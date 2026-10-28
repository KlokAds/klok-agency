<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import StatTiles from '@/components/StatTiles.vue';
import type { StatTile } from '@/components/StatTiles.vue';
import MetaCampaigns from '@/components/dollars/MetaCampaigns.vue';
import type { ForecastClient } from '@/components/dollars/MetaCampaigns.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KSelect from '@/components/k/KSelect.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTag from '@/components/k/KTag.vue';
import { ago, money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { openQuickEntry } from '@/lib/quick-entry';
import type { Option } from '@/types';

type Account = {
    id: number;
    code: string;
    name: string;
    kind: string;
    last4: string | null;
    topup_from_id: number | null;
    is_active: boolean;
    balance: number | null;
};

// A card or bank app the phone saw a dollar move in (NSave, Fasset, PlasmaOne, …).
type MoneyApp = {
    id: number;
    name: string;
    package: string;
    status: 'pending' | 'on' | 'off';
    seen: string | null;
    waiting: number;
    accounts: {
        id: number;
        name: string;
        last4: string | null;
        kind: string;
    }[];
};

// One NSave app notification the phone forwarded, and what the books did with it.
type CardEvent = {
    id: number;
    at: string | null;
    app: string | null;
    kind: string;
    amount: number | null;
    merchant: string | null;
    last4: string | null;
    card: string | null;
    status: string;
    problem: string | null;
    body: string;
};

// Where the dollars are: the card and other USD accounts, what each ad
// platform still has to charge, and the bought dollars left per FX batch.
const props = defineProps<{
    summary: {
        card: number;
        holding: number;
        platform_due: number;
        card_after: number;
        business_usd: number;
        usd_check: {
            bought: number;
            consumed: number;
            remaining: number;
            ok: boolean;
        };
        usd_at_cost: number;
    };
    ad_accounts: {
        id: number;
        name: string;
        currency: string | null;
        since: string;
        spent: number;
        charged: number;
        pending: number;
        campaigns: {
            id: number;
            slug: string | null;
            name: string;
            client: string | null;
            spent: number;
            charged: number;
            pending: number;
        }[];
        charges: {
            id: number;
            date: string | null;
            amount: number;
            card: string | null;
            by_campaign: { name: string; amount: number }[];
        }[];
    }[];
    platforms: {
        id: number;
        name: string;
        spent: number;
        charged: number;
        due: number;
    }[];
    forecast: {
        clients: ForecastClient[];
        pending: number;
        other: number;
        rate: number | null;
    };
    accounts: Account[];
    cards: {
        id: number;
        name: string;
        last4: string | null;
        balance: number;
    }[];
    card_events: CardEvent[];
    money_apps: MoneyApp[];
    batches: {
        id: number;
        number: number;
        date: string | null;
        usd: number;
        bdt: number;
        rate: number;
        left: number;
    }[];
    kinds: Option[];
}>();

const can = computed(() => usePage().props.auth.can);
const kindLabel = (kind: string) =>
    props.kinds.find((k) => k.value === kind)?.label ?? kind;
const holdings = computed(() =>
    props.accounts.filter((a) => a.kind === 'holding' && a.is_active),
);

// The card in four figures: what is on it, what the platforms still take, what stays, and
// what sits in other accounts.
const tiles = computed<StatTile[]>(() => [
    {
        key: 'card',
        label: t('Card-এ এখন'),
        value: money(props.summary.card, 'usd'),
        icon: 'wallet',
        badge: 'bg-white/10 text-white',
        dark: true,
        // With NSave's copy card too: each card's own balance under the total.
        sub:
            props.cards.length > 1
                ? props.cards
                      .map((c) => `${c.name} ${money(c.balance, 'usd')}`)
                      .join(' · ')
                : undefined,
        action: { label: t('Card মেলান'), href: '/reconcile', icon: 'scale' },
    },
    {
        key: 'due',
        label: t('Platform এখনো কাটবে'),
        value: money(props.summary.platform_due, 'usd'),
        icon: 'clock',
        badge: 'bg-wait-soft text-wait',
        text: 'text-wait',
        sub: t('খরচ হয়েছে, card থেকে এখনো কাটেনি'),
    },
    {
        key: 'after',
        label: t('কাটার পরে থাকবে'),
        value: money(props.summary.card_after, 'usd'),
        icon: 'check',
        badge:
            props.summary.card_after < 0
                ? 'bg-out-soft text-out'
                : 'bg-in-soft text-in',
        text: props.summary.card_after < 0 ? 'text-out' : 'text-in',
        sub: t('Card − Platform এখনো কাটবে'),
    },
    {
        key: 'holding',
        label: t('অন্য account-এ'),
        value: money(props.summary.holding, 'usd'),
        icon: 'dollar',
        badge: 'bg-brand-soft text-brand-ink',
        sub: holdings.value.length
            ? holdings.value.map((h) => h.name).join(', ')
            : t('কোনো account নেই'),
    },
]);
const inUse = computed(() => props.batches.find((b) => b.left > 0) ?? null);
const later = computed(() =>
    props.batches.filter((b) => b.left > 0 && b.id !== inUse.value?.id),
);

const accountOpen = ref(false);
const editingAccount = ref<Account | null>(null);
const accountForm = useForm({
    name: '',
    kind: 'platform',
    is_active: true,
    last4: '',
    topup_from_id: null as number | null,
});
const newKinds = computed(() => props.kinds);
// A card needs its last four digits: NSave's notifications and Meta's charges find it by them.
const askDigits = computed(() =>
    editingAccount.value
        ? editingAccount.value.kind === 'card'
        : accountForm.kind === 'card',
);

function startAccount(a?: Account): void {
    editingAccount.value = a ?? null;
    accountForm.clearErrors();
    Object.assign(
        accountForm,
        a
            ? {
                  name: a.name,
                  kind: a.kind,
                  is_active: a.is_active,
                  last4: a.last4 ?? '',
                  topup_from_id: a.topup_from_id,
              }
            : {
                  name: '',
                  kind: 'platform',
                  is_active: true,
                  last4: '',
                  topup_from_id: null,
              },
    );
    accountOpen.value = true;
}

function saveAccount(): void {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (accountOpen.value = false),
    };

    if (editingAccount.value) {
        accountForm
            .transform((d) => ({
                ...d,
                topup_from_id: d.topup_from_id || null,
            }))
            .put(`/dollars/accounts/${editingAccount.value.id}`, opts);
    } else {
        accountForm
            .transform((d) => ({
                ...d,
                topup_from_id: d.topup_from_id || null,
            }))
            .post('/dollars/accounts', opts);
    }
}

// NSave lines: the held ones the Owner puts on a card or sets aside.
const cardOptions = computed<Option[]>(() =>
    props.cards.map((c) => ({
        value: c.id,
        label: c.last4 ? `${c.name} · ${c.last4}` : c.name,
    })),
);
const placeOn = ref<Record<number, number | null>>({});
const held = computed(() =>
    props.card_events.filter((e) => e.status === 'held'),
);
const EVENT_KIND: Record<string, string> = {
    charge: 'খরচ', // i18n
    refund: 'Refund',
    topup: 'টাকা যোগ', // i18n
    declined: 'Declined',
    other: 'অন্য', // i18n
};
const EVENT_STATUS: Record<string, { label: string; tone: string }> = {
    booked: { label: 'খাতায় উঠেছে', tone: 'text-in' }, // i18n
    duplicate: { label: 'আগেই ছিল', tone: 'text-ink-3' }, // i18n
    held: { label: 'মেলাতে হবে', tone: 'text-wait' }, // i18n
    ignored: { label: 'টাকা নড়েনি', tone: 'text-ink-3' }, // i18n
};

// Where money added to an account comes from: another of ours, or new dollars bought.
const NEW_MONEY = 0;
const topupOptions = (except?: number) => [
    { value: NEW_MONEY, label: t('নতুন কেনা dollar — নিজে লিখব') },
    ...props.accounts
        .filter(
            (a) =>
                (a.kind === 'card' || a.kind === 'holding') && a.id !== except,
        )
        .map((a) => ({
            value: a.id,
            label: t('{name} থেকে আসে', { name: a.name }),
        })),
];
const ownAccounts = computed<Option[]>(() =>
    props.accounts
        .filter((a) => a.kind === 'card' || a.kind === 'holding')
        .map((a) => ({
            value: a.id,
            label: a.last4 ? `${a.name} · ${a.last4}` : a.name,
        })),
);
const APP_STATUS: Record<MoneyApp['status'], { label: string; tone: string }> =
    {
        pending: {
            label: 'নতুন — approve বাকি',
            tone: 'bg-wait-soft text-wait',
        }, // i18n
        on: { label: 'চালু', tone: 'bg-in-soft text-in' }, // i18n
        off: { label: 'বন্ধ', tone: 'bg-sunken text-ink-3' }, // i18n
    };

// Approving a new app: a new card or USD account for it, or joined to one already in the books.
const approving = ref<MoneyApp | null>(null);
const approveForm = useForm({
    mode: 'new' as 'new' | 'link',
    name: '',
    kind: 'card' as 'card' | 'holding',
    last4: '',
    account_id: null as number | null,
    topup_from_id: NEW_MONEY as number | null,
});
const approveModes = computed<Option[]>(() => [
    { value: 'new', label: t('নতুন account') },
    { value: 'link', label: t('আগের account-এ যুক্ত') },
]);
const accountKinds = computed<Option[]>(() => [
    { value: 'card', label: 'Card' },
    { value: 'holding', label: t('USD account') },
]);

function startApprove(app: MoneyApp): void {
    approving.value = app;
    approveForm.clearErrors();
    Object.assign(approveForm, {
        mode: 'new',
        name: app.name,
        kind: 'card',
        last4: '',
        account_id: null,
        topup_from_id: NEW_MONEY,
    });
}

function saveApprove(): void {
    if (!approving.value) {
        return;
    }

    approveForm
        .transform((d) => ({
            ...d,
            topup_from_id: d.topup_from_id || null,
            last4: d.last4 || null,
        }))
        .post(`/dollars/money-apps/${approving.value.id}/approve`, {
            preserveScroll: true,
            onSuccess: () => (approving.value = null),
        });
}

function turnApp(app: MoneyApp, on: boolean): void {
    router.post(
        `/dollars/money-apps/${app.id}/turn`,
        { on },
        { preserveScroll: true },
    );
}

function placeEvent(e: CardEvent): void {
    router.post(
        `/dollars/card-events/${e.id}/place`,
        { card_id: placeOn.value[e.id] ?? props.cards[0]?.id },
        { preserveScroll: true },
    );
}

function dismissEvent(e: CardEvent): void {
    router.post(
        `/dollars/card-events/${e.id}/dismiss`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <SectionTabs section="dollars" />
    <PageHeader
        :eyebrow="t('টাকা')"
        :title="t('Dollar কোথায়')"
        :lead="
            t(
                'Card-এ আর অন্য account-এ কত dollar, কোন platform এখনো কত কাটবে, আর কেনা dollar কোন batch-এ কত বাকি।',
            )
        "
    >
        <div class="flex flex-wrap gap-2">
            <KButton
                v-if="can['fx.create']"
                icon="swap"
                @click="openQuickEntry('buy-usd')"
                >{{ t('USD কিনলাম') }}</KButton
            >
            <KButton
                v-if="can['usd.create']"
                variant="ink"
                icon="wallet"
                @click="openQuickEntry('card-charge')"
                >{{ t('Card থেকে কেটেছে') }}</KButton
            >
        </div>
    </PageHeader>

    <!-- The card -->
    <StatTiles :tiles="tiles" :cols="4" />

    <!-- Check -->
    <div
        class="panel mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 px-5 py-3 text-[14.5px]"
    >
        <span
            class="flex items-center gap-1.5"
            :class="summary.usd_check.ok ? 'text-in' : 'text-out'"
        >
            <KIcon :name="summary.usd_check.ok ? 'check' : 'warn'" :size="14" />
            {{
                summary.usd_check.ok
                    ? t('মিলেছে — কেনা dollar − খরচ = হাতে থাকা dollar')
                    : t('মেলেনি — কোনো USD entry বাদ পড়েছে বা ভুল')
            }}
        </span>
        <span class="text-ink-3"
            >{{ t('কেনা') }}
            <b class="num text-ink">{{
                money(summary.usd_check.bought, 'usd')
            }}</b>
            − {{ t('খরচ') }}
            <b class="num text-ink">{{
                money(summary.usd_check.consumed, 'usd')
            }}</b>
            =
            <b class="num text-ink">{{
                money(summary.usd_check.remaining, 'usd')
            }}</b></span
        >
        <span class="text-ink-3"
            >{{ t('কেনা দামে') }}
            <b class="num text-ink">{{
                money(summary.usd_at_cost, 'bdt')
            }}</b></span
        >
    </div>

    <!-- Card and bank apps the phone reads: approve a new one, turn one on or off -->
    <section v-if="money_apps.length" class="panel mt-6 p-5 sm:p-6">
        <h2 class="flex items-center gap-2 text-[16px] font-bold">
            <KIcon name="wallet" class="text-brand" />{{ t('Card ও bank app') }}
        </h2>
        <p class="mt-1 text-[13.5px] text-ink-3">
            {{
                t(
                    'Phone-এর notification থেকে dollar-এর লেনদেন নিজে খাতায় ওঠে। নতুন app এলে approve করুন; যেটা চান না, বন্ধ রাখুন।',
                )
            }}
        </p>
        <ul class="mt-3 grid grid-cols-1 gap-2.5 md:grid-cols-2">
            <li
                v-for="app in money_apps"
                :key="app.id"
                class="rounded-xl border p-3.5"
                :class="
                    app.status === 'pending'
                        ? 'border-wait/40 bg-wait-soft'
                        : 'border-line bg-sunken'
                "
            >
                <div class="flex items-start gap-3">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand text-[15px] font-bold text-white"
                        >{{ app.name.slice(0, 1).toUpperCase() }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">{{ app.name }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[12.5px] font-semibold"
                                :class="APP_STATUS[app.status].tone"
                                >{{ t(APP_STATUS[app.status].label) }}</span
                            >
                        </p>
                        <p class="mt-0.5 truncate text-[13px] text-ink-3">
                            {{
                                app.accounts.length
                                    ? app.accounts
                                          .map((a) =>
                                              a.last4
                                                  ? `${a.name} · ${a.last4}`
                                                  : a.name,
                                          )
                                          .join(', ')
                                    : app.package
                            }}
                            <template v-if="app.seen">
                                · {{ ago(app.seen) }}</template
                            >
                        </p>
                        <p
                            v-if="app.waiting"
                            class="mt-1 text-[13px] font-semibold text-wait"
                        >
                            {{
                                t('{n}টা লেনদেন অপেক্ষায়', { n: app.waiting })
                            }}
                        </p>
                    </div>
                    <KSwitch
                        v-if="app.status !== 'pending' && can['usd.edit']"
                        :model-value="app.status === 'on'"
                        :label="app.status === 'on' ? t('চালু') : t('বন্ধ')"
                        @update:model-value="(v: boolean) => turnApp(app, v)"
                    />
                </div>
                <div
                    v-if="app.status === 'pending' && can['usd.edit']"
                    class="mt-3 grid grid-cols-2 gap-2"
                >
                    <KButton
                        size="sm"
                        variant="primary"
                        icon="check"
                        @click="startApprove(app)"
                        >{{ t('Approve') }}</KButton
                    >
                    <KButton
                        size="sm"
                        variant="plain"
                        @click="turnApp(app, false)"
                        >{{ t('বন্ধ রাখুন') }}</KButton
                    >
                </div>
            </li>
        </ul>
    </section>

    <!-- Every dollar move read from those apps' notifications -->
    <section v-if="card_events.length" class="panel mt-6 p-5 sm:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="flex items-center gap-2 text-[16px] font-bold">
                <KIcon name="bell" class="text-brand" />{{
                    t('App থেকে আসা লেনদেন')
                }}
            </h2>
            <p class="text-[13.5px] text-ink-3">
                {{
                    held.length
                        ? t('{n}টা মেলাতে হবে', { n: held.length })
                        : t('সব খাতায় উঠেছে')
                }}
            </p>
        </div>
        <ul class="mt-3 divide-y divide-line">
            <li
                v-for="e in card_events"
                :key="e.id"
                class="py-3 text-[14px]"
                :class="
                    e.status === 'held' && '-mx-3 rounded-xl bg-wait-soft px-3'
                "
            >
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">
                            {{ t(EVENT_KIND[e.kind] ?? e.kind) }}
                            <template v-if="e.merchant">
                                · {{ e.merchant }}</template
                            >
                            <span
                                v-if="e.app"
                                class="ml-1 text-[13px] font-normal text-ink-3"
                                >{{ e.app }}</span
                            >
                        </p>
                        <p class="mt-0.5 text-[13px] text-ink-3">
                            {{ e.at ? ago(e.at) : '' }}
                            <template v-if="e.card || e.last4">
                                · {{ e.card ?? 'card ' + e.last4 }}</template
                            >
                            ·
                            <span
                                :class="EVENT_STATUS[e.status]?.tone"
                                class="font-semibold"
                                >{{
                                    t(EVENT_STATUS[e.status]?.label ?? e.status)
                                }}</span
                            >
                        </p>
                        <p
                            v-if="e.status === 'held' && e.problem"
                            class="mt-1 text-[13px] text-wait"
                        >
                            {{ e.problem }}
                        </p>
                    </div>
                    <span
                        class="num shrink-0 font-bold"
                        :class="
                            e.kind === 'charge'
                                ? 'text-out'
                                : e.kind === 'refund' || e.kind === 'topup'
                                  ? 'text-in'
                                  : 'text-ink-3'
                        "
                        >{{
                            e.amount !== null
                                ? (e.kind === 'charge' ? '−' : '+') +
                                  money(e.amount, 'usd')
                                : '—'
                        }}</span
                    >
                </div>
                <div
                    v-if="e.status === 'held' && can['usd.create']"
                    class="mt-2.5 grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-2"
                >
                    <KSelect
                        :model-value="placeOn[e.id] ?? cards[0]?.id ?? null"
                        :options="cardOptions"
                        size="sm"
                        @update:model-value="
                            (v: string | number | null | undefined) =>
                                (placeOn[e.id] = v == null ? null : Number(v))
                        "
                    />
                    <KButton
                        size="sm"
                        variant="primary"
                        @click="placeEvent(e)"
                        >{{ t('এই card-এ তুলুন') }}</KButton
                    >
                    <KButton
                        size="sm"
                        variant="plain"
                        @click="dismissEvent(e)"
                        >{{ t('সরান') }}</KButton
                    >
                </div>
            </li>
        </ul>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <!-- FX batches -->
        <section class="panel min-w-0 p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-[16px] font-bold">
                    {{ t('কেনা dollar — batch ধরে') }}
                </h2>
                <KButton href="/fx" size="sm" class="shrink-0"
                    >FX Batch</KButton
                >
            </div>
            <p class="mt-1 text-[13.5px] text-ink-3">
                {{
                    t(
                        'খরচ সবসময় পুরনো batch থেকে কাটে (FIFO) — সেই batch-এর দামে হিসাব হয়।',
                    )
                }}
            </p>
            <p
                v-if="!inUse"
                class="mt-4 rounded-lg bg-out-soft px-3 py-2 text-[14px] text-out"
            >
                {{ t('কোনো batch-এ dollar বাকি নেই — নতুন dollar কিনতে হবে।') }}
            </p>
            <ul class="mt-4 flex flex-col gap-3">
                <li
                    v-for="b in [inUse, ...later].filter((x) => x !== null)"
                    :key="b!.id"
                >
                    <div
                        class="flex items-center justify-between gap-3 text-[14px]"
                    >
                        <span class="flex items-center gap-2">
                            <span class="font-semibold"
                                >Batch {{ b!.number }}</span
                            >
                            <KTag v-if="b!.id === inUse?.id" tone="brand">{{
                                t('চলছে')
                            }}</KTag>
                            <span class="num text-ink-3"
                                >&#2547;{{ b!.rate }}/$</span
                            >
                        </span>
                        <span class="num"
                            >{{ money(b!.left, 'usd') }}
                            <span class="text-ink-3"
                                >/ {{ money(b!.usd, 'usd') }}</span
                            ></span
                        >
                    </div>
                    <div class="mt-1 h-1.5 rounded-full bg-sunken">
                        <div
                            class="h-1.5 rounded-full bg-brand"
                            :style="{
                                width:
                                    (b!.left / Math.max(1, b!.usd)) * 100 + '%',
                            }"
                        />
                    </div>
                    <p v-if="b!.date" class="mt-1 text-[13px] text-ink-3">
                        {{ niceDate(b!.date, false) }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Platforms -->
        <section class="panel min-w-0 overflow-hidden">
            <div class="px-5 pt-5 sm:px-6">
                <h2 class="text-[16px] font-bold">{{ t('Platform ধরে') }}</h2>
                <p class="mt-1 text-[13.5px] text-ink-3">
                    {{
                        t(
                            'কোন platform-এ কত খরচ হয়েছে, card থেকে কত কেটেছে, কত বাকি।',
                        )
                    }}
                </p>
            </div>
            <KEmpty v-if="!platforms.length" :title="t('কোনো platform নেই')" />
            <div v-else class="mt-3 overflow-x-auto">
                <table v-stack class="stack w-full min-w-[440px] text-[14px]">
                    <thead>
                        <tr
                            class="border-b border-line text-left text-[13.5px] font-semibold text-ink-3"
                        >
                            <th class="px-5 py-2.5 sm:px-6">Platform</th>
                            <th class="px-3 py-2.5 text-right">
                                {{ t('খরচ') }}
                            </th>
                            <th class="px-3 py-2.5 text-right">
                                {{ t('কেটেছে') }}
                            </th>
                            <th class="px-5 py-2.5 text-right sm:px-6">
                                {{ t('বাকি') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in platforms"
                            :key="p.id"
                            class="border-b border-line last:border-0"
                        >
                            <td class="px-5 py-2.5 font-medium sm:px-6">
                                {{ p.name }}
                            </td>
                            <td class="num px-3 py-2.5 text-right">
                                {{ money(p.spent, 'usd') }}
                            </td>
                            <td class="num px-3 py-2.5 text-right">
                                {{ money(p.charged, 'usd') }}
                            </td>
                            <td
                                class="num px-5 py-2.5 text-right font-semibold sm:px-6"
                                :class="p.due > 0 ? 'text-wait' : 'text-ink-3'"
                            >
                                {{ money(p.due, 'usd') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel min-w-0 p-5 sm:p-6 xl:col-span-2">
            <h2 class="text-[16px] font-bold">{{ t('Ad account ধরে') }}</h2>
            <p class="mt-1 text-[13.5px] text-ink-3">
                {{
                    t(
                        'Meta যা খরচ গুনেছে, card থেকে যা কেটেছে, আর যা এখনো কাটবে — account আর campaign ধরে। প্রতিটা charge কোন campaign-এর টাকা, তা-ও।',
                    )
                }}
            </p>
            <KEmpty
                v-if="!ad_accounts.length"
                :title="t('এখনো কোনো ad account-এর খরচ নেই')"
                class="mt-3"
            />
            <div v-else class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <article
                    v-for="a in ad_accounts"
                    :key="a.id"
                    class="rounded-xl border border-line"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4"
                    >
                        <h3 class="font-bold">
                            {{ a.name }}
                            <span
                                class="ml-1 text-[13px] font-medium text-ink-3"
                                >{{ a.currency }}</span
                            >
                        </h3>
                        <span class="num text-[13px] text-ink-3">{{
                            t('{date} থেকে', { date: niceDate(a.since) })
                        }}</span>
                    </div>
                    <div
                        class="mt-3 grid grid-cols-3 divide-x divide-line border-y border-line bg-sunken/60 py-2.5 text-center"
                    >
                        <p>
                            <span class="block text-[13px] text-ink-3">{{
                                t('Meta গুনেছে')
                            }}</span>
                            <b class="num text-[15px]">{{
                                money(a.spent, 'usd')
                            }}</b>
                        </p>
                        <p>
                            <span class="block text-[13px] text-in">{{
                                t('Card থেকে কেটেছে')
                            }}</span>
                            <b class="num text-[15px] text-in">{{
                                money(a.charged, 'usd')
                            }}</b>
                        </p>
                        <p>
                            <span class="block text-[13px] text-wait">{{
                                t('এখনো কাটবে')
                            }}</span>
                            <b class="num text-[15px] text-wait">{{
                                money(a.pending, 'usd')
                            }}</b>
                        </p>
                    </div>
                    <ul class="divide-y divide-line">
                        <li
                            v-for="c in a.campaigns"
                            :key="c.id"
                            class="flex items-center gap-3 px-4 py-2 text-[13.5px]"
                        >
                            <span class="min-w-0 flex-1">
                                <Link
                                    :href="`/campaigns/${c.slug ?? c.id}`"
                                    class="block truncate font-medium hover:text-brand"
                                    >{{ c.name }}</Link
                                >
                                <span
                                    v-if="c.client"
                                    class="block truncate text-[13px] text-ink-3"
                                    >{{ c.client }}</span
                                >
                            </span>
                            <span class="num w-16 shrink-0 text-right">{{
                                money(c.spent, 'usd')
                            }}</span>
                            <span
                                class="num w-16 shrink-0 text-right text-in"
                                >{{ money(c.charged, 'usd') }}</span
                            >
                            <span
                                class="num w-16 shrink-0 text-right text-wait"
                                >{{ money(c.pending, 'usd') }}</span
                            >
                        </li>
                    </ul>
                    <details
                        v-if="a.charges.length"
                        class="border-t border-line px-4 py-2.5 text-[13.5px]"
                    >
                        <summary
                            class="cursor-pointer font-semibold text-ink-2"
                        >
                            {{
                                t('{n}টা charge — কোন campaign-এর', {
                                    n: a.charges.length,
                                })
                            }}
                        </summary>
                        <ul class="mt-2 space-y-2">
                            <li v-for="ch in a.charges" :key="ch.id">
                                <p
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="num text-ink-3"
                                        >{{ niceDate(ch.date)
                                        }}<template v-if="ch.card">
                                            · card {{ ch.card }}</template
                                        ></span
                                    >
                                    <b class="num">{{
                                        money(ch.amount, 'usd')
                                    }}</b>
                                </p>
                                <p
                                    v-if="ch.by_campaign.length"
                                    class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[13px] text-ink-3"
                                >
                                    <span
                                        v-for="(b, i) in ch.by_campaign"
                                        :key="i"
                                        >{{ b.name }}
                                        <b class="num text-ink">{{
                                            money(b.amount, 'usd')
                                        }}</b></span
                                    >
                                </p>
                                <p v-else class="mt-0.5 text-[13px] text-ink-3">
                                    {{ t('ভাগ এখনো হয়নি') }}
                                </p>
                            </li>
                        </ul>
                    </details>
                </article>
            </div>
        </section>

        <MetaCampaigns :forecast="forecast" />

        <!-- USD accounts -->
        <section class="panel min-w-0 p-5 sm:p-6 xl:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-[16px] font-bold">
                        {{ t('USD account ও platform') }}
                    </h2>
                    <p class="mt-1 text-[13.5px] text-ink-3">
                        {{
                            t(
                                'Card, dollar রাখার অন্য account, আর ad platform।',
                            )
                        }}
                    </p>
                </div>
                <KButton
                    v-if="can['usd.edit']"
                    size="sm"
                    icon="plus"
                    @click="startAccount()"
                    >{{ t('যোগ') }}</KButton
                >
            </div>
            <ul class="mt-3 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                <li
                    v-for="a in accounts"
                    :key="a.id"
                    class="flex items-center gap-3 border-b border-line py-2.5 text-[15px]"
                >
                    <span
                        class="min-w-0 flex-1 truncate font-medium"
                        :class="!a.is_active && 'text-ink-3 line-through'"
                        >{{ a.name
                        }}<span
                            v-if="a.last4"
                            class="num ml-1.5 text-[13px] font-normal text-ink-3"
                            >· {{ a.last4 }}</span
                        ></span
                    >
                    <span v-if="a.balance !== null" class="num text-[14px]">{{
                        money(a.balance, 'usd')
                    }}</span>
                    <KTag>{{ kindLabel(a.kind) }}</KTag>
                    <button
                        v-if="can['usd.edit']"
                        type="button"
                        class="flex h-11 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg border border-brand/30 bg-brand-soft px-1.5 text-brand-ink transition-colors hover:border-brand"
                        @click="startAccount(a)"
                    >
                        <KIcon name="pen" :size="15" />
                        <span class="text-[13px] leading-none">{{
                            t('বদলান')
                        }}</span>
                    </button>
                </li>
            </ul>
        </section>
    </div>

    <KModal
        v-model="accountOpen"
        width="sm"
        :title="editingAccount ? editingAccount.name : t('নতুন account')"
    >
        <form
            id="acct-form"
            class="flex flex-col gap-4"
            @submit.prevent="saveAccount"
        >
            <KField :label="t('নাম')" :error="accountForm.errors.name"
                ><KInput
                    v-model="accountForm.name"
                    :placeholder="t('যেমন LinkedIn Ads')"
            /></KField>
            <KField v-if="!editingAccount" :label="t('ধরন')"
                ><KSegment v-model="accountForm.kind" :options="newKinds"
            /></KField>
            <KField
                v-if="askDigits"
                :label="t('Card-এর শেষ ৪ সংখ্যা')"
                :hint="
                    t(
                        'NSave-এর notification আর Meta-র charge এই সংখ্যা দেখে ঠিক card-এ ওঠে।',
                    )
                "
                :error="accountForm.errors.last4"
                ><KInput
                    v-model="accountForm.last4"
                    inputmode="numeric"
                    :maxlength="4"
                    placeholder="1234"
            /></KField>
            <KField
                v-if="
                    editingAccount
                        ? editingAccount.kind !== 'platform'
                        : accountForm.kind !== 'platform'
                "
                :label="t('টাকা যোগ হলে কোথা থেকে আসে')"
                :hint="
                    t(
                        'যেমন NSave copy-তে টাকা main card থেকে আসে। নতুন কেনা হলে আপনি taka সহ লিখবেন।',
                    )
                "
                ><KSelect
                    :model-value="accountForm.topup_from_id ?? NEW_MONEY"
                    :options="topupOptions(editingAccount?.id)"
                    @update:model-value="
                        (v: string | number | null | undefined) =>
                            (accountForm.topup_from_id = v ? Number(v) : null)
                    "
            /></KField>
            <KSwitch
                v-if="editingAccount && editingAccount.kind !== 'card'"
                v-model="accountForm.is_active"
                :label="t('চালু')"
            />
        </form>
        <template #footer>
            <KButton variant="quiet" @click="accountOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="acct-form"
                variant="primary"
                :loading="accountForm.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <KModal
        :model-value="approving !== null"
        width="sm"
        :title="t('{app} approve', { app: approving?.name ?? '' })"
        :subtitle="t('এর notification-এর dollar-এর লেনদেন কোন account-এ উঠবে।')"
        @update:model-value="(v: boolean) => !v && (approving = null)"
    >
        <form
            id="approve-form"
            class="flex flex-col gap-4"
            @submit.prevent="saveApprove"
        >
            <KSegment v-model="approveForm.mode" :options="approveModes" />
            <template v-if="approveForm.mode === 'new'">
                <KField :label="t('নাম')" :error="approveForm.errors.name"
                    ><KInput v-model="approveForm.name"
                /></KField>
                <KField :label="t('ধরন')"
                    ><KSegment
                        v-model="approveForm.kind"
                        :options="accountKinds"
                /></KField>
            </template>
            <KField
                v-else
                :label="t('কোন account')"
                :error="approveForm.errors.account_id"
                ><KSelect
                    v-model="approveForm.account_id"
                    :options="ownAccounts"
                    :placeholder="t('বাছুন')"
            /></KField>
            <KField
                v-if="
                    approveForm.mode === 'link' || approveForm.kind === 'card'
                "
                :label="t('Card-এর শেষ ৪ সংখ্যা')"
                :error="approveForm.errors.last4"
                ><KInput
                    v-model="approveForm.last4"
                    inputmode="numeric"
                    :maxlength="4"
                    placeholder="1234"
            /></KField>
            <KField
                v-if="approveForm.mode === 'new'"
                :label="t('টাকা যোগ হলে কোথা থেকে আসে')"
                ><KSelect
                    :model-value="approveForm.topup_from_id ?? NEW_MONEY"
                    :options="topupOptions()"
                    @update:model-value="
                        (v: string | number | null | undefined) =>
                            (approveForm.topup_from_id = v ? Number(v) : null)
                    "
            /></KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="approving = null">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="approve-form"
                variant="primary"
                :loading="approveForm.processing"
                >{{ t('Approve') }}</KButton
            >
        </template>
    </KModal>
</template>
