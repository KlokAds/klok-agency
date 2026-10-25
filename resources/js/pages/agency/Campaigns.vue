<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import RangePicker from '@/components/portal/RangePicker.vue';
import KSelect from '@/components/k/KSelect.vue';
import KIcon from '@/components/k/KIcon.vue';
import KTag from '@/components/k/KTag.vue';
import KField from '@/components/k/KField.vue';
import { useFilters } from '@/composables/useFilters';
import { confirmAction, toast } from '@/lib/feedback';
import { ago, endsAt, money, niceDate, today } from '@/lib/format';

// The dates the chosen period covers, written small beside the status filter.
const covers = computed(() =>
    props.range.from === props.range.to
        ? niceDate(props.range.from)
        : `${niceDate(props.range.from, false)} – ${niceDate(props.range.to)}`,
);
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    slug?: string | null;
    name: string;
    status: string | null;
    live: boolean;
    objective: string | null;
    daily_budget: number | null;
    spend_cap: number | null;
    paused_for_credit: boolean;
    state: string | null;
    controllable: boolean;
    limits_error: string | null;
    guarded: boolean;
    renewal: { id: number; status: string } | null;
    start_at: string | null;
    stop_at: string | null;
    ends_at: string | null;
    ended: boolean;
    spend_total: number;
    spend_7d: number;
    period: { spend: number; results: number };
    last_spend_day: string | null;
    client_id: number | null;
    client: string | null;
    bill_from: string | null;
    start: string | null;
    suggested: { id: number; name: string } | null;
};

// Billing by campaign: pick an ad account, link each campaign to the
// client it runs for, and from which day its spend is billed to them.
const props = defineProps<{
    connected: boolean;
    error: string | null;
    accounts: Option[];
    account: {
        id: number;
        name: string;
        external_id: string | null;
        currency: string | null;
        shop: string | null;
    } | null;
    campaigns: Paginated<Row>;
    counts: { total: number; linked: number };
    filters: { q: string | null; status: string };
    range: { key: string; from: string; to: string };
    synced_at: string | null;
    options: { clients: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);
const usdAccount = computed(() => (props.account?.currency ?? 'USD') === 'USD');

const { filters } = useFilters('/campaigns', {
    account: props.account?.id ?? null,
    q: props.filters.q,
    status: props.filters.status,
    range: props.range.key,
    from: props.range.key === 'custom' ? props.range.from : null,
    to: props.range.key === 'custom' ? props.range.to : null,
});

const statuses = computed<Option[]>(() => [
    { value: 'all', label: t('সব') },
    { value: 'live', label: t('চালু') },
    { value: 'off', label: t('বন্ধ') },
    { value: 'unlinked', label: t('Client নেই') },
    { value: 'linked', label: t('যুক্ত') },
]);

const spend = (cents: number | null) =>
    cents === null ? '—' : money(cents, 'usd');

// Linking asks for the first day to bill, so old spend is not billed twice.
const editing = ref<Row | null>(null);
const linkOpen = ref(false);
const linkClient = ref<number | string | null>(null);
const linkFrom = ref<string | null>(null);
const saving = ref(false);

function startLink(row: Row, clientId: number | string | null = null): void {
    editing.value = row;
    linkClient.value = clientId ?? row.client_id ?? row.suggested?.id ?? null;
    linkFrom.value = row.bill_from;
    linkOpen.value = true;
}

function saveLink(): void {
    if (!editing.value) {
        return;
    }

    router.put(
        `/campaigns/${editing.value.id}/client`,
        {
            client_id: linkClient.value,
            bill_from: linkClient.value ? linkFrom.value : null,
        },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => (linkOpen.value = false),
        },
    );
}

function unlink(): void {
    linkClient.value = null;
    saveLink();
}

const suggestedCount = computed(
    () => props.campaigns.data.filter((c) => c.suggested).length,
);
const bulkOpen = ref(false);
const bulkFrom = ref<string | null>(null);

function linkSuggested(): void {
    router.post(
        '/campaigns/link-suggested',
        { account: props.account?.id, bill_from: bulkFrom.value },
        { preserveScroll: true, onSuccess: () => (bulkOpen.value = false) },
    );
}

// More days by hand (the client paid some other way): the end moves on Meta.
const extending = ref<Row | null>(null);
const extendOpen = ref(false);
const extendDays = ref<string>('7');
const extendError = ref<string | null>(null);

function startExtend(row: Row): void {
    extending.value = row;
    extendDays.value = '7';
    extendError.value = null;
    extendOpen.value = true;
}

function extend(): void {
    if (!extending.value) {
        return;
    }

    router.post(
        `/campaigns/${extending.value.id}/extend`,
        { days: Number.parseInt(extendDays.value, 10) || 0 },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => (extendOpen.value = false),
            onError: (e) => (extendError.value = e.days ?? null),
        },
    );
}

// On, off and the budget a day, on Meta through Pilot — as the client can on the portal.
const busyId = ref<number | null>(null);
async function setActive(row: Row, on: boolean): Promise<void> {
    if (
        !on &&
        !(await confirmAction({
            title: t('Campaign থামাবেন?'),
            body: row.client
                ? t(
                      '{client} portal-এ দেখবেন “Klok Ads থামিয়েছে”, আর খবর পাবেন।',
                      {
                          client: row.client,
                      },
                  )
                : undefined,
            confirm: t('থামান'),
            danger: true,
        }))
    ) {
        return;
    }
    busyId.value = row.id;
    router.post(
        `/campaigns/${row.id}/${on ? 'resume' : 'pause'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (busyId.value = null),
            onError: (e) => e.control && toast(e.control, 'error'),
        },
    );
}

const budgeting = ref<Row | null>(null);
const budgetOpen = ref(false);
const budgetValue = ref('');
const budgetError = ref<string | null>(null);
function startBudget(row: Row): void {
    budgeting.value = row;
    budgetValue.value = row.daily_budget
        ? (row.daily_budget / 100).toFixed(2)
        : '';
    budgetError.value = null;
    budgetOpen.value = true;
}
function saveBudget(): void {
    if (!budgeting.value) {
        return;
    }
    saving.value = true;
    router.post(
        `/campaigns/${budgeting.value.id}/budget`,
        { budget: budgetValue.value },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = false),
            onSuccess: () => (budgetOpen.value = false),
            onError: (e) => (budgetError.value = e.budget ?? e.control ?? null),
        },
    );
}

const syncing = ref(false);
function sync(): void {
    router.post(
        '/campaigns/sync',
        { account: props.account?.id },
        {
            preserveScroll: true,
            onStart: () => (syncing.value = true),
            onFinish: () => (syncing.value = false),
        },
    );
}
</script>

<template>
    <SectionTabs section="campaigns" />
    <PageHeader
        :eyebrow="t('Client ও Ads')"
        title="Campaign"
        :lead="
            t(
                'Ad account বেছে তার campaign-গুলো দেখুন, প্রতিটা campaign কোন client-এর তা বেছে দিন — সেই campaign-এর খরচ ওই client-এর হিসাবে যাবে।',
            )
        "
    >
        <KButton
            v-if="connected"
            icon="undo"
            :loading="syncing"
            @click="sync"
            >{{ t('Pilot থেকে আনুন') }}</KButton
        >
    </PageHeader>

    <p v-if="error" class="panel mb-6 px-5 py-3 text-[14px] text-out">
        Klok Pilot: {{ error }}
    </p>

    <KEmpty
        v-if="!connected || !accounts.length"
        class="panel"
        :title="t('কোনো ad account নেই')"
        :body="t('Klok Pilot-এ ad account যুক্ত করলে এখানে দেখা যাবে।')"
    />

    <template v-else>
        <section class="panel mb-4 p-4 sm:p-5">
            <div
                class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,320px)_minmax(0,1fr)]"
            >
                <KSelect
                    v-model="filters.account"
                    :options="accounts"
                    :placeholder="t('Ad account বাছুন')"
                />
                <KInput
                    v-model="filters.q"
                    :placeholder="t('Campaign খুঁজুন…')"
                    icon="search"
                />
            </div>
            <div class="mt-3 border-t border-line pt-3">
                <RangePicker
                    url="/campaigns"
                    :range="range"
                    :params="{
                        account: filters.account,
                        q: filters.q,
                        status: filters.status,
                    }"
                    dense
                />
            </div>
            <div
                class="mt-3 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 sm:justify-between"
            >
                <KSegment
                    v-model="filters.status"
                    :options="statuses"
                    size="sm"
                />
                <p
                    class="num w-full text-center text-[13px] text-ink-3 sm:w-auto sm:text-left"
                >
                    {{ covers }}
                    ·
                    {{
                        t('{linked}/{total} টা যুক্ত', {
                            linked: counts.linked,
                            total: counts.total,
                        })
                    }}
                    <template v-if="synced_at">
                        · {{ t('খরচ মেলানো') }} {{ ago(synced_at) }}</template
                    >
                </p>
            </div>
            <p
                v-if="account && !usdAccount"
                class="mt-3 rounded-lg bg-wait-soft px-3 py-2 text-[13.5px] text-wait"
            >
                {{
                    t(
                        'এই account-এর টাকা {currency}-এ — card-এর USD হিসাবে শুধু USD account-এর campaign যোগ হয়।',
                        { currency: account.currency ?? '' },
                    )
                }}
            </p>
            <div
                v-if="suggestedCount && can['clients.edit']"
                class="mt-3 flex items-center justify-between gap-3 rounded-lg bg-brand-soft px-3 py-2 text-[13.5px] text-brand-ink"
            >
                <span class="min-w-0">{{
                    t('{n}টা campaign-এর নামে client-এর নাম আছে।', {
                        n: suggestedCount,
                    })
                }}</span>
                <KButton size="sm" class="shrink-0" @click="bulkOpen = true">{{
                    t('যুক্ত করুন')
                }}</KButton>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <KEmpty
                v-if="!campaigns.data.length"
                :title="t('কোনো campaign পাওয়া যায়নি')"
                :body="t('অন্য account বা filter দেখুন, বা Pilot থেকে আনুন।')"
            />
            <!-- Phone: one card a campaign — name and state first, the money, then what to do. -->
            <ul
                v-if="campaigns.data.length"
                class="space-y-3 bg-paper p-3 sm:hidden"
            >
                <li
                    v-for="c in campaigns.data"
                    :key="c.id"
                    class="panel p-4"
                    :class="c.live ? 'border-l-[3px] border-l-in' : ''"
                >
                    <div class="flex items-start gap-2.5">
                        <span
                            class="mt-1.5 size-2.5 shrink-0 rounded-full"
                            :class="
                                c.live
                                    ? 'bg-in'
                                    : c.ended
                                      ? 'bg-done'
                                      : 'bg-wait'
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/campaigns/${c.slug ?? c.id}`"
                                class="block text-[15.5px] leading-snug font-semibold hover:text-brand"
                                >{{ c.name }}</Link
                            >
                            <p class="mt-0.5 text-[13px] text-ink-3">
                                {{
                                    c.live
                                        ? t('চালু')
                                        : c.ended
                                          ? t('শেষ হয়েছে')
                                          : t('বন্ধ')
                                }}
                                <template v-if="c.daily_budget">
                                    · {{ t('দিনে') }}
                                    {{ spend(c.daily_budget) }}</template
                                >
                                <template v-if="c.ends_at">
                                    · {{ c.ended ? '' : t('শেষ') + ' '
                                    }}{{ endsAt(c.ends_at) }}</template
                                >
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-3 grid grid-cols-3 divide-x divide-line rounded-xl bg-sunken py-2 text-center"
                    >
                        <p>
                            <span class="block text-[12px] text-ink-3">{{
                                t('এই সময়ে')
                            }}</span>
                            <b class="num text-[14.5px]">{{
                                spend(c.period.spend)
                            }}</b>
                            <span class="num block text-[12px] text-ink-3"
                                >{{ c.period.results }} result</span
                            >
                        </p>
                        <p>
                            <span class="block text-[12px] text-ink-3">{{
                                t('মোট খরচ')
                            }}</span>
                            <b class="num text-[14.5px]">{{
                                spend(c.spend_total)
                            }}</b>
                        </p>
                        <p>
                            <span class="block text-[12px] text-ink-3">{{
                                t('শেষ খরচ')
                            }}</span>
                            <b class="num text-[14.5px]">{{
                                c.last_spend_day
                                    ? niceDate(c.last_spend_day, false)
                                    : '—'
                            }}</b>
                        </p>
                    </div>

                    <!-- Whose it is: the client, with a pen to change it; or the one thing to do. -->
                    <div
                        v-if="c.client_id"
                        class="mt-3 flex items-center gap-2 border-t border-line pt-3 text-[13.5px]"
                    >
                        <KIcon
                            name="user"
                            :size="14"
                            class="shrink-0 text-ink-3"
                        />
                        <span class="min-w-0 flex-1 truncate">
                            <b class="font-semibold">{{ c.client }}</b>
                            <span class="text-ink-3">
                                ·
                                {{
                                    t('{date} থেকে', {
                                        date: niceDate(
                                            c.bill_from ?? '',
                                            false,
                                        ),
                                    })
                                }}</span
                            >
                        </span>
                        <button
                            v-if="can['clients.edit']"
                            type="button"
                            class="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-brand/30 bg-brand-soft px-2.5 text-[13px] font-semibold whitespace-nowrap text-brand-ink transition-colors hover:border-brand"
                            :aria-label="t('Client বদলান')"
                            @click="startLink(c)"
                        >
                            <KIcon name="pen" :size="14" />{{ t('বদলান') }}
                        </button>
                    </div>
                    <div
                        v-else
                        class="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3"
                    >
                        <template v-if="can['clients.edit']">
                            <KButton
                                v-if="c.suggested"
                                size="sm"
                                variant="primary"
                                icon="check"
                                @click="startLink(c, c.suggested.id)"
                                >{{ c.suggested.name }}</KButton
                            >
                            <KButton
                                size="sm"
                                :variant="c.suggested ? 'plain' : 'primary'"
                                icon="plus"
                                @click="startLink(c)"
                                >{{ t('Client বাছুন') }}</KButton
                            >
                        </template>
                        <KTag v-else tone="wait">{{
                            t('Client বাছা হয়নি')
                        }}</KTag>
                    </div>

                    <!-- What to do with it: switch, budget, more days — at most three, never wrapping. -->
                    <div
                        v-if="
                            c.client_id &&
                            ((c.controllable && can['runs.edit']) ||
                                (c.guarded && can['clients.edit']))
                        "
                        class="mt-2.5 grid gap-2"
                        :class="
                            c.controllable && can['runs.edit']
                                ? 'grid-cols-3'
                                : 'grid-cols-1'
                        "
                    >
                        <template v-if="c.controllable && can['runs.edit']">
                            <KButton
                                v-if="c.live"
                                size="sm"
                                variant="plain"
                                block
                                :loading="busyId === c.id"
                                @click="setActive(c, false)"
                                >{{ t('থামান') }}</KButton
                            >
                            <KButton
                                v-else
                                size="sm"
                                variant="primary"
                                block
                                :loading="busyId === c.id"
                                @click="setActive(c, true)"
                                >{{ t('চালু করুন') }}</KButton
                            >
                            <KButton
                                size="sm"
                                variant="plain"
                                block
                                @click="startBudget(c)"
                                >Budget</KButton
                            >
                        </template>
                        <KButton
                            v-if="c.guarded && can['clients.edit']"
                            size="sm"
                            variant="primary"
                            block
                            icon="renew"
                            @click="startExtend(c)"
                            >Extend</KButton
                        >
                    </div>
                </li>
            </ul>

            <div
                v-if="campaigns.data.length"
                class="hidden overflow-x-auto sm:block"
            >
                <table
                    class="w-full min-w-[760px] text-[14px] [&_th]:whitespace-nowrap"
                >
                    <thead>
                        <tr
                            class="border-b border-line text-left text-[13.5px] font-semibold text-ink-3"
                        >
                            <th class="px-5 py-3">Campaign</th>
                            <th class="px-3 py-3 text-right">
                                {{ t('এই সময়ে') }}
                            </th>
                            <th class="px-3 py-3 text-right">
                                {{ t('মোট খরচ') }}
                            </th>
                            <th class="px-3 py-3">{{ t('শেষ খরচ') }}</th>
                            <th class="px-5 py-3">Client</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in campaigns.data"
                            :key="c.id"
                            class="border-b border-line last:border-0"
                        >
                            <td class="max-w-[340px] px-5 py-3">
                                <p class="flex items-center gap-2">
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        :class="
                                            c.live
                                                ? 'bg-in'
                                                : c.ended
                                                  ? 'bg-done'
                                                  : 'bg-wait'
                                        "
                                        :title="c.status ?? ''"
                                    />
                                    <Link
                                        :href="`/campaigns/${c.slug ?? c.id}`"
                                        class="truncate font-medium hover:text-brand"
                                        >{{ c.name }}</Link
                                    >
                                </p>
                                <p class="mt-0.5 pl-4 text-[13px] text-ink-3">
                                    {{
                                        c.live
                                            ? t('চালু')
                                            : c.ended
                                              ? t('শেষ হয়েছে')
                                              : t('বন্ধ')
                                    }}
                                    <template v-if="c.daily_budget">
                                        · {{ t('দিনে') }}
                                        {{ spend(c.daily_budget) }}</template
                                    >
                                    <template v-if="c.ends_at">
                                        · {{ c.ended ? '' : t('শেষ') + ' '
                                        }}{{ endsAt(c.ends_at) }}</template
                                    >
                                    <template v-if="c.spend_cap">
                                        · cap {{ spend(c.spend_cap) }}</template
                                    >
                                </p>
                                <p
                                    v-if="c.controllable && can['runs.edit']"
                                    class="mt-1.5 flex flex-wrap items-center gap-1.5 pl-4"
                                >
                                    <KButton
                                        v-if="c.live"
                                        size="sm"
                                        variant="quiet"
                                        :loading="busyId === c.id"
                                        @click="setActive(c, false)"
                                        >{{ t('থামান') }}</KButton
                                    >
                                    <KButton
                                        v-else
                                        size="sm"
                                        :loading="busyId === c.id"
                                        @click="setActive(c, true)"
                                        >{{ t('চালু করুন') }}</KButton
                                    >
                                    <KButton
                                        size="sm"
                                        variant="quiet"
                                        @click="startBudget(c)"
                                        >Budget</KButton
                                    >
                                    <KTag
                                        v-if="c.state === 'paused_client'"
                                        tone="wait"
                                        >{{ t('Client থামিয়েছেন') }}</KTag
                                    >
                                    <KTag
                                        v-else-if="c.state === 'paused_team'"
                                        tone="wait"
                                        >{{ t('Team থামিয়েছে') }}</KTag
                                    >
                                    <KTag
                                        v-else-if="c.state === 'held'"
                                        tone="out"
                                        >{{ t('Account-এ সমস্যা') }}</KTag
                                    >
                                </p>
                                <p
                                    v-if="c.paused_for_credit || c.limits_error"
                                    class="mt-1 flex flex-wrap gap-1.5 pl-4"
                                >
                                    <KTag
                                        v-if="c.paused_for_credit"
                                        tone="out"
                                        >{{ t('টাকা শেষ — বন্ধ') }}</KTag
                                    >
                                    <span
                                        v-if="c.limits_error"
                                        class="text-[12.5px] text-wait"
                                        >{{ c.limits_error }}</span
                                    >
                                </p>
                            </td>
                            <td class="num px-3 py-3 text-right">
                                {{ spend(c.period.spend) }}
                                <span class="block text-[12.5px] text-ink-3"
                                    >{{ c.period.results }} result</span
                                >
                            </td>
                            <td class="num px-3 py-3 text-right">
                                {{ spend(c.spend_total) }}
                            </td>
                            <td class="num px-3 py-3 text-ink-3">
                                {{
                                    c.last_spend_day
                                        ? niceDate(c.last_spend_day, false)
                                        : '—'
                                }}
                            </td>
                            <td class="px-5 py-3">
                                <div
                                    v-if="c.client_id"
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span>
                                        <span class="font-semibold">{{
                                            c.client
                                        }}</span>
                                        <span
                                            class="block text-[12.5px] whitespace-nowrap text-ink-3"
                                            >{{
                                                t('{date} থেকে', {
                                                    date: niceDate(
                                                        c.bill_from ?? '',
                                                        false,
                                                    ),
                                                })
                                            }}</span
                                        >
                                    </span>
                                    <span class="flex items-center gap-1">
                                        <Link
                                            v-if="c.renewal"
                                            :href="`/renewals/${c.renewal.id}`"
                                            class="rounded-md px-2 py-1 text-[13px] font-semibold text-brand hover:bg-brand-soft"
                                            >Renewal</Link
                                        >
                                        <KButton
                                            v-if="
                                                c.guarded && can['clients.edit']
                                            "
                                            size="sm"
                                            icon="renew"
                                            @click="startExtend(c)"
                                            >Extend</KButton
                                        >
                                        <KButton
                                            v-if="can['clients.edit']"
                                            size="sm"
                                            variant="plain"
                                            icon="pen"
                                            @click="startLink(c)"
                                            >{{ t('Client বদলান') }}</KButton
                                        >
                                    </span>
                                </div>
                                <div
                                    v-else-if="can['clients.edit']"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <KButton
                                        v-if="c.suggested"
                                        size="sm"
                                        @click="startLink(c, c.suggested.id)"
                                        >{{ c.suggested.name }} ?</KButton
                                    >
                                    <KButton
                                        size="sm"
                                        :variant="
                                            c.suggested ? 'plain' : 'primary'
                                        "
                                        icon="plus"
                                        @click="startLink(c)"
                                        >{{ t('Client বাছুন') }}</KButton
                                    >
                                </div>
                                <KTag v-else tone="wait">{{
                                    t('Client বাছা হয়নি')
                                }}</KTag>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <KPager :page="campaigns" :sizes="[10, 25, 50, 100]" />
        </section>
    </template>

    <KModal v-model="linkOpen" :title="editing?.name ?? 'Campaign'">
        <div class="grid grid-cols-1 gap-4">
            <KField label="Client">
                <KSelect
                    v-model="linkClient"
                    :options="options.clients"
                    :placeholder="t('কোন client-এর?')"
                />
            </KField>
            <KField
                :label="t('কোন দিন থেকে খরচ এই client-এর হিসাবে যাবে')"
                optional
                :hint="
                    t(
                        'ফাঁকা রাখলে campaign শুরুর দিন থেকে ({start}) — আগের খরচ হাতে লেখা থাকলে তার পরের দিন থেকে, যাতে দুইবার যোগ না হয়।',
                        {
                            start: editing?.start
                                ? niceDate(editing.start, false)
                                : '—',
                        },
                    )
                "
            >
                <KDate
                    v-model="linkFrom"
                    :max="today()"
                    clearable
                    :placeholder="t('Campaign শুরুর দিন থেকে')"
                />
            </KField>
        </div>
        <template #footer>
            <KButton
                v-if="editing?.client_id"
                variant="quiet"
                class="mr-auto"
                @click="unlink"
                >{{ t('যুক্ত সরান') }}</KButton
            >
            <KButton variant="quiet" @click="linkOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                :loading="saving"
                :disabled="!linkClient"
                @click="saveLink"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <KModal v-model="extendOpen" :title="extending?.name ?? 'Campaign'">
        <p class="text-[14.5px] text-ink-2">
            {{
                t(
                    'Campaign আরও কত দিন চলবে? শেষের তারিখ Meta-তে বাড়বে; টাকা শেষ হয়ে বন্ধ থাকলে আবার চালু হবে। Client-এর টাকা খাতায় না থাকলে spend cap আবার থামিয়ে দেবে।',
                )
            }}
        </p>
        <KField
            :label="t('কত দিন')"
            :error="extendError ?? undefined"
            class="mt-4"
            :hint="
                extending?.stop_at
                    ? t('এখন শেষ {date}', {
                          date: niceDate(extending.stop_at, false),
                      })
                    : undefined
            "
        >
            <KInput
                v-model="extendDays"
                inputmode="numeric"
                class="max-w-[140px]"
            >
                <template #trail
                    ><span class="text-[13.5px] text-ink-3">{{
                        t('দিন')
                    }}</span></template
                >
            </KInput>
        </KField>
        <template #footer>
            <KButton variant="quiet" @click="extendOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                icon="renew"
                :loading="saving"
                @click="extend"
                >Extend</KButton
            >
        </template>
    </KModal>

    <KModal v-model="budgetOpen" :title="budgeting?.name ?? 'Campaign'">
        <p class="text-[14.5px] text-ink-2">
            {{
                t(
                    'দিনে কত খরচ হবে, Meta-তে বসবে। Client-এর টাকার বাইরে গেলে spend cap আগেই থামিয়ে দেবে।',
                )
            }}
        </p>
        <KField
            :label="t('দৈনিক budget')"
            :error="budgetError ?? undefined"
            class="mt-4"
            :hint="
                budgeting?.daily_budget
                    ? t('এখন {amount}', {
                          amount: spend(budgeting.daily_budget),
                      })
                    : undefined
            "
        >
            <KInput
                v-model="budgetValue"
                inputmode="decimal"
                prefix="$"
                class="max-w-[160px]"
            />
        </KField>
        <template #footer>
            <KButton variant="quiet" @click="budgetOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton variant="primary" :loading="saving" @click="saveBudget">{{
                t('Budget বদলান')
            }}</KButton>
        </template>
    </KModal>

    <KModal v-model="bulkOpen" :title="t('নাম দেখে যুক্ত করুন')">
        <p class="text-[14.5px] text-ink-2">
            {{
                t(
                    'যে campaign-এর নামে ঠিক একজন client-এর নাম আছে, সেগুলো সেই client-এর সাথে যুক্ত হবে।',
                )
            }}
        </p>
        <KField
            :label="t('কোন দিন থেকে খরচ হিসাবে যাবে')"
            optional
            class="mt-4"
            :hint="t('ফাঁকা রাখলে প্রতিটা campaign তার নিজের শুরুর দিন থেকে।')"
        >
            <KDate
                v-model="bulkFrom"
                :max="today()"
                clearable
                :placeholder="t('Campaign শুরুর দিন থেকে')"
            />
        </KField>
        <template #footer>
            <KButton variant="quiet" @click="bulkOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton variant="primary" @click="linkSuggested">{{
                t('যুক্ত করুন')
            }}</KButton>
        </template>
    </KModal>
</template>
