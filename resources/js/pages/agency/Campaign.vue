<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KTag from '@/components/k/KTag.vue';
import CampaignState from '@/components/portal/CampaignState.vue';
import type { CampaignStateKey } from '@/components/portal/CampaignState.vue';
import RangePicker from '@/components/portal/RangePicker.vue';
import DayRows from '@/components/portal/DayRows.vue';
import SpendChart from '@/components/portal/SpendChart.vue';
import { ago, money } from '@/lib/format';
import { t } from '@/lib/i18n';

// One campaign for the team: what it spent and brought over a period, day by day — the same
// figures its client sees, read from Klok Pilot.
const props = defineProps<{
    campaign: {
        id: number;
        slug?: string | null;
        name: string;
        account: string | null;
        client: { id: number; name: string } | null;
        live: boolean;
        ended: boolean;
        state: CampaignStateKey | null;
        stops: string | null;
        started: string | null;
        budget: number | null;
        spend_cap: number | null;
        currency: string;
        result_name: string;
        bill_from: string | null;
        spend_total: number;
    };
    range: { key: string; from: string; to: string };
    totals: {
        spend: number;
        results: number;
        clicks: number;
        cpr: number | null;
    };
    series: { day: string; spend: number; results: number }[];
    days: {
        day: string;
        spend: number;
        results: number;
        clicks: number;
        cpr: number | null;
    }[];
    synced_at: string | null;
}>();

const cur = computed(() => (props.campaign.currency === 'BDT' ? 'bdt' : 'usd'));
</script>

<template>
    <Head :title="campaign.name" />

    <KButton href="/campaigns" size="sm" variant="plain" icon="back">{{
        t('সব campaign')
    }}</KButton>

    <div class="mt-3 flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-[22px] leading-snug font-bold">
                {{ campaign.name }}
            </h1>
            <p class="mt-1 text-[14px] text-ink-3">
                {{ campaign.account }}
                <template v-if="campaign.started">
                    ·
                    {{ t('শুরু {date}', { date: campaign.started }) }}</template
                >
                <template v-if="synced_at">
                    · {{ t('খরচ মেলানো') }} {{ ago(synced_at) }}</template
                >
            </p>
        </div>
        <CampaignState
            v-if="campaign.state"
            :state="campaign.state"
            size="md"
        />
        <KTag v-else :tone="campaign.live ? 'in' : 'wait'">{{
            campaign.live
                ? t('চালু')
                : campaign.ended
                  ? t('শেষ হয়েছে')
                  : t('বন্ধ')
        }}</KTag>
    </div>

    <section
        class="panel mt-4 grid grid-cols-2 gap-3 p-4 text-[14px] sm:grid-cols-4 sm:p-5"
    >
        <p>
            <span class="block text-ink-3">Client</span>
            <Link
                v-if="campaign.client"
                :href="`/clients/${campaign.client.id}`"
                class="font-semibold text-brand hover:underline"
                >{{ campaign.client.name }}</Link
            >
            <b v-else class="text-ink-3">{{ t('Client বাছা হয়নি') }}</b>
            <span
                v-if="campaign.bill_from"
                class="block text-[13px] text-ink-3"
                >{{ t('{date} থেকে', { date: campaign.bill_from }) }}</span
            >
        </p>
        <p>
            <span class="block text-ink-3">{{ t('কবে বন্ধ হবে') }}</span>
            <b>{{ campaign.stops ?? t('শেষের তারিখ নেই') }}</b>
        </p>
        <p>
            <span class="block text-ink-3">{{ t('দিনের budget') }}</span>
            <b class="num">{{
                campaign.budget ? money(campaign.budget, cur) : t('ঠিক করা নেই')
            }}</b>
            <span v-if="campaign.spend_cap" class="block text-[13px] text-ink-3"
                >cap {{ money(campaign.spend_cap, cur) }}</span
            >
        </p>
        <p>
            <span class="block text-ink-3">{{ t('মোট খরচ') }}</span>
            <b class="num">{{ money(campaign.spend_total, cur) }}</b>
        </p>
    </section>

    <div class="mt-5">
        <RangePicker
            :url="`/campaigns/${campaign.slug ?? campaign.id}`"
            :range="range"
        />
    </div>

    <section class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="panel border-l-[3px] border-l-brand p-4">
            <p class="text-[13.5px] font-medium text-brand-ink">
                {{ t('খরচ') }}
            </p>
            <p class="num mt-1 text-[22px] font-bold">
                {{ money(totals.spend, cur) }}
            </p>
        </div>
        <div class="panel border-l-[3px] border-l-in p-4">
            <p class="text-[13.5px] font-medium text-in">
                {{ campaign.result_name }}
            </p>
            <p class="num mt-1 text-[22px] font-bold">{{ totals.results }}</p>
        </div>
        <div class="panel border-l-[3px] border-l-wait p-4">
            <p class="text-[13.5px] font-medium text-wait">
                {{ t('প্রতি result-এ') }}
            </p>
            <p class="num mt-1 text-[22px] font-bold">
                {{
                    totals.cpr !== null ? money(totals.cpr, cur) : t('এখনো নেই')
                }}
            </p>
        </div>
        <div class="panel border-l-[3px] border-l-done p-4">
            <p class="text-[13.5px] font-medium text-done">Click</p>
            <p class="num mt-1 text-[22px] font-bold">{{ totals.clicks }}</p>
        </div>
    </section>

    <section v-if="series.length > 1" class="panel mt-4 p-5">
        <SpendChart :series="series" :currency="campaign.currency" />
    </section>

    <section class="panel mt-4 overflow-hidden">
        <h2 class="border-b border-line px-5 py-3.5 text-[16px] font-bold">
            {{ t('দিনে দিনে') }}
        </h2>
        <p v-if="!days.length" class="px-5 py-4 text-[14.5px] text-ink-3">
            {{
                range.key === 'today'
                    ? t(
                          'আজকের হিসাব Meta থেকে এখনো আসেনি — Meta দিনের খরচ কিছুক্ষণ পর পর জানায়; গতকাল বা ৭ দিন দেখুন।',
                      )
                    : t('এই সময়ে কোনো খরচ হয়নি।')
            }}
        </p>
        <DayRows
            v-else
            :days="days"
            :currency="campaign.currency"
            :result-name="campaign.result_name"
        />
    </section>
</template>
