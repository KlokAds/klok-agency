<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KCheck from '@/components/k/KCheck.vue';
import KDate from '@/components/k/KDate.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KMoney from '@/components/k/KMoney.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { useFilters } from '@/composables/useFilters';
import { confirmAction } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import { money, niceDate, toInput, today } from '@/lib/format';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    date: string | null;
    description: string;
    category: string;
    category_label: string;
    client: string | null;
    client_id: number | null;
    pay_via: string | null;
    usd_credit: number | null;
    wallet: string | null;
    head: string | null;
    expense_head_id: number | null;
    employee: string | null;
    amount: number;
    notes: string | null;
    review_note: string | null;
    balance: number | null;
    locked: boolean;
};

const props = defineProps<{
    entries: Paginated<Row>;
    filters: Record<string, string | null>;
    totals: { in: number; out: number; count: number };
    summary: { balance: number };
    options: {
        categories: Option[];
        clients: Option[];
        heads: Option[];
        wallets: Option[];
    };
}>();

const can = computed(() => usePage().props.auth.can);

const { filters, clear } = useFilters('/bdt', {
    q: props.filters.q ?? null,
    category: props.filters.category ?? null,
    client_id: props.filters.client_id ?? null,
    expense_head_id: props.filters.expense_head_id ?? null,
    from: props.filters.from ?? null,
    to: props.filters.to ?? null,
    order: props.filters.order ?? 'new',
    wallet: props.filters.wallet ?? null,
});

const filtered = computed(() =>
    Object.entries(filters).some(([k, v]) => k !== 'order' && v),
);
const orderOptions = computed<Option[]>(() => [
    { value: 'new', label: t('নতুন আগে') },
    { value: 'old', label: t('পুরনো আগে') },
]);

const editableCategories = computed(() =>
    props.options.categories.filter((c) => c.value !== 'usd_purchase'),
);

const tone = (category: string) =>
    ({
        capital: 'brand',
        owner_drawing: 'wait',
        owner_loan: 'wait',
        owner_repay: 'in',
        usd_purchase: 'neutral',
        client_payment: 'in',
        service_income: 'in',
        other_income: 'in',
        expense: 'out',
        salary: 'out',
    })[category] as 'brand' | 'neutral' | 'in' | 'out' | 'wait';

const editing = ref<Row | null>(null);
const open = ref(false);
const form = useForm({
    entry_date: today() as string | null,
    description: '',
    category: 'expense',
    client_id: null as number | null,
    pay_via: 'mfs' as string | null,
    wallet: null as string | null,
    expense_head_id: null as number | null,
    amount: '' as string | null,
    usd_credit: '' as string | null,
    notes: '' as string | null,
    clear_review: false,
});

function startNew(): void {
    editing.value = null;
    form.defaults({
        entry_date: today(),
        description: '',
        category: 'expense',
        client_id: null,
        pay_via: 'mfs',
        wallet: null,
        expense_head_id: null,
        amount: '',
        usd_credit: '',
        notes: '',
        clear_review: false,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function startEdit(row: Row): void {
    editing.value = row;
    form.clearErrors();
    Object.assign(form, {
        entry_date: row.date,
        description: row.description,
        category: row.category,
        client_id: row.client_id,
        pay_via: row.pay_via ?? 'mfs',
        wallet: row.wallet,
        expense_head_id: row.expense_head_id,
        amount: toInput(row.amount),
        usd_credit: toInput(row.usd_credit),
        notes: row.notes ?? '',
        clear_review: false,
    });
    open.value = true;
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };
    if (editing.value) {
        form.put(`/bdt/${editing.value.id}`, options);
    } else {
        form.post('/bdt', options);
    }
}

async function remove(row: Row): Promise<void> {
    const ok = await confirmAction({
        title: t('Entry মুছবেন?'),
        body: t(
            '"{description}" ({amount}) Trash-এ যাবে। এই টাকায় যে খরচগুলো Settled হয়েছিল, সেগুলো আবার Pending হবে।',
            { description: row.description, amount: money(row.amount, 'bdt') },
        ),
        confirm: t('Trash-এ পাঠান'),
        danger: true,
    });

    if (ok) {
        router.delete(`/bdt/${row.id}`, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });
    }
}

const payVia = computed<Option[]>(() => [
    { value: 'mfs', label: 'MFS (bKash / Nagad)' },
    { value: 'qr', label: 'Bangla QR' },
    { value: 'bank', label: 'Bank' },
    { value: 'cash', label: 'Cash' },
]);

const needsClient = computed(() =>
    ['client_payment', 'service_income'].includes(form.category),
);
</script>

<template>
    <SectionTabs section="ledgers" />
    <PageHeader
        :eyebrow="t('খাতা')"
        :title="t('BDT খাতা')"
        :lead="
            t(
                'সব BDT লেনদেন এক জায়গায় — মিলিয়ে দেখা আর ভুল ঠিক করার জন্য। নতুন টাকা Payment, খরচ বা “নতুন entry” থেকে লিখুন।',
            )
        "
    >
    </PageHeader>

    <!-- Phone: the search on its own line, the four dropdowns two a row, then the dates and the order -->
    <section
        class="panel no-print mb-4 grid grid-cols-2 gap-2 p-3 sm:gap-3 lg:grid-cols-[1.4fr_repeat(4,1fr)] lg:p-4"
    >
        <KInput
            v-model="filters.q"
            type="search"
            icon="search"
            :placeholder="t('খুঁজুন — বিবরণ, note, client')"
            size="sm"
            class="col-span-2 lg:col-span-1"
        />
        <KSelect
            v-model="filters.category"
            :options="options.categories"
            :placeholder="t('সব ধরন')"
            clearable
            size="sm"
        />
        <KSelect
            v-model="filters.client_id"
            :options="options.clients"
            :placeholder="t('সব client')"
            clearable
            size="sm"
        />
        <KSelect
            v-model="filters.expense_head_id"
            :options="options.heads"
            :placeholder="t('সব খরচের খাত')"
            clearable
            size="sm"
        />
        <KSelect
            v-model="filters.wallet"
            :options="options.wallets"
            :placeholder="t('সব জায়গা')"
            clearable
            size="sm"
        />
        <div class="col-span-2 flex items-center gap-2">
            <KDate
                v-model="filters.from"
                :placeholder="t('শুরু')"
                clearable
                size="sm"
                class="min-w-0 flex-1"
            />
            <span class="text-ink-3">—</span>
            <KDate
                v-model="filters.to"
                :placeholder="t('শেষ')"
                clearable
                size="sm"
                class="min-w-0 flex-1"
            />
        </div>
        <div
            class="col-span-2 flex items-center justify-between gap-3 lg:col-span-3 lg:justify-end"
        >
            <KSegment
                v-model="filters.order"
                :options="orderOptions"
                size="sm"
            />
            <KButton v-if="filtered" size="sm" icon="close" @click="clear">{{
                t('Filter মুছুন')
            }}</KButton>
        </div>
    </section>

    <div class="mb-4 flex flex-wrap gap-x-8 gap-y-2 px-1 text-[14px]">
        <span class="text-ink-3"
            ><b class="num text-ink">{{ totals.count }}</b> row</span
        >
        <span class="text-ink-3"
            >{{ t('ঢুকেছে') }}
            <b class="num text-in">{{ money(totals.in, 'bdt') }}</b></span
        >
        <span class="text-ink-3"
            >{{ t('বেরিয়েছে') }}
            <b class="num text-out">{{ money(totals.out, 'bdt') }}</b></span
        >
        <span class="ml-auto text-ink-3"
            >Balance
            <b class="num text-ink">{{
                money(summary.balance, 'bdt')
            }}</b></span
        >
    </div>

    <section class="panel overflow-hidden">
        <KEmpty
            v-if="!entries.data.length"
            :title="t('কোনো row নেই')"
            :body="
                filtered
                    ? t('এই filter-এ কিছু মেলেনি।')
                    : t('প্রথম entry দিয়ে শুরু করুন।')
            "
        />

        <template v-else>
            <div class="hidden overflow-x-auto lg:block">
                <table v-stack class="stack ledger">
                    <thead>
                        <tr>
                            <th class="w-[92px]">{{ t('তারিখ') }}</th>
                            <th>{{ t('বিবরণ') }}</th>
                            <th>{{ t('কার / কীসের') }}</th>
                            <th class="r">{{ t('পরিমাণ') }}</th>
                            <th class="r">Balance</th>
                            <th class="w-[76px]" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in entries.data"
                            :key="row.id"
                            class="group"
                        >
                            <td
                                class="num text-[13.5px] whitespace-nowrap text-ink-2"
                            >
                                {{
                                    row.date
                                        ? niceDate(row.date)
                                        : t('তারিখ নেই')
                                }}
                            </td>
                            <td class="min-w-[280px]">
                                <p class="font-medium">{{ row.description }}</p>
                                <p
                                    class="mt-1 flex flex-wrap items-center gap-1.5"
                                >
                                    <KTag :tone="tone(row.category)">{{
                                        row.category_label
                                    }}</KTag>
                                    <KTag
                                        v-if="row.review_note"
                                        tone="wait"
                                        :title="row.review_note"
                                        >{{ t('মিলিয়ে দেখুন') }} —
                                        {{ row.review_note }}</KTag
                                    >
                                    <span
                                        v-if="row.notes"
                                        class="text-[13px] text-ink-3"
                                        >{{ row.notes }}</span
                                    >
                                </p>
                            </td>
                            <td
                                class="text-[14px] whitespace-nowrap text-ink-2"
                            >
                                {{
                                    row.client ?? row.employee ?? row.head ?? ''
                                }}
                                <span
                                    v-if="row.wallet"
                                    class="ml-1.5 rounded-md bg-sunken px-1.5 py-0.5 text-[13px] text-ink-3"
                                    >{{
                                        options.wallets.find(
                                            (w) => w.value === row.wallet,
                                        )?.label
                                    }}</span
                                >
                            </td>
                            <td class="r">
                                <KAmount
                                    :value="row.amount"
                                    currency="bdt"
                                    sign
                                    class="font-medium"
                                />
                            </td>
                            <td class="r num text-ink-2">
                                {{ money(row.balance, 'bdt') }}
                            </td>
                            <td class="r whitespace-nowrap">
                                <span
                                    v-if="row.locked"
                                    class="inline-flex text-ink-3"
                                    :title="
                                        row.category === 'salary'
                                            ? t('Payroll থেকে বদলান')
                                            : t('FX Batch থেকে বদলান')
                                    "
                                    ><KIcon name="lock" :size="16"
                                /></span>
                                <template v-else>
                                    <button
                                        v-if="can['bdt.edit']"
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1 rounded-md border border-brand/30 bg-brand-soft px-2 text-[13px] font-semibold text-brand-ink transition-colors hover:border-brand"
                                        @click="startEdit(row)"
                                    >
                                        <KIcon name="pen" :size="15" />{{
                                            t('বদলান')
                                        }}
                                    </button>
                                    <button
                                        v-if="can['bdt.delete']"
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1 rounded-md border border-out/30 bg-out-soft px-2 text-[13px] font-semibold text-out transition-colors hover:border-out"
                                        @click="remove(row)"
                                    >
                                        <KIcon name="bin" :size="15" />{{
                                            t('মুছুন')
                                        }}
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul class="divide-y divide-dashed divide-line lg:hidden">
                <li v-for="row in entries.data" :key="row.id">
                    <button
                        type="button"
                        class="flex w-full items-start gap-3 px-4 py-3.5 text-left"
                        :disabled="row.locked || !can['bdt.edit']"
                        @click="startEdit(row)"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{
                                row.description
                            }}</span>
                            <span
                                class="mt-1 flex flex-wrap items-center gap-1.5 text-[13px] text-ink-3"
                            >
                                <span class="num">{{
                                    row.date
                                        ? niceDate(row.date)
                                        : t('তারিখ নেই')
                                }}</span>
                                <KTag :tone="tone(row.category)">{{
                                    row.category_label
                                }}</KTag>
                                <KIcon
                                    v-if="row.locked"
                                    name="lock"
                                    :size="13"
                                    :title="
                                        row.category === 'salary'
                                            ? t('Payroll থেকে বদলান')
                                            : t('FX Batch থেকে বদলান')
                                    "
                                />
                            </span>
                        </span>
                        <span class="shrink-0 text-right">
                            <KAmount
                                :value="row.amount"
                                currency="bdt"
                                sign
                                class="block font-semibold"
                            />
                            <span class="num text-[13px] text-ink-3">{{
                                money(row.balance, 'bdt')
                            }}</span>
                        </span>
                    </button>
                </li>
            </ul>

            <KPager :page="entries" :sizes="[10, 25, 50, 100]" />
        </template>
    </section>

    <KModal
        v-model="open"
        :title="editing ? t('BDT row বদলান') : t('নতুন BDT row')"
        :subtitle="t('USD কেনা FX Batch থেকে, বেতন Payroll থেকে দিন।')"
    >
        <form
            id="bdt-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField :label="t('তারিখ')" :error="form.errors.entry_date">
                <KDate
                    v-model="form.entry_date"
                    :invalid="!!form.errors.entry_date"
                />
            </KField>
            <KField :label="t('ধরন')" :error="form.errors.category">
                <KSelect
                    v-model="form.category"
                    :options="editableCategories"
                />
            </KField>
            <KField
                :label="t('বিবরণ')"
                :error="form.errors.description"
                class="sm:col-span-2"
            >
                <KInput
                    v-model="form.description"
                    :invalid="!!form.errors.description"
                />
            </KField>
            <KField
                v-if="needsClient"
                label="Client"
                :error="form.errors.client_id"
            >
                <KSelect
                    v-model="form.client_id"
                    :options="options.clients"
                    :invalid="!!form.errors.client_id"
                />
            </KField>
            <KField
                v-if="needsClient"
                :label="t('কীভাবে এলো')"
                :hint="
                    form.pay_via === 'mfs'
                        ? t('MFS-এ এলে fee কাটা হবে (Settings-এর হারে)।')
                        : t('Bank বা cash-এ কোনো fee নেই।')
                "
                :error="form.errors.pay_via"
            >
                <KSegment v-model="form.pay_via" :options="payVia" />
            </KField>
            <KField
                v-if="form.category === 'client_payment'"
                :label="t('এই টাকায় কত dollar-এর ad (আগাম)')"
                optional
                :error="form.errors.usd_credit"
                :hint="
                    t(
                        'দিলে এটা client-এর আগাম টাকা: campaign যত খরচ করবে তত কাটা যাবে, বাকিটা credit থাকবে। খালি রাখলে টাকা পাওয়ার দিনেই আয়।',
                    )
                "
            >
                <KMoney v-model="form.usd_credit" currency="usd" />
            </KField>
            <KField
                :label="t('কোন জায়গায়')"
                optional
                :error="form.errors.wallet"
            >
                <KSegment v-model="form.wallet" :options="options.wallets" />
            </KField>
            <KField
                v-if="form.category === 'expense'"
                :label="t('খরচের খাত')"
                :error="form.errors.expense_head_id"
            >
                <KSelect
                    v-model="form.expense_head_id"
                    :options="options.heads"
                    clearable
                />
            </KField>
            <KField
                :label="t('পরিমাণ')"
                :error="form.errors.amount"
                :hint="t('শুধু সংখ্যা — যোগ না বিয়োগ, তা ধরন থেকে বোঝা যায়')"
            >
                <KMoney
                    v-model="form.amount"
                    currency="bdt"
                    :invalid="!!form.errors.amount"
                />
            </KField>
            <KField label="Note" optional class="sm:col-span-2">
                <KTextarea v-model="form.notes" />
            </KField>
            <div
                v-if="editing?.review_note"
                class="rounded-xl bg-wait-soft px-4 py-3 sm:col-span-2"
            >
                <p class="text-[14px] font-medium text-wait">
                    {{
                        t('পুরনো sheet থেকে: {note}', {
                            note: editing.review_note,
                        })
                    }}
                </p>
                <KCheck
                    v-model="form.clear_review"
                    :label="t('দেখেছি, ঠিক আছে — চিহ্ন সরিয়ে দাও')"
                    class="mt-2"
                />
            </div>
        </form>
        <template #footer>
            <KButton
                v-if="editing && can['bdt.delete']"
                variant="danger"
                icon="bin"
                class="mr-auto"
                @click="remove(editing)"
                >{{ t('মুছুন') }}</KButton
            >
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="bdt-form"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>
</template>
