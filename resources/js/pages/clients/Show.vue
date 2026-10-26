<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import TaskForm from '@/components/TaskForm.vue';
import KSelect from '@/components/k/KSelect.vue';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { confirmAction } from '@/lib/feedback';
import { ago, money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Client = {
    id: number;
    name: string;
    status: string;
    phone: string | null;
    email: string | null;
    company: string | null;
    notes: string | null;
    manager_id: number | null;
    renew_days: number | null;
};

type OpenTask = {
    id: number;
    title: string;
    kind: string;
    due_at: string | null;
    assignee: string | null;
    overdue: boolean;
};

type PortalPerson = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    company: string | null;
    since: string | null;
};

const props = defineProps<{
    client: Client;
    figures: {
        usd_settled: number;
        cost_settled: number;
        received: number;
        margin: number;
        usd_pending: number;
        cost_pending: number;
        meta?: { charged: number; uncharged: number };
        paid?: {
            total: number;
            count: number;
            last: { date: string | null; amount: number } | null;
        };
    } | null;
    statement: Paginated<{
        key: string;
        id: number;
        book: 'usd' | 'bdt';
        date: string | null;
        description: string;
        account: string;
        usd: number | null;
        cost: number | null;
        bdt: number | null;
        status: string | null;
        settled_by: string | null;
    }>;
    statement_totals: {
        usd: number;
        cost: number;
        bdt: number;
        pending_cost: number;
        pending_usd: number;
    };
    campaigns: {
        id: number;
        name: string;
        live: boolean;
        account: string | null;
        spend_7d: number;
        spend_billed?: number | null;
        bill_from: string | null;
    }[];
    credit: {
        credit_usd: number;
        campaign_usd: number;
        credit_left: number;
        daily_usd: number;
        days_left: number | null;
        unearned: number;
    } | null;
    budget_days: {
        days: {
            day: string;
            budget: number | null;
            spend: number;
            diff: number | null;
        }[];
        over: number;
        under: number;
    };
    tasks: OpenTask[];
    invoices: {
        id: number;
        uid: string;
        number: string;
        issue_date: string;
        status: string;
        overdue: boolean;
        total: number;
        due: number;
    }[];
    pilot: { days: number; accounts: PilotAccount[] } | null;
    options: { users: Option[]; wallets: Option[] };
    portal: {
        users: PortalPerson[];
        waiting: PortalPerson[];
    };
}>();

// The client's ad accounts in Klok Pilot: last 1 / 7 / 30 days.
type PilotAccount = {
    id: number;
    name: string;
    external_id: string | null;
    error: string | null;
    currency: string;
    synced_at: string | null;
    totals: {
        spend?: number;
        results?: number;
        cost_per_result?: number | null;
        clicks?: number;
    };
    campaigns: {
        name: string;
        status: string | null;
        spend: number;
        results: number;
        cost_per_result: number | null;
        clicks: number;
    }[];
};

const pilotDays = ref(String(props.pilot?.days ?? 7));
const pilotLoading = ref(false);
const dayOptions = computed<Option[]>(() => [
    { value: '1', label: t('আজ') },
    { value: '7', label: t('৭ দিন') },
    { value: '30', label: t('৩০ দিন') },
]);

function loadPilot(days: string | number | null | undefined): void {
    router.reload({
        only: ['pilot'],
        data: { pilot_days: days },
        onStart: () => (pilotLoading.value = true),
        onFinish: () => (pilotLoading.value = false),
    });
}

function adMoney(value: number | null | undefined, currency: string): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return (
        (currency === 'BDT' ? String.fromCharCode(0x09f3) : '$') +
        value.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    );
}

const taskOpen = ref(false);
const clientOptions = computed<Option[]>(() => [
    { value: props.client.id, label: props.client.name },
]);

const can = computed(() => usePage().props.auth.can);

// Portal: a sign-up waiting for a client becomes this client's person.
const linkUser = ref<number | null>(null);
function link(): void {
    if (!linkUser.value) {
        return;
    }
    router.post(
        `/clients/${props.client.id}/portal-users`,
        { user_id: linkUser.value },
        { preserveScroll: true, onSuccess: () => (linkUser.value = null) },
    );
}
async function unlink(person: PortalPerson): Promise<void> {
    if (
        !(await confirmAction({
            title: t('{name}-কে portal থেকে সরাবেন?', { name: person.name }),
            body: t('উনি আর এই client-এর campaign বা invoice দেখবেন না।'),
            confirm: t('সরান'),
            danger: true,
        }))
    ) {
        return;
    }
    router.delete(`/clients/${props.client.id}/portal-users/${person.id}`, {
        preserveScroll: true,
    });
}

// Unspent advance given back: the money goes out, the credit is 0.
const refundOpen = ref(false);
const refund = useForm({
    entry_date: new Date().toISOString().slice(0, 10),
    wallet: (props.options.wallets[0]?.value as string) ?? 'bank',
    note: '',
});
const refundError = computed(
    () => (refund.errors as Record<string, string | undefined>).refund,
);
function sendRefund(): void {
    refund.post(`/clients/${props.client.id}/refund`, {
        preserveScroll: true,
        onSuccess: () => (refundOpen.value = false),
    });
}
const f = computed(
    () =>
        props.figures ?? {
            usd_settled: 0,
            cost_settled: 0,
            received: 0,
            margin: 0,
            usd_pending: 0,
            cost_pending: 0,
        },
);
const rate = computed(() =>
    f.value.usd_settled
        ? (f.value.received / f.value.usd_settled).toFixed(2)
        : null,
);

const open = ref(false);
// The client's own details; where they are empty, their portal person's, so there is something to start from.
const person = props.portal.users[0];

// One click: the owed spend becomes a sent invoice and the page moves to it.
const invoicingDue = ref(false);
function invoiceDue(): void {
    invoicingDue.value = true;
    router.post(
        `/clients/${props.client.id}/invoice-due`,
        {},
        { onFinish: () => (invoicingDue.value = false) },
    );
}
const form = useForm({
    ...props.client,
    phone: props.client.phone ?? person?.phone ?? '',
    email: props.client.email ?? person?.email ?? '',
    company: props.client.company ?? person?.company ?? '',
    notes: props.client.notes ?? '',
    renew_days: props.client.renew_days ? String(props.client.renew_days) : '',
});

const statuses: Option[] = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

function save(): void {
    form.put(`/clients/${props.client.id}`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

async function remove(): Promise<void> {
    if (
        await confirmAction({
            title: t('{name} মুছবেন?', { name: props.client.name }),
            body: t('যার কোনো লেনদেন নেই, শুধু তাকেই মোছা যায়।'),
            confirm: t('Trash-এ পাঠান'),
            danger: true,
        })
    ) {
        router.delete(`/clients/${props.client.id}`);
    }
}

function print(): void {
    window.print();
}
</script>

<template>
    <PageHeader
        eyebrow="Client"
        :title="client.name"
        :lead="client.notes ?? undefined"
    >
        <KButton icon="print" @click="print">Statement</KButton>
        <KButton
            v-if="can['tasks.create']"
            icon="bell"
            @click="taskOpen = true"
            >{{ t('Reminder যোগ') }}</KButton
        >
        <KButton
            v-if="can['clients.edit']"
            variant="primary"
            icon="pen"
            @click="open = true"
            >{{ t('বদলান') }}</KButton
        >
    </PageHeader>

    <div
        class="mb-6 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line lg:grid-cols-4"
    >
        <div class="bg-surface p-4 sm:p-5">
            <p class="eyebrow">{{ t('Ad খরচ (settled)') }}</p>
            <p class="num mt-2 text-[22px]">
                {{ money(f.usd_settled, 'usd') }}
            </p>
            <p class="num mt-1 text-[13px] text-ink-3">
                FIFO cost {{ money(f.cost_settled, 'bdt') }}
            </p>
            <p v-if="f.meta" class="num mt-1 text-[13px] text-ink-3">
                {{ t('Meta কেটেছে') }}
                <b class="text-in">{{ money(f.meta.charged, 'usd') }}</b>
                · {{ t('এখনো কাটেনি') }}
                <b class="text-wait">{{ money(f.meta.uncharged, 'usd') }}</b>
            </p>
        </div>
        <div class="bg-surface p-4 sm:p-5">
            <p class="eyebrow">{{ t('দিয়েছে') }}</p>
            <p class="num mt-2 text-[22px]">
                {{ money(f.paid?.total ?? f.received, 'bdt') }}
            </p>
            <p v-if="f.paid?.last" class="num mt-1 text-[13px] text-ink-3">
                {{ t('শেষ পেমেন্ট') }} {{ niceDate(f.paid.last.date, false) }} ·
                <b class="text-ink">{{ money(f.paid.last.amount, 'bdt') }}</b>
                · {{ t('{n}টা পেমেন্ট', { n: f.paid.count }) }}
            </p>
            <p class="num mt-1 text-[13px] text-ink-3">
                {{ t('আয় ধরা') }} {{ money(f.received, 'bdt')
                }}<template v-if="rate">
                    · {{ t('গড়') }} &#2547;{{ rate }} / USD</template
                >
            </p>
        </div>
        <div class="bg-surface p-4 sm:p-5">
            <p class="eyebrow">Margin</p>
            <p class="mt-2 text-[22px]">
                <KAmount :value="f.margin" currency="bdt" />
            </p>
        </div>
        <div class="bg-surface p-4 sm:p-5">
            <p class="eyebrow">{{ t('পাওনা') }}</p>
            <p class="num mt-2 text-[22px] text-wait">
                {{ money(f.usd_pending, 'usd') }}
            </p>
            <p class="num mt-1 text-[13px] text-ink-3">
                cost {{ money(f.cost_pending, 'bdt') }}
            </p>
            <KButton
                v-if="f.usd_pending > 0 && can['clients.edit']"
                size="sm"
                variant="primary"
                class="mt-2"
                :loading="invoicingDue"
                @click="invoiceDue"
                >{{ t('এক click-এ invoice') }}</KButton
            >
        </div>
    </div>

    <section class="no-print panel mb-6 p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-[16px] font-bold">Invoice</h2>
            <div class="flex items-center gap-2">
                <KButton size="sm" :href="`/invoices?client=${client.id}`">{{
                    t('সব দেখুন')
                }}</KButton>
                <KButton
                    v-if="can['clients.edit']"
                    size="sm"
                    variant="primary"
                    icon="plus"
                    :href="`/invoices/create?client=${client.id}`"
                    >{{ t('নতুন invoice') }}</KButton
                >
            </div>
        </div>
        <p
            v-if="!invoices.length"
            class="mt-3 rounded-lg bg-sunken px-3 py-2.5 text-[14px] text-ink-3"
        >
            {{ t('এখনো কোনো invoice নেই।') }}
        </p>
        <ul v-else class="mt-2">
            <li
                v-for="i in invoices"
                :key="i.id"
                class="border-b border-line last:border-0"
            >
                <Link
                    :href="`/invoices/${i.uid}`"
                    class="flex items-center gap-3 py-2.5 text-[14px]"
                >
                    <span class="num font-semibold">{{ i.number }}</span>
                    <span class="num text-ink-3">{{
                        niceDate(i.issue_date)
                    }}</span>
                    <span class="flex-1" />
                    <span class="num text-right"
                        >{{ money(i.total, 'bdt') }}
                        <span
                            class="block text-[13px]"
                            :class="
                                i.overdue
                                    ? 'text-out'
                                    : i.due
                                      ? 'text-wait'
                                      : 'text-in'
                            "
                            >{{
                                i.status === 'void'
                                    ? t('বাতিল')
                                    : i.due
                                      ? t('বাকি {amount}', {
                                            amount: money(i.due, 'bdt'),
                                        })
                                      : 'Paid'
                            }}</span
                        ></span
                    >
                </Link>
            </li>
        </ul>
    </section>

    <div class="no-print mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section class="panel p-5 sm:p-6">
            <div class="flex items-baseline justify-between">
                <h2 class="text-[16px] font-bold">Campaign</h2>
                <KButton v-if="can['runs.view']" size="sm" href="/campaigns">{{
                    t('সব দেখুন')
                }}</KButton>
            </div>
            <div
                v-if="credit && (credit.credit_usd || credit.campaign_usd)"
                class="mt-3 grid grid-cols-3 gap-2 text-[13.5px]"
            >
                <div class="rounded-lg bg-sunken px-3 py-2">
                    <p class="text-ink-3">{{ t('আগাম credit') }}</p>
                    <p class="num mt-0.5 font-semibold">
                        {{ money(credit.credit_usd, 'usd') }}
                    </p>
                </div>
                <div class="rounded-lg bg-sunken px-3 py-2">
                    <p class="text-ink-3">{{ t('খরচ হয়েছে') }}</p>
                    <p class="num mt-0.5 font-semibold">
                        {{ money(credit.campaign_usd, 'usd') }}
                    </p>
                </div>
                <div class="rounded-lg bg-sunken px-3 py-2">
                    <p class="text-ink-3">{{ t('বাকি credit') }}</p>
                    <p
                        class="num mt-0.5 font-semibold"
                        :class="credit.credit_left < 0 ? 'text-out' : ''"
                    >
                        {{ money(credit.credit_left, 'usd') }}
                    </p>
                </div>
            </div>
            <div
                v-if="
                    credit &&
                    credit.credit_left > 0 &&
                    credit.unearned > 0 &&
                    can['bdt.create']
                "
                class="mt-2 flex justify-end"
            >
                <KButton
                    size="sm"
                    variant="plain"
                    icon="undo"
                    @click="refundOpen = true"
                    >{{ t('বাকি credit ফেরত দিন') }}</KButton
                >
            </div>
            <p
                v-if="credit && credit.credit_usd && credit.daily_usd"
                class="mt-2 text-[13.5px] text-ink-3"
            >
                {{
                    t(
                        'রোজ গড়ে {amount} খরচ — বাকি টাকায় আরও প্রায় {n} দিন চলবে।',
                        {
                            amount: money(credit.daily_usd, 'usd'),
                            n: credit.days_left ?? 0,
                        },
                    )
                }}
            </p>
            <p
                v-if="!campaigns.length"
                class="mt-3 rounded-lg bg-sunken px-3 py-2.5 text-[14px] text-ink-3"
            >
                {{ t('এখনো কোনো campaign এই client-এর সাথে যুক্ত নয়।') }}
            </p>
            <ul v-else class="mt-2">
                <li
                    v-for="c in campaigns"
                    :key="c.id"
                    class="flex items-center gap-3 border-b border-line py-2.5 text-[14px] last:border-0"
                >
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :class="c.live ? 'bg-in' : 'bg-line-2'"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            c.name
                        }}</span>
                        <span class="text-[13px] text-ink-3"
                            >{{ c.account }}
                            <template v-if="c.bill_from">
                                ·
                                {{
                                    t('{date} থেকে এই client-এর হিসাবে', {
                                        date: niceDate(c.bill_from, false),
                                    })
                                }}</template
                            ></span
                        >
                    </span>
                    <span class="num shrink-0 text-right"
                        >{{ money(c.spend_billed ?? c.spend_7d, 'usd') }}
                        <span class="block text-[13px] text-ink-3">{{
                            c.spend_billed !== null &&
                            c.spend_billed !== undefined
                                ? t('হিসাবে মোট · ৭ দিনে {amount}', {
                                      amount: money(c.spend_7d, 'usd'),
                                  })
                                : t('৭ দিনে')
                        }}</span></span
                    >
                </li>
            </ul>
        </section>

        <section class="panel p-5 sm:p-6">
            <div class="flex items-baseline justify-between">
                <h2 class="text-[16px] font-bold">
                    {{ t('বাকি কাজ ও reminder') }}
                </h2>
                <KButton
                    v-if="can['tasks.view']"
                    size="sm"
                    :href="`/tasks?client_id=${client.id}`"
                    >{{ t('সব দেখুন') }}</KButton
                >
            </div>
            <p
                v-if="!tasks.length"
                class="mt-3 rounded-lg bg-sunken px-3 py-2.5 text-[14px] text-ink-3"
            >
                {{ t('কোনো কাজ বাকি নেই।') }}
            </p>
            <ul v-else class="mt-2">
                <li
                    v-for="task in tasks"
                    :key="task.id"
                    class="flex items-center gap-3 border-b border-line py-2.5 text-[14px] last:border-0"
                >
                    <span class="min-w-0 flex-1 truncate font-medium">{{
                        t(task.title)
                    }}</span>
                    <span
                        class="num shrink-0 text-[13px]"
                        :class="
                            task.overdue
                                ? 'font-semibold text-out'
                                : 'text-ink-3'
                        "
                        >{{
                            task.due_at
                                ? niceDate(task.due_at.slice(0, 10), false) +
                                  ' ' +
                                  task.due_at.slice(11, 16)
                                : t('তারিখ নেই')
                        }}</span
                    >
                </li>
            </ul>
        </section>
    </div>

    <section v-if="pilot" class="no-print panel mb-6 p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="flex items-center gap-2 text-[16px] font-bold">
                <KIcon name="signal" class="text-brand" />{{
                    t('Ad account (Klok Pilot)')
                }}
            </h2>
            <KSegment
                v-model="pilotDays"
                :options="dayOptions"
                size="sm"
                @update:model-value="loadPilot"
            />
        </div>

        <div
            class="transition-opacity"
            :class="pilotLoading ? 'opacity-50' : ''"
        >
            <div
                v-for="a in pilot.accounts"
                :key="a.id"
                class="mt-5 border-t border-line pt-4 first:border-0 first:pt-0"
            >
                <p class="num truncate text-[14px] font-semibold">
                    {{ a.name
                    }}<span class="font-normal text-ink-3">
                        <template v-if="a.external_id">
                            · {{ a.external_id }}</template
                        ><template v-if="a.synced_at">
                            · sync {{ ago(a.synced_at) }}</template
                        ></span
                    >
                </p>
                <p v-if="a.error" class="mt-2 text-[14px] text-out">
                    {{ a.error }}
                </p>
                <template v-else>
                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-xl bg-sunken px-4 py-3">
                            <p class="text-[13px] text-ink-3">
                                {{ t('খরচ') }}
                            </p>
                            <p class="num mt-1 text-[17px] font-bold">
                                {{ adMoney(a.totals.spend ?? 0, a.currency) }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-sunken px-4 py-3">
                            <p class="text-[13px] text-ink-3">Result</p>
                            <p class="num mt-1 text-[17px] font-bold">
                                {{ a.totals.results ?? 0 }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-sunken px-4 py-3">
                            <p class="text-[13px] text-ink-3">
                                {{ t('প্রতি result খরচ') }}
                            </p>
                            <p class="num mt-1 text-[17px] font-bold">
                                {{
                                    adMoney(
                                        a.totals.cost_per_result,
                                        a.currency,
                                    )
                                }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-sunken px-4 py-3">
                            <p class="text-[13px] text-ink-3">Click</p>
                            <p class="num mt-1 text-[17px] font-bold">
                                {{
                                    (a.totals.clicks ?? 0).toLocaleString(
                                        'en-US',
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="!a.campaigns.length"
                        class="mt-3 rounded-lg bg-sunken px-3 py-2.5 text-[14px] text-ink-3"
                    >
                        {{ t('এই সময়ে কোনো campaign-এ খরচ হয়নি।') }}
                    </p>
                    <ul v-else class="mt-2">
                        <li
                            v-for="c in a.campaigns.slice(0, 8)"
                            :key="c.name"
                            class="flex items-center gap-3 border-b border-line py-2.5 text-[14px] last:border-0"
                        >
                            <span class="min-w-0 flex-1 truncate font-medium">{{
                                c.name
                            }}</span>
                            <span class="num shrink-0">{{
                                adMoney(c.spend, a.currency)
                            }}</span>
                            <span class="num w-20 shrink-0 text-right"
                                >{{ c.results }} result</span
                            >
                            <span
                                class="num hidden w-28 shrink-0 text-right text-ink-3 sm:block"
                                >{{
                                    c.cost_per_result !== null
                                        ? 'CPR ' +
                                          adMoney(c.cost_per_result, a.currency)
                                        : '—'
                                }}</span
                            >
                        </li>
                    </ul>
                </template>
            </div>
        </div>
    </section>

    <section
        v-if="budget_days.days.length"
        class="no-print panel mb-6 overflow-hidden"
    >
        <div
            class="flex flex-wrap items-baseline justify-between gap-3 px-5 pt-5 sm:px-6"
        >
            <div>
                <h2 class="text-[16px] font-bold">
                    {{ t('Budget বনাম আসল খরচ') }}
                </h2>
                <p class="mt-1 text-[13.5px] text-ink-3">
                    {{
                        t(
                            'শেষ ৩০ দিন — Meta কোনো দিন budget-এর চেয়ে বেশি, কোনো দিন কম খরচ করে।',
                        )
                    }}
                </p>
            </div>
            <p class="text-[13.5px]">
                <span class="text-wait"
                    >{{ t('বেশি গেছে') }}
                    <b class="num">{{
                        money(budget_days.over, 'usd')
                    }}</b></span
                >
                <span class="mx-2 text-line-2">·</span>
                <span class="text-in"
                    >{{ t('কম গেছে') }}
                    <b class="num">{{
                        money(budget_days.under, 'usd')
                    }}</b></span
                >
            </p>
        </div>
        <div class="mt-3 max-h-[360px] overflow-auto">
            <table
                v-stack
                class="stack ledger w-full min-w-[480px] text-[14px]"
            >
                <thead>
                    <tr>
                        <th class="px-5 py-2.5 text-left sm:px-6">
                            {{ t('দিন') }}
                        </th>
                        <th class="px-3 py-2.5 text-right">Budget</th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('খরচ') }}
                        </th>
                        <th class="px-5 py-2.5 text-right sm:px-6">
                            {{ t('পার্থক্য') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in budget_days.days" :key="d.day">
                        <td class="px-5 py-2 sm:px-6">
                            {{ niceDate(d.day, false) }}
                        </td>
                        <td class="num px-3 py-2 text-right text-ink-3">
                            {{
                                d.budget === null ? '—' : money(d.budget, 'usd')
                            }}
                        </td>
                        <td class="num px-3 py-2 text-right">
                            {{ money(d.spend, 'usd') }}
                        </td>
                        <td
                            class="num px-5 py-2 text-right sm:px-6"
                            :class="
                                d.diff === null || d.diff === 0
                                    ? 'text-ink-3'
                                    : d.diff > 0
                                      ? 'text-wait'
                                      : 'text-in'
                            "
                        >
                            {{
                                d.diff === null
                                    ? '—'
                                    : money(d.diff, 'usd', { sign: true })
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="no-print panel mb-6 p-5 sm:p-6">
        <h2 class="text-[16px] font-bold">{{ t('Client portal') }}</h2>
        <p class="mt-1 text-[14px] text-ink-3">
            {{
                t(
                    'যাঁরা এখানে যুক্ত, তাঁরা login করে এই client-এর campaign, খরচ, result আর invoice দেখেন — অন্য কিছু না।',
                )
            }}
        </p>
        <ul v-if="portal.users.length" class="mt-3 divide-y divide-line">
            <li
                v-for="u in portal.users"
                :key="u.id"
                class="flex items-center gap-3 py-2.5 text-[14.5px]"
            >
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold">{{ u.name }}</span>
                    <span class="block truncate text-[13.5px] text-ink-3"
                        >{{ u.email
                        }}<template v-if="u.phone">
                            · {{ u.phone }}</template
                        ></span
                    >
                </span>
                <KButton
                    v-if="can['clients.edit']"
                    size="sm"
                    variant="danger"
                    icon="close"
                    @click="unlink(u)"
                    >{{ t('সরান') }}</KButton
                >
            </li>
        </ul>
        <div
            v-if="can['clients.edit'] && portal.waiting.length"
            class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
        >
            <KField :label="t('Register করে অপেক্ষায় আছেন')" class="min-w-0">
                <KSelect
                    v-model="linkUser"
                    :options="
                        portal.waiting.map((w) => ({
                            value: w.id,
                            label: `${w.name}${w.company ? ' · ' + w.company : ''} · ${w.phone ?? w.email}`,
                        }))
                    "
                    :placeholder="t('কাকে যুক্ত করবেন?')"
                />
            </KField>
            <KButton variant="primary" :disabled="!linkUser" @click="link">{{
                t('যুক্ত করুন')
            }}</KButton>
        </div>
        <p
            v-else-if="!portal.users.length"
            class="mt-3 rounded-lg bg-sunken px-3 py-2.5 text-[14px] text-ink-3"
        >
            {{
                t(
                    'এখনো কেউ নেই। Client-কে login পাতার "account খুলুন" থেকে register করতে বলুন, তারপর এখানে যুক্ত করুন।',
                )
            }}
        </p>
    </section>

    <section class="panel overflow-hidden">
        <div class="px-5 pt-5 sm:px-6">
            <h2 class="text-[16px] font-bold">Statement</h2>
            <p class="mt-1 text-[13.5px] text-ink-3">
                {{ t('USD খরচ আর BDT পেমেন্ট — তারিখ অনুযায়ী') }}
            </p>
        </div>
        <KEmpty
            v-if="!statement.data.length"
            :title="t('এখনো কোনো লেনদেন নেই')"
        />
        <div v-else class="mt-3 overflow-x-auto">
            <table v-stack class="stack ledger">
                <thead>
                    <tr>
                        <th>{{ t('তারিখ') }}</th>
                        <th>{{ t('বিবরণ') }}</th>
                        <th class="r">{{ t('Ad খরচ (USD)') }}</th>
                        <th class="r">FIFO cost</th>
                        <th class="r">{{ t('পেমেন্ট (BDT)') }}</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in statement.data" :key="r.key">
                        <td
                            class="num text-[13.5px] whitespace-nowrap text-ink-2"
                        >
                            {{ niceDate(r.date) }}
                        </td>
                        <td>
                            <p class="font-medium">{{ r.description }}</p>
                            <p class="text-[13px] text-ink-3">
                                {{ r.account }}
                            </p>
                        </td>
                        <td class="r num">
                            {{ r.usd !== null ? money(r.usd, 'usd') : '' }}
                        </td>
                        <td class="r num text-ink-2">
                            {{ r.cost !== null ? money(r.cost, 'bdt') : '' }}
                        </td>
                        <td class="r num text-in">
                            {{ r.bdt !== null ? money(r.bdt, 'bdt') : '' }}
                            <span
                                v-if="r.settled_by"
                                class="block text-[12.5px] font-normal whitespace-nowrap text-ink-3"
                                >{{
                                    t('{payment} দিয়ে', {
                                        payment: r.settled_by,
                                    })
                                }}</span
                            >
                        </td>
                        <td>
                            <KTag v-if="r.status === 'pending'" tone="wait"
                                >Pending</KTag
                            >
                            <KTag v-else-if="r.status === 'settled'" tone="in"
                                >Settled</KTag
                            >
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr
                        class="border-t-2 border-line-2 bg-surface-2 font-semibold"
                    >
                        <td class="text-[13px] text-ink-3" colspan="2">
                            {{ t('মোট') }}
                            <span
                                v-if="statement_totals.pending_usd"
                                class="ml-2 font-normal text-wait"
                            >
                                {{
                                    t('পাওনা {usd} (cost {cost})', {
                                        usd: money(
                                            statement_totals.pending_usd,
                                            'usd',
                                        ),
                                        cost: money(
                                            statement_totals.pending_cost,
                                            'bdt',
                                        ),
                                    })
                                }}
                            </span>
                        </td>
                        <td class="r num">
                            {{ money(statement_totals.usd, 'usd') }}
                        </td>
                        <td class="r num text-ink-2">
                            {{ money(statement_totals.cost, 'bdt') }}
                        </td>
                        <td class="r num text-in">
                            {{ money(statement_totals.bdt, 'bdt') }}
                        </td>
                        <td />
                    </tr>
                </tfoot>
            </table>
            <KPager :page="statement" />
        </div>
    </section>

    <KModal v-model="open" :title="t('{name} — বদলান', { name: client.name })">
        <form
            id="client-edit"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField
                :label="t('নাম')"
                :error="form.errors.name"
                class="sm:col-span-2"
                ><KInput v-model="form.name" :invalid="!!form.errors.name"
            /></KField>
            <KField :label="t('অবস্থা')"
                ><KSegment v-model="form.status" :options="statuses"
            /></KField>
            <KField label="Phone" optional
                ><KInput v-model="form.phone" type="tel"
            /></KField>
            <KField label="Email" optional :error="form.errors.email"
                ><KInput v-model="form.email" type="email"
            /></KField>
            <KField label="Company" optional class="sm:col-span-2"
                ><KInput v-model="form.company"
            /></KField>
            <KField
                label="Manager"
                optional
                :hint="t('এই client-এর ad আর কাজের reminder এঁর কাছে যাবে।')"
                ><KSelect
                    v-model="form.manager_id"
                    :options="options.users"
                    :placeholder="t('কেউ না')"
                    clearable
            /></KField>
            <KField
                :label="t('Auto renewal invoice (দিন)')"
                optional
                :error="form.errors.renew_days"
                :hint="
                    t(
                        'Campaign শেষের ২ দিন আগে এত দিনের invoice নিজে তৈরি হবে; টাকা এলে campaign নিজে বাড়বে। খালি রাখলে client link থেকে দিন বাছবে।',
                    )
                "
                ><KInput
                    v-model="form.renew_days"
                    inputmode="numeric"
                    :placeholder="t('যেমন 7')"
                    :invalid="!!form.errors.renew_days"
            /></KField>
            <KField label="Note" optional class="sm:col-span-2"
                ><KTextarea v-model="form.notes"
            /></KField>
        </form>
        <template #footer>
            <KButton
                v-if="can['clients.delete'] && !statement.data.length"
                variant="danger"
                icon="bin"
                class="mr-auto"
                @click="remove"
                >{{ t('মুছুন') }}</KButton
            >
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="client-edit"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <TaskForm
        v-model="taskOpen"
        :task="null"
        :client-id="client.id"
        :options="{ clients: clientOptions, users: options.users }"
    />
    <KModal v-model="refundOpen" :title="t('বাকি credit ফেরত দিন')">
        <p v-if="credit" class="text-[14.5px] text-ink-2">
            {{
                t(
                    '{client}-এর {usd} credit খরচ হয়নি — যে দামে দিয়েছিল সেই দামে {bdt} ফেরত যাবে, আর credit হবে ০।',
                    {
                        client: client.name,
                        usd: money(credit.credit_left, 'usd'),
                        bdt: money(credit.unearned, 'bdt'),
                    },
                )
            }}
        </p>
        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <KField :label="t('তারিখ')" :error="refund.errors.entry_date">
                <KDate v-model="refund.entry_date" />
            </KField>
            <KField :label="t('কোথা থেকে দিলেন')" :error="refund.errors.wallet">
                <KSelect v-model="refund.wallet" :options="options.wallets" />
            </KField>
        </div>
        <KField :label="t('Note')" optional class="mt-4">
            <KInput v-model="refund.note" />
        </KField>
        <p v-if="refundError" class="mt-3 text-[13.5px] text-out">
            {{ refundError }}
        </p>
        <template #footer>
            <KButton variant="quiet" @click="refundOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                :loading="refund.processing"
                @click="sendRefund"
                >{{ t('ফেরত লিখুন') }}</KButton
            >
        </template>
    </KModal>
</template>
