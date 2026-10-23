<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KCheck from '@/components/k/KCheck.vue';
import KDate from '@/components/k/KDate.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KMoney from '@/components/k/KMoney.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { money, niceDate, today } from '@/lib/format';
import { t } from '@/lib/i18n';
import { quickJob } from '@/lib/quick-entry';
import type { Option } from '@/types';

const open = defineModel<boolean>({ default: false });
const page = usePage();
const can = computed(() => page.props.auth.can ?? {});

type Lookups = {
    clients: Option[];
    accounts: Option[];
    heads: Option[];
    wallets: Option[];
    pending: {
        id: number;
        client_id: number;
        date: string | null;
        description: string;
        amount: number;
    }[];
};

const lookups = computed(() => page.props.lookups as Lookups | undefined);
const loading = ref(false);

// The jobs, in the words the old sheet used ("কোথায় কী লিখব"). Two of them
// mostly fill themselves (auto): Pilot campaigns and paid invoices.
const JOBS = [
    {
        key: 'client-payment',
        label: 'Client টাকা দিল',
        note: 'Invoice-এর টাকা নিজে বসে — cash বা অন্য পথে এলে এখানে',
        icon: 'taka',
        perm: 'bdt.create',
        auto: true,
    }, // i18n
    {
        key: 'card-charge',
        label: 'Card থেকে কেটেছে',
        note: 'Meta/Google card charge',
        icon: 'wallet',
        perm: 'usd.create',
    }, // i18n
    {
        key: 'buy-usd',
        label: 'USD কিনলাম',
        note: 'USD +, BDT −, নতুন FX batch',
        icon: 'swap',
        perm: 'fx.create',
    }, // i18n
    {
        key: 'expense',
        label: 'ব্যবসার খরচ (BDT)',
        note: 'Domain, যাতায়াত, অফিস…',
        icon: 'bin',
        perm: 'bdt.create',
    }, // i18n
    {
        key: 'own-spend',
        label: 'নিজের USD খরচ',
        note: 'KlokAds-এর নিজের ad / research',
        icon: 'dollar',
        perm: 'usd.create',
    }, // i18n
    {
        key: 'transfer',
        label: 'USD এক account থেকে আরেকটায়',
        note: 'Card ↔ Other account',
        icon: 'arrow',
        perm: 'usd.create',
    }, // i18n
    {
        key: 'owner',
        label: 'Owner টাকা দিল / তুলল',
        note: 'নিল / ফেরত দিল · Capital · Drawing',
        icon: 'user',
        perm: 'bdt.create',
    }, // i18n
    {
        key: 'ad-spend',
        label: 'Pilot-এর বাইরের ad খরচ',
        note: 'Pilot-এর campaign-এর খরচ নিজে আসে — শুধু বাকিগুলো',
        icon: 'signal',
        perm: 'usd.create',
        auto: true,
    }, // i18n
] as const;

type JobKey = (typeof JOBS)[number]['key'];

const jobs = computed(() => JOBS.filter((j) => can.value[j.perm]));
const jobOptions = computed<Option[]>(() =>
    jobs.value.map((j) => ({
        value: j.key,
        label: t(j.label),
        note: t(j.note),
        icon: j.icon,
    })),
);
const job = ref<JobKey>('client-payment');

const blank = () => ({
    entry_date: today() as string | null,
    client_id: null as number | null,
    account_id: null as number | null,
    from_id: null as number | null,
    to_id: null as number | null,
    expense_head_id: null as number | null,
    amount: '' as string | null,
    usd: '' as string | null,
    bdt: '' as string | null,
    status: 'pending',
    category: 'client_payment',
    pay_via: 'mfs',
    usd_credit: '' as string | null,
    bank_fee: false,
    wallet: 'bank' as string | null,
    direction: 'loan',
    description: '' as string | null,
    notes: '' as string | null,
    settle_ids: [] as number[],
    run_days: '' as string | null,
});

const form = useForm(blank());

watch(open, (value) => {
    if (value) {
        // A page asked for one job (e.g. the Payment page's "new payment").
        if (
            quickJob.value &&
            jobs.value.some((j) => j.key === quickJob.value)
        ) {
            job.value = quickJob.value as JobKey;
        }
        quickJob.value = null;

        if (!jobs.value.some((j) => j.key === job.value)) {
            job.value = jobs.value[0]?.key ?? 'ad-spend';
        }

        loading.value = true;
        router.reload({
            only: ['lookups'],
            onFinish: () => (loading.value = false),
        });
    }
});

watch(job, () => {
    const date = form.entry_date;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    form.entry_date = date;
    applyDefaults();
});

const accounts = computed(() => lookups.value?.accounts ?? []);
const platforms = computed(() =>
    accounts.value.filter((a) => a.kind === 'platform'),
);
const ownAccounts = computed(() =>
    accounts.value.filter((a) => a.kind !== 'platform'),
);
const spendAccounts = computed(() =>
    accounts.value.filter((a) => a.kind !== 'holding'),
);

function applyDefaults(): void {
    const meta = platforms.value.find((a) => a.code === 'meta');

    if (job.value === 'ad-spend' || job.value === 'card-charge') {
        form.account_id = (meta?.value as number) ?? null;
    }

    if (job.value === 'own-spend') {
        form.account_id = (meta?.value as number) ?? null;
    }

    if (job.value === 'transfer') {
        form.from_id =
            (ownAccounts.value.find((a) => a.kind === 'card')
                ?.value as number) ?? null;
        form.to_id =
            (ownAccounts.value.find((a) => a.kind === 'holding')
                ?.value as number) ?? null;
    }
}

watch(lookups, applyDefaults);

const clientPending = computed(() =>
    (lookups.value?.pending ?? []).filter(
        (p) => p.client_id === form.client_id,
    ),
);

watch(
    () => form.client_id,
    () => (form.settle_ids = []),
);

function toggleSettle(id: number, on: boolean): void {
    form.settle_ids = on
        ? [...form.settle_ids, id]
        : form.settle_ids.filter((x) => x !== id);
}

const settleTotal = computed(() =>
    clientPending.value
        .filter((p) => form.settle_ids.includes(p.id))
        .reduce((sum, p) => sum + p.amount, 0),
);

const rate = computed(() => {
    const usd = parseFloat(form.usd || '0');
    const bdt = parseFloat(form.bdt || '0');

    return usd > 0 && bdt > 0 ? (bdt / usd).toFixed(2) : null;
});

// An advance payment buys this much ad credit; the rate is shown as typed.
const creditRate = computed(() => {
    const usd = parseFloat(form.usd_credit || '0');
    const bdt = parseFloat(form.amount || '0');

    return usd > 0 && bdt > 0 ? (bdt / usd).toFixed(2) : null;
});

const effectiveRate = computed(() => {
    const bdt = parseFloat(form.amount || '0');

    return settleTotal.value > 0 && bdt > 0
        ? (bdt / (settleTotal.value / 100)).toFixed(2)
        : null;
});

const current = computed(() => JOBS.find((j) => j.key === job.value)!);

function submit(): void {
    form.post(`/quick/${job.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            const date = form.entry_date;
            form.reset();
            form.entry_date = date;
        },
    });
}

const yesNo = computed<Option[]>(() => [
    { value: 'pending', label: t('Pending — টাকা পাইনি') },
    { value: 'settled', label: t('Settled — টাকা পেয়েছি') },
]);
const walletOptions = computed<Option[]>(() => lookups.value?.wallets ?? []);
// A payment's place follows how it came: MFS lands in bKash, bank in the bank.
watch(
    () => form.pay_via,
    (via) => {
        if (job.value === 'client-payment') {
            form.wallet =
                via === 'mfs' ? 'bkash' : via === 'cash' ? 'cash' : 'bank';
        }
    },
);
watch(
    () => job.value,
    (value) => {
        form.wallet =
            value === 'client-payment' && form.pay_via === 'mfs'
                ? 'bkash'
                : 'bank';
    },
);
const payVia = computed<Option[]>(() => [
    { value: 'mfs', label: 'MFS (bKash / Nagad)' },
    { value: 'qr', label: 'Bangla QR' },
    { value: 'bank', label: 'Bank' },
    { value: 'cash', label: 'Cash' },
]);
const incomeKinds = computed<Option[]>(() => [
    { value: 'client_payment', label: t('Ad-এর টাকা') },
    { value: 'service_income', label: 'Service fee' },
]);
const directions = computed<Option[]>(() => [
    { value: 'loan', label: t('নিলাম — ফেরত দেবো') },
    { value: 'repay', label: t('ফেরত দিলাম') },
    { value: 'in', label: t('টাকা দিলাম (Capital)') },
    { value: 'out', label: t('একেবারে তুললাম (Drawing)') },
]);
</script>

<template>
    <KModal v-model="open" width="xl">
        <template #header>
            <p class="eyebrow">{{ t('নতুন entry') }}</p>
            <h2 class="mt-0.5 text-[18px] font-bold tracking-tight">
                {{ t('কী হলো?') }}
            </h2>
        </template>

        <div
            class="grid grid-cols-[minmax(0,1fr)] gap-5 md:grid-cols-[250px_minmax(0,1fr)] md:gap-6"
        >
            <!-- What happened: a dropdown on small screens, a list beside the form on wide ones -->
            <KSelect
                :model-value="job"
                :options="jobOptions"
                @update:model-value="(v) => (job = v as JobKey)"
                :searchable="false"
                class="md:hidden"
            />
            <div class="hidden flex-col gap-1.5 md:flex">
                <button
                    v-for="j in jobs"
                    :key="j.key"
                    type="button"
                    class="flex items-start gap-3 rounded-xl border px-3 py-2.5 text-left transition-colors"
                    :class="
                        job === j.key
                            ? 'border-brand bg-brand-soft'
                            : 'border-line hover:border-line-2 hover:bg-surface-2'
                    "
                    @click="job = j.key"
                >
                    <KIcon
                        :name="j.icon"
                        :size="18"
                        :class="
                            job === j.key
                                ? 'mt-0.5 text-brand-ink'
                                : 'mt-0.5 text-ink-3'
                        "
                    />
                    <span class="min-w-0">
                        <span
                            class="flex items-center gap-1.5 text-[13.5px] font-semibold"
                            :class="job === j.key && 'text-brand-ink'"
                            >{{ t(j.label)
                            }}<span
                                v-if="'auto' in j"
                                class="rounded bg-in-soft px-1.5 text-[10.5px] font-semibold text-in"
                                >Auto</span
                            ></span
                        >
                        <span class="block text-[11.5px] text-ink-3">{{
                            t(j.note)
                        }}</span>
                    </span>
                </button>
            </div>

            <!-- The form for it -->
            <form class="flex min-w-0 flex-col gap-4" @submit.prevent="submit">
                <div
                    class="hidden rounded-xl border border-dashed border-line-2 bg-surface-2 px-4 py-3 text-[13px] text-ink-2 md:block"
                >
                    <span class="font-semibold text-ink">{{
                        t(current.label)
                    }}</span>
                    — {{ t(current.note) }}
                    <span v-if="loading" class="ml-2 text-ink-3">{{
                        t('লোড হচ্ছে…')
                    }}</span>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <KField :label="t('তারিখ')" :error="form.errors.entry_date">
                        <KDate
                            v-model="form.entry_date"
                            :max="today()"
                            :invalid="!!form.errors.entry_date"
                        />
                    </KField>

                    <!-- Ad spend -->
                    <template v-if="job === 'ad-spend'">
                        <KField label="Client" :error="form.errors.client_id">
                            <KSelect
                                v-model="form.client_id"
                                :options="lookups?.clients ?? []"
                                :placeholder="t('কোন client?')"
                                :invalid="!!form.errors.client_id"
                            />
                        </KField>
                        <KField
                            :label="t('কোথায় চলেছে')"
                            :error="form.errors.account_id"
                        >
                            <KSelect
                                v-model="form.account_id"
                                :options="spendAccounts"
                                :invalid="!!form.errors.account_id"
                            />
                        </KField>
                        <KField
                            :label="t('খরচ (USD)')"
                            :error="form.errors.amount"
                        >
                            <KMoney
                                v-model="form.amount"
                                currency="usd"
                                :invalid="!!form.errors.amount"
                            />
                        </KField>
                        <KField
                            :label="t('Client টাকা দিয়েছে?')"
                            class="sm:col-span-2"
                        >
                            <KSegment v-model="form.status" :options="yesNo" />
                        </KField>
                    </template>

                    <!-- Client payment -->
                    <template v-if="job === 'client-payment'">
                        <KField label="Client" :error="form.errors.client_id">
                            <KSelect
                                v-model="form.client_id"
                                :options="lookups?.clients ?? []"
                                :placeholder="t('কে দিল?')"
                                :invalid="!!form.errors.client_id"
                            />
                        </KField>
                        <KField
                            :label="t('পেলাম (BDT)')"
                            :error="form.errors.amount"
                        >
                            <KMoney
                                v-model="form.amount"
                                currency="bdt"
                                :invalid="!!form.errors.amount"
                            />
                        </KField>
                        <KField
                            v-if="form.category === 'client_payment'"
                            :label="t('কত ডলারের ad-এর জন্য (USD)')"
                            optional
                            :error="form.errors.usd_credit"
                            :hint="
                                creditRate
                                    ? t(
                                          'Rate ৳{rate} / USD — এই credit থেকে campaign-এর খরচ কাটা যাবে।',
                                          {
                                              rate: creditRate,
                                          },
                                      )
                                    : t(
                                          'আগাম দিলে লিখুন — campaign-এর খরচ এই credit থেকে কাটা যাবে।',
                                      )
                            "
                        >
                            <KMoney
                                v-model="form.usd_credit"
                                currency="usd"
                                :invalid="!!form.errors.usd_credit"
                            />
                        </KField>
                        <KField :label="t('কীসের টাকা')">
                            <KSegment
                                v-model="form.category"
                                :options="incomeKinds"
                            />
                        </KField>
                        <KField
                            :label="t('কীভাবে এলো')"
                            :hint="
                                form.pay_via === 'mfs'
                                    ? t(
                                          'MFS-এ এলে fee কাটা হবে (Settings-এর হারে)।',
                                      )
                                    : t('Bank বা cash-এ কোনো fee নেই।')
                            "
                            :error="form.errors.pay_via"
                        >
                            <KSegment
                                v-model="form.pay_via"
                                :options="payVia"
                            />
                        </KField>
                        <KField
                            :label="t('কোথায় এলো')"
                            :error="form.errors.wallet"
                        >
                            <KSegment
                                v-model="form.wallet"
                                :options="walletOptions"
                            />
                        </KField>
                        <div
                            v-if="
                                form.client_id &&
                                form.category === 'client_payment'
                            "
                            class="sm:col-span-2"
                        >
                            <p
                                class="mb-2 text-[13px] font-semibold text-ink-2"
                            >
                                {{
                                    t('কোন Pending খরচগুলো এই টাকায় শোধ হলো?')
                                }}
                            </p>
                            <div
                                v-if="clientPending.length"
                                class="divide-y divide-dashed divide-line rounded-xl border border-line"
                            >
                                <div
                                    v-for="p in clientPending"
                                    :key="p.id"
                                    class="flex items-center gap-3 px-3.5 py-2.5"
                                >
                                    <KCheck
                                        :model-value="
                                            form.settle_ids.includes(p.id)
                                        "
                                        @update:model-value="
                                            (v: boolean) =>
                                                toggleSettle(p.id, v)
                                        "
                                    >
                                        <span class="text-[13.5px]">{{
                                            p.description
                                        }}</span>
                                        <span
                                            class="ml-2 text-[12px] text-ink-3"
                                            >{{ niceDate(p.date) }}</span
                                        >
                                    </KCheck>
                                    <span
                                        class="num ml-auto text-[13.5px] font-medium"
                                        >{{ money(p.amount, 'usd') }}</span
                                    >
                                </div>
                            </div>
                            <p
                                v-else
                                class="rounded-xl bg-sunken px-3.5 py-3 text-[13px] text-ink-3"
                            >
                                {{ t('এই client-এর কোনো Pending খরচ নেই।') }}
                            </p>
                            <p
                                v-if="effectiveRate"
                                class="mt-2 text-[12.5px] text-ink-2"
                            >
                                {{ t('বাছাই করা') }}
                                <span class="num font-semibold">{{
                                    money(settleTotal, 'usd')
                                }}</span>
                                — {{ t('client-এর rate দাঁড়াল') }}
                                <span class="num font-semibold text-brand-ink"
                                    >&#2547;{{ effectiveRate }}</span
                                >
                                / USD
                            </p>
                        </div>
                    </template>

                    <!-- Card charge -->
                    <template v-if="job === 'card-charge'">
                        <KField
                            :label="t('কে কেটেছে')"
                            :error="form.errors.account_id"
                        >
                            <KSelect
                                v-model="form.account_id"
                                :options="platforms"
                                :invalid="!!form.errors.account_id"
                            />
                        </KField>
                        <KField
                            :label="t('কত কেটেছে (USD)')"
                            :error="form.errors.amount"
                        >
                            <KMoney
                                v-model="form.amount"
                                currency="usd"
                                :invalid="!!form.errors.amount"
                            />
                        </KField>
                        <KField
                            :label="t('Card statement-এর লেখা')"
                            optional
                            :hint="t('যেমন FACEBK* S5LHCASSD4')"
                        >
                            <KInput
                                v-model="form.description"
                                placeholder="FACEBK* …"
                            />
                        </KField>
                    </template>

                    <!-- Buy USD -->
                    <template v-if="job === 'buy-usd'">
                        <KField
                            :label="t('পেলাম (USD)')"
                            :error="form.errors.usd"
                        >
                            <KMoney
                                v-model="form.usd"
                                currency="usd"
                                :invalid="!!form.errors.usd"
                            />
                        </KField>
                        <KField
                            :label="t('দিলাম (BDT)')"
                            :error="form.errors.bdt"
                        >
                            <KMoney
                                v-model="form.bdt"
                                currency="bdt"
                                :invalid="!!form.errors.bdt"
                            />
                        </KField>
                        <KField
                            :label="t('কোথা থেকে দিলাম')"
                            :error="form.errors.wallet"
                        >
                            <KSegment
                                v-model="form.wallet"
                                :options="walletOptions"
                            />
                        </KField>
                        <KField
                            :label="t('নাম')"
                            optional
                            :hint="t('না দিলে Deposit 4, 5… নিজে বসবে')"
                        >
                            <KInput
                                v-model="form.description"
                                placeholder="Deposit"
                            />
                        </KField>
                        <div
                            v-if="rate"
                            class="flex items-center rounded-xl bg-brand-soft px-4 py-3 text-[13.5px] text-brand-ink sm:col-span-2"
                        >
                            {{ t('এই batch-এর rate:') }}
                            <span class="num ml-1.5 text-[16px] font-semibold"
                                >&#2547;{{ rate }}</span
                            >&nbsp;/ USD
                        </div>
                    </template>

                    <!-- Expense -->
                    <template v-if="job === 'expense'">
                        <KField
                            :label="t('খরচের খাত')"
                            :error="form.errors.expense_head_id"
                        >
                            <KSelect
                                v-model="form.expense_head_id"
                                :options="lookups?.heads ?? []"
                                :invalid="!!form.errors.expense_head_id"
                            />
                        </KField>
                        <KField
                            :label="t('কত (BDT)')"
                            :error="form.errors.amount"
                        >
                            <KMoney
                                v-model="form.amount"
                                currency="bdt"
                                :invalid="!!form.errors.amount"
                            />
                        </KField>
                        <KField
                            :label="t('কোথা থেকে দিলাম')"
                            :error="form.errors.wallet"
                        >
                            <KSegment
                                v-model="form.wallet"
                                :options="walletOptions"
                            />
                        </KField>
                        <KField
                            :label="t('কীসের খরচ')"
                            :error="form.errors.description"
                        >
                            <KInput
                                v-model="form.description"
                                :placeholder="t('যেমন Domain renew')"
                                :invalid="!!form.errors.description"
                            />
                        </KField>
                    </template>

                    <!-- Own USD spend -->
                    <template v-if="job === 'own-spend'">
                        <KField
                            :label="t('কোথায়')"
                            :error="form.errors.account_id"
                        >
                            <KSelect
                                v-model="form.account_id"
                                :options="spendAccounts"
                                :invalid="!!form.errors.account_id"
                            />
                        </KField>
                        <KField
                            :label="t('খরচ (USD)')"
                            :error="form.errors.amount"
                        >
                            <KMoney
                                v-model="form.amount"
                                currency="usd"
                                :invalid="!!form.errors.amount"
                            />
                        </KField>
                        <KField
                            :label="t('কীসের খরচ')"
                            :error="form.errors.description"
                        >
                            <KInput
                                v-model="form.description"
                                :placeholder="t('যেমন KlokAds - own ad spend')"
                                :invalid="!!form.errors.description"
                            />
                        </KField>
                    </template>

                    <!-- Transfer -->
                    <template v-if="job === 'transfer'">
                        <KField
                            :label="t('কোথা থেকে')"
                            :error="form.errors.from_id"
                        >
                            <KSelect
                                v-model="form.from_id"
                                :options="ownAccounts"
                                :invalid="!!form.errors.from_id"
                            />
                        </KField>
                        <KField :label="t('কোথায়')" :error="form.errors.to_id">
                            <KSelect
                                v-model="form.to_id"
                                :options="ownAccounts"
                                :invalid="!!form.errors.to_id"
                            />
                        </KField>
                        <KField
                            :label="t('কত (USD)')"
                            :error="form.errors.amount"
                        >
                            <KMoney
                                v-model="form.amount"
                                currency="usd"
                                :invalid="!!form.errors.amount"
                            />
                        </KField>
                    </template>

                    <!-- Owner -->
                    <template v-if="job === 'owner'">
                        <KField :label="t('কী হলো')" class="sm:col-span-2">
                            <KSegment
                                v-model="form.direction"
                                :options="directions"
                            />
                        </KField>
                        <KField
                            :label="t('কোন জায়গায়')"
                            :error="form.errors.wallet"
                        >
                            <KSegment
                                v-model="form.wallet"
                                :options="walletOptions"
                            />
                        </KField>
                        <KField
                            :label="t('কত (BDT)')"
                            :error="form.errors.amount"
                        >
                            <KMoney
                                v-model="form.amount"
                                currency="bdt"
                                :invalid="!!form.errors.amount"
                            />
                        </KField>
                    </template>
                </div>

                <KSwitch
                    v-if="
                        form.wallet === 'bank' &&
                        (job === 'expense' ||
                            (job === 'owner' &&
                                ['out', 'loan'].includes(form.direction)))
                    "
                    v-model="form.bank_fee"
                    :label="t('Bank transfer (NPSB/অন্য bank-এ পাঠানো)')"
                    :hint="
                        t(
                            'চালু করলে transfer fee আলাদা Bank charge খরচ হিসেবে লেখা হবে।',
                        )
                    "
                />

                <KField label="Note" optional>
                    <KTextarea
                        v-model="form.notes"
                        :placeholder="t('দরকারি কিছু থাকলে লিখুন')"
                    />
                </KField>

                <div
                    class="flex flex-wrap items-center justify-end gap-2 border-t border-line pt-4"
                >
                    <KButton variant="quiet" @click="open = false">{{
                        t('বাতিল')
                    }}</KButton>
                    <KButton
                        type="submit"
                        variant="primary"
                        icon="check"
                        :loading="form.processing"
                        >{{ t('খাতায় তুলুন') }}</KButton
                    >
                </div>
            </form>
        </div>
    </KModal>
</template>
