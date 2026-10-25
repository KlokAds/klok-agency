<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import RangePicker from '@/components/portal/RangePicker.vue';
import SpendChart from '@/components/portal/SpendChart.vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

type Totals = {
    spend: number;
    results: number;
    clicks: number;
    cpr: number | null;
};
type Row = Totals & {
    id: number;
    name: string;
    live: boolean;
    paused: boolean;
    ended: boolean;
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
}>();

const cur = (c: string) => (c === 'BDT' ? 'bdt' : 'usd');

// All the same kind (all sales, all messages): say so; mixed is plain "Result".
const totalName = computed(() => {
    const names = [...new Set(props.campaigns.map((c) => c.result_name))];
    return names.length === 1 ? names[0] : 'Result';
});
</script>

<template>
    <Head title="Campaign" />

    <h1 class="text-[24px] font-bold">{{ t('Campaign-এর ফল') }}</h1>
    <p class="mt-1 text-[13.5px] text-ink-3">
        {{
            range.from === range.to ? range.from : `${range.from} – ${range.to}`
        }}
    </p>

    <div class="mt-4">
        <RangePicker url="/portal/campaigns" :range="range" />
    </div>

    <section class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="panel p-4">
            <p class="text-[12.5px] text-ink-3">{{ t('খরচ') }}</p>
            <p class="num mt-1 text-[22px] font-bold">
                {{ money(totals.spend, 'usd') }}
            </p>
        </div>
        <div class="panel p-4">
            <p class="text-[12.5px] text-ink-3">{{ totalName }}</p>
            <p class="num mt-1 text-[22px] font-bold">{{ totals.results }}</p>
        </div>
        <div class="panel p-4">
            <p class="text-[12.5px] text-ink-3">{{ t('প্রতি result-এ') }}</p>
            <p class="num mt-1 text-[22px] font-bold">
                {{ totals.cpr !== null ? money(totals.cpr, 'usd') : '—' }}
            </p>
        </div>
        <div class="panel p-4">
            <p class="text-[12.5px] text-ink-3">Click</p>
            <p class="num mt-1 text-[22px] font-bold">{{ totals.clicks }}</p>
        </div>
    </section>

    <section v-if="series.length > 1" class="panel mt-4 p-5">
        <SpendChart :series="series" />
    </section>

    <section class="mt-6 grid grid-cols-1 gap-3">
        <p v-if="!campaigns.length" class="panel p-5 text-[14px] text-ink-3">
            {{ t('এই সময়ে কোনো campaign চলেনি।') }}
        </p>
        <Link
            v-for="c in campaigns"
            :key="c.id"
            :href="`/portal/campaigns/${c.id}?range=${range.key}${range.key === 'custom' ? `&from=${range.from}&to=${range.to}` : ''}`"
            class="panel block p-4 transition-colors hover:border-brand sm:p-5"
        >
            <span class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded-full px-2.5 py-0.5 text-[11.5px] font-bold"
                    :class="
                        c.live
                            ? 'bg-in-soft text-in'
                            : c.ended
                              ? 'bg-sunken text-ink-3'
                              : 'bg-wait-soft text-wait'
                    "
                    >{{
                        c.live ? t('চলছে') : c.ended ? t('শেষ') : t('থামানো')
                    }}</span
                >
                <span
                    class="min-w-0 flex-1 truncate text-[15px] font-semibold"
                    >{{ c.name }}</span
                >
            </span>
            <span
                v-if="c.stops"
                class="mt-1.5 block text-[12.5px] text-ink-3"
                >{{ c.stops }}</span
            >
            <span
                class="mt-3 grid grid-cols-2 gap-2 text-[13px] sm:grid-cols-4"
            >
                <span class="text-ink-3"
                    >{{ t('খরচ') }}
                    <b class="num block text-[15px] text-ink">{{
                        money(c.spend, cur(c.currency))
                    }}</b></span
                >
                <span class="text-ink-3"
                    >{{ c.result_name }}
                    <b class="num block text-[15px] text-ink">{{
                        c.results
                    }}</b></span
                >
                <span class="text-ink-3"
                    >{{ t('প্রতি result-এ') }}
                    <b class="num block text-[15px] text-ink">{{
                        c.cpr !== null ? money(c.cpr, cur(c.currency)) : '—'
                    }}</b></span
                >
                <span class="text-ink-3"
                    >Click
                    <b class="num block text-[15px] text-ink">{{
                        c.clicks
                    }}</b></span
                >
            </span>
        </Link>
    </section>
</template>
