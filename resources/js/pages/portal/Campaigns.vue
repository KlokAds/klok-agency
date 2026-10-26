<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RangePicker from '@/components/portal/RangePicker.vue';
import CampaignState from '@/components/portal/CampaignState.vue';
import type { CampaignStateKey } from '@/components/portal/CampaignState.vue';
import KTag from '@/components/k/KTag.vue';
import SpendChart from '@/components/portal/SpendChart.vue';
import { niceDate, money } from '@/lib/format';
import { t } from '@/lib/i18n';

type Totals = {
    spend: number;
    results: number;
    clicks: number;
    cpr: number | null;
};
type Row = Totals & {
    id: number;
    slug?: string | null;
    name: string;
    live: boolean;
    paused: boolean;
    ended: boolean;
    state: CampaignStateKey;
    stops: string | null;
    days_left: number | null;
    currency: string;
    result_name: string;
};

const props = defineProps<{
    range: { key: string; from: string; to: string };
    totals: Totals;
    series: { day: string; spend: number; results: number }[];
    campaigns: Row[];
    rounds?: {
        date: string;
        bdt: number;
        usd: number;
        spent: number;
        left: number;
        from: string | null;
        to: string | null;
        state: 'running' | 'used' | 'waiting' | 'owed';
    }[];
    other_spend?: {
        date: string | null;
        platform: string | null;
        description: string;
        usd: number;
        due: boolean;
    }[];
    services?: {
        description: string;
        amount: number;
        date: string;
        invoice: string;
        paid: boolean;
        url: string;
    }[];
}>();

const cur = (c: string) => (c === 'BDT' ? 'bdt' : 'usd');

// Running ones on top, the ended and paused below (the server sends them in that order).
const groups = computed(() => [
    { key: 'live', title: 'চলছে', list: props.campaigns.filter((c) => c.live) }, // i18n
    {
        key: 'done',
        title: 'শেষ বা থামানো',
        list: props.campaigns.filter((c) => !c.live),
    }, // i18n
]);

// Meta campaigns, other platforms (Google…), services: one tab each, the ones there are.
const tabs = computed(() =>
    [
        {
            key: 'meta',
            label: t('Meta campaign'),
            count: props.campaigns.length,
        },
        {
            key: 'other',
            label: t('অন্য platform'),
            count: props.other_spend?.length ?? 0,
        },
        {
            key: 'services',
            label: t('সেবা'),
            count: props.services?.length ?? 0,
        },
    ].filter((tab) => tab.key === 'meta' || tab.count > 0),
);
const tab = ref('meta');

// All the same kind (all sales, all messages): say so; mixed is plain "Result".
const totalName = computed(() => {
    const names = [...new Set(props.campaigns.map((c) => c.result_name))];
    return names.length === 1 ? names[0] : 'Result';
});
</script>

<template>
    <Head title="Campaign" />

    <h1 class="text-[24px] font-bold">{{ t('Campaign-এর ফল') }}</h1>
    <p class="mt-1 text-[14.5px] text-ink-3">
        {{
            range.from === range.to ? range.from : `${range.from} – ${range.to}`
        }}
    </p>

    <!-- What the client is billed for, a tab each: Meta, other platforms, services. -->
    <div
        v-if="tabs.length > 1"
        role="tablist"
        class="mt-4 flex gap-1 overflow-x-auto rounded-2xl bg-sunken p-1"
    >
        <button
            v-for="x in tabs"
            :key="x.key"
            type="button"
            role="tab"
            :aria-selected="tab === x.key"
            class="flex h-10 flex-1 shrink-0 items-center justify-center gap-1.5 rounded-xl px-3 text-[14px] font-semibold whitespace-nowrap transition-colors"
            :class="
                tab === x.key
                    ? 'bg-surface text-ink shadow-[0_1px_3px_rgb(0_0_0/0.1)]'
                    : 'text-ink-3 hover:text-ink'
            "
            @click="tab = x.key"
        >
            {{ x.label }}
            <span
                class="rounded-full px-1.5 text-[12px]"
                :class="tab === x.key ? 'bg-brand-soft text-brand' : 'bg-line'"
                >{{ x.count }}</span
            >
        </button>
    </div>

    <template v-if="tab === 'meta'">
        <div class="mt-4">
            <RangePicker url="/portal/campaigns" :range="range" />
        </div>

        <section class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="panel p-4">
                <p class="text-[13.5px] text-ink-3">
                    {{ t('Meta-র হিসাবে খরচ') }}
                </p>
                <p class="num mt-1 text-[22px] font-bold">
                    {{ money(totals.spend, 'usd') }}
                </p>
            </div>
            <div class="panel p-4">
                <p class="text-[13.5px] text-ink-3">{{ totalName }}</p>
                <p class="num mt-1 text-[22px] font-bold">
                    {{ totals.results }}
                </p>
            </div>
            <div class="panel border-l-[3px] border-l-wait p-4">
                <p class="text-[13.5px] font-medium text-wait">
                    {{ t('প্রতি result-এ') }}
                </p>
                <p class="num mt-1 text-[22px] font-bold">
                    {{
                        totals.cpr !== null
                            ? money(totals.cpr, 'usd')
                            : t('এখনো নেই')
                    }}
                </p>
            </div>
            <div class="panel border-l-[3px] border-l-done p-4">
                <p class="text-[13.5px] font-medium text-done">Click</p>
                <p class="num mt-1 text-[22px] font-bold">
                    {{ totals.clicks }}
                </p>
            </div>
        </section>

        <section v-if="series.length > 1" class="panel mt-4 p-5">
            <SpendChart :series="series" />
        </section>

        <section class="mt-6 grid grid-cols-1 gap-3">
            <p
                v-if="!campaigns.length"
                class="panel p-5 text-[15px] text-ink-3"
            >
                {{ t('এখনো কোনো campaign নেই।') }}
            </p>
            <template v-for="g in groups" :key="g.key">
                <h2
                    v-if="g.list.length"
                    class="mt-2 flex items-center gap-2 text-[16px] font-bold"
                >
                    {{ t(g.title) }}
                    <span
                        class="num rounded-full bg-sunken px-2 py-0.5 text-[12.5px] font-semibold text-ink-2"
                        >{{ g.list.length }}</span
                    >
                </h2>
                <Link
                    v-for="c in g.list"
                    :key="c.id"
                    :href="`/portal/campaigns/${c.slug ?? c.id}?range=${range.key}${range.key === 'custom' ? `&from=${range.from}&to=${range.to}` : ''}`"
                    class="panel block p-4 transition-colors hover:border-brand sm:p-5"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span
                            class="min-w-0 flex-1 truncate text-[16px] font-semibold"
                            >{{ c.name }}</span
                        >
                        <CampaignState :state="c.state" />
                    </span>
                    <span
                        v-if="c.stops"
                        class="mt-1.5 block text-[13.5px] text-ink-3"
                        >{{ c.stops }}</span
                    >
                    <span
                        class="mt-3 grid grid-cols-2 gap-2 text-[14px] sm:grid-cols-4"
                    >
                        <span class="text-ink-3"
                            >{{ t('খরচ') }}
                            <b class="num block text-[16px] text-ink">{{
                                money(c.spend, cur(c.currency))
                            }}</b></span
                        >
                        <span class="text-ink-3"
                            >{{ c.result_name }}
                            <b class="num block text-[16px] text-ink">{{
                                c.results
                            }}</b></span
                        >
                        <span class="text-ink-3"
                            >{{ t('প্রতি result-এ') }}
                            <b class="num block text-[16px] text-ink">{{
                                c.cpr !== null
                                    ? money(c.cpr, cur(c.currency))
                                    : t('এখনো নেই')
                            }}</b></span
                        >
                        <span class="text-ink-3"
                            >Click
                            <b class="num block text-[16px] text-ink">{{
                                c.clicks
                            }}</b></span
                        >
                    </span>
                </Link>
            </template>
        </section>

        <!-- Each payment and what its dollars did: which one is being spent now, what is left. -->
        <section v-if="rounds?.length" class="panel mt-6 overflow-hidden">
            <h2 class="border-b border-line px-5 py-3.5 text-[16px] font-bold">
                {{ t('Payment ধরে হিসাব') }}
            </h2>
            <ul class="divide-y divide-line">
                <li
                    v-for="r in rounds"
                    :key="r.date + r.usd"
                    class="px-5 py-3"
                    :class="r.state === 'used' ? 'opacity-70' : ''"
                >
                    <div class="flex items-center justify-between gap-3">
                        <p class="min-w-0">
                            <span class="num font-semibold">{{
                                niceDate(r.date)
                            }}</span>
                            <span class="num ml-1.5 text-[13.5px] text-ink-3"
                                >{{ money(r.bdt, 'bdt') }} →
                                {{ money(r.usd, 'usd') }}</span
                            >
                        </p>
                        <KTag
                            :tone="
                                r.state === 'running'
                                    ? 'in'
                                    : r.state === 'owed'
                                      ? 'out'
                                      : r.state === 'waiting'
                                        ? 'brand'
                                        : 'neutral'
                            "
                            >{{
                                r.state === 'running'
                                    ? t('এখন এটা থেকে কাটছে')
                                    : r.state === 'owed'
                                      ? t('বেশি খরচ')
                                      : r.state === 'waiting'
                                        ? t('এরপর')
                                        : t('শেষ')
                            }}</KTag
                        >
                    </div>
                    <div
                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-sunken"
                    >
                        <div
                            class="h-full rounded-full"
                            :class="r.left < 0 ? 'bg-out' : 'bg-brand'"
                            :style="{
                                width:
                                    Math.min(
                                        100,
                                        Math.round(
                                            (r.spent / Math.max(1, r.usd)) *
                                                100,
                                        ),
                                    ) + '%',
                            }"
                        />
                    </div>
                    <p
                        class="num mt-1.5 flex flex-wrap gap-x-3 text-[13.5px] text-ink-3"
                    >
                        <span
                            >{{ t('খরচ') }}
                            <b class="text-ink">{{
                                money(r.spent, 'usd')
                            }}</b></span
                        >
                        <span v-if="r.left > 0"
                            >{{ t('বাকি') }}
                            <b class="text-in">{{
                                money(r.left, 'usd')
                            }}</b></span
                        >
                        <span v-else-if="r.left < 0"
                            >{{ t('পাওনা') }}
                            <b class="text-out">{{
                                money(-r.left, 'usd')
                            }}</b></span
                        >
                        <span v-if="r.from"
                            >{{ niceDate(r.from, false)
                            }}<template v-if="r.to && r.to !== r.from">
                                – {{ niceDate(r.to, false) }}</template
                            ></span
                        >
                    </p>
                </li>
            </ul>
        </section>
    </template>

    <!-- Besides the Meta campaigns: ad spend on other platforms, and services. -->
    <section
        v-if="tab === 'other' && other_spend?.length"
        class="panel mt-4 p-5"
    >
        <h2 class="text-[16px] font-bold">
            {{ t('অন্য platform-এ বিজ্ঞাপন') }}
        </h2>
        <p class="mt-0.5 text-[13.5px] text-ink-3">
            {{ t('Google ইত্যাদি — উপরের Meta campaign-এর বাইরে।') }}
        </p>
        <ul class="mt-3 divide-y divide-line">
            <li
                v-for="(o, i) in other_spend"
                :key="i"
                class="flex items-center justify-between gap-3 py-2.5 text-[14.5px]"
            >
                <span class="min-w-0">
                    <span class="block font-medium">{{ o.platform }}</span>
                    <span class="block truncate text-[13px] text-ink-3"
                        >{{ o.date }} · {{ o.description }}</span
                    >
                </span>
                <span class="shrink-0 text-right">
                    <b class="num block">{{ money(o.usd, 'usd') }}</b>
                    <span
                        class="text-[12.5px] font-semibold"
                        :class="o.due ? 'text-wait' : 'text-in'"
                        >{{ o.due ? t('বাকি') : t('পরিশোধ') }}</span
                    >
                </span>
            </li>
        </ul>
    </section>

    <section
        v-if="tab === 'services' && services?.length"
        class="panel mt-4 p-5"
    >
        <h2 class="text-[16px] font-bold">{{ t('সেবা') }}</h2>
        <p class="mt-0.5 text-[13.5px] text-ink-3">
            {{ t('Invoice-এ নেওয়া অন্য কাজ — page, design, setup ইত্যাদি।') }}
        </p>
        <ul class="mt-3 divide-y divide-line">
            <li v-for="(s, i) in services" :key="i">
                <a
                    :href="s.url"
                    class="flex items-center justify-between gap-3 py-2.5 text-[14.5px]"
                >
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{
                            s.description
                        }}</span>
                        <span class="block text-[13px] text-ink-3"
                            >{{ s.date }} · {{ s.invoice }}</span
                        >
                    </span>
                    <span class="shrink-0 text-right">
                        <b class="num block">{{ money(s.amount, 'bdt') }}</b>
                        <span
                            class="text-[12.5px] font-semibold"
                            :class="s.paid ? 'text-in' : 'text-wait'"
                            >{{ s.paid ? t('পরিশোধ') : t('বাকি') }}</span
                        >
                    </span>
                </a>
            </li>
        </ul>
    </section>
</template>
