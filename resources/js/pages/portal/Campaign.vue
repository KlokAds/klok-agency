<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import KButton from '@/components/k/KButton.vue';
import { confirmAction } from '@/lib/feedback';
import CampaignControl from '@/components/portal/CampaignControl.vue';
import type { Control } from '@/components/portal/CampaignControl.vue';
import CampaignState from '@/components/portal/CampaignState.vue';
import type { CampaignStateKey } from '@/components/portal/CampaignState.vue';
import RangePicker from '@/components/portal/RangePicker.vue';
import DayRows from '@/components/portal/DayRows.vue';
import SpendChart from '@/components/portal/SpendChart.vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

type Totals = {
    spend: number;
    results: number;
    clicks: number;
    cpr: number | null;
};

const props = defineProps<{
    campaign: {
        id: number;
        slug?: string | null;
        name: string;
        live: boolean;
        ended: boolean;
        state: CampaignStateKey;
        stops: string | null;
        days_left: number | null;
        budget: number | null;
        currency: string;
        started: string | null;
        result_name: string;
    };
    range: { key: string; from: string; to: string };
    totals: Totals;
    series: { day: string; spend: number; results: number }[];
    days: {
        day: string;
        spend: number;
        results: number;
        clicks: number;
        cpr: number | null;
    }[];
    extensions: {
        days: number | null;
        until: string | null;
        at: string | null;
    }[];
    control?: Control | null;
    renew?: {
        budget: number;
        rate: number | null;
        status: string | null;
        days: number | null;
        invoice_url: string | null;
        invoice_due: number | null;
    } | null;
}>();

const cur = computed(() => (props.campaign.currency === 'BDT' ? 'bdt' : 'usd'));

// Keep it running: so many more days, priced at the client's rate, paid on the invoice.
const renewDays = ref(7);
const choices = [7, 15, 30];
const price = computed(() =>
    props.renew?.rate
        ? Math.round(
              (props.renew.budget * renewDays.value * props.renew.rate) / 100,
          ) * 100
        : null,
);
const busy = ref(false);
const error = ref<string | null>(null);

function renewNow(): void {
    busy.value = true;
    error.value = null;
    router.post(
        `/portal/campaigns/${props.campaign.id}/renew`,
        { days: renewDays.value },
        {
            onError: (e) =>
                (error.value = e.days ?? t('হলো না — আবার চেষ্টা করুন।')),
            onFinish: () => (busy.value = false),
        },
    );
}

async function stopIt(): Promise<void> {
    if (
        !(await confirmAction({
            title: t('নিশ্চিতভাবে campaign বন্ধ করবেন?'),
            body: t(
                '{stops} — আর বাড়ানো হবে না, বাকি invoice বাতিল হবে। নিশ্চিত হলে নিচে চাপুন; আপনাকে email-এও জানানো হবে। পরে মত বদলালে এখান থেকেই আবার দিন বেছে নিতে পারবেন।',
                {
                    stops: props.campaign.stops ?? '',
                },
            ),
            confirm: t('হ্যাঁ, থামবে'),
            danger: true,
        }))
    ) {
        return;
    }
    router.post(
        `/portal/campaigns/${props.campaign.id}/stop`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="campaign.name" />

    <KButton size="sm" variant="plain" icon="back" href="/portal/campaigns">{{
        t('সব campaign')
    }}</KButton>
    <h1 class="mt-3 text-[22px] leading-snug font-bold">{{ campaign.name }}</h1>

    <section
        class="panel mt-4 grid grid-cols-1 gap-3 p-5 text-[14.5px] sm:grid-cols-3"
    >
        <p>
            <span class="block text-ink-3">{{ t('অবস্থা') }}</span>
            <CampaignState :state="campaign.state" size="md" class="mt-1" />
        </p>
        <p>
            <span class="block text-ink-3">{{ t('কবে বন্ধ হবে') }}</span>
            <b>{{ campaign.stops ?? t('শেষের তারিখ নেই') }}</b>
            <span
                v-if="campaign.live && campaign.days_left !== null"
                class="block text-[13.5px] text-ink-3"
                >{{ t('আর {n} দিন', { n: campaign.days_left }) }}</span
            >
        </p>
        <p>
            <span class="block text-ink-3">{{ t('দিনের budget') }}</span>
            <b class="num">{{
                campaign.budget ? money(campaign.budget, cur) : t('ঠিক করা নেই')
            }}</b>
            <span
                v-if="campaign.started"
                class="block text-[13.5px] text-ink-3"
                >{{ t('শুরু {date}', { date: campaign.started }) }}</span
            >
        </p>
    </section>

    <CampaignControl
        v-if="control"
        :campaign-id="campaign.id"
        :control="control"
    />

    <!-- Keep it going, or not: the client decides here. -->
    <section v-if="renew" class="panel mt-4 p-5">
        <h2 class="text-[16px] font-bold">{{ t('Campaign চালু রাখবেন?') }}</h2>

        <template v-if="renew.status === 'invoiced' && renew.invoice_url">
            <p class="mt-2 text-[15px] text-ink-2">
                {{
                    t(
                        '{days} দিনের invoice তৈরি আছে — pay করলেই campaign নিজে বাড়বে।',
                        {
                            days: renew.days ?? '',
                        },
                    )
                }}
            </p>
            <KButton
                variant="primary"
                size="lg"
                block
                external
                :href="renew.invoice_url"
                class="mt-3"
                >{{
                    t('{amount} pay করুন', {
                        amount: money(renew.invoice_due ?? 0, 'bdt'),
                    })
                }}</KButton
            >
            <p class="mt-3 text-[13.5px] text-ink-3">
                {{
                    t(
                        'অন্য কয়দিন চাইলে নিচে বেছে নিন — আগের invoice বাতিল হয়ে নতুন হবে।',
                    )
                }}
            </p>
        </template>

        <p
            v-else-if="renew.status === 'declined'"
            class="mt-2 rounded-lg bg-wait-soft px-3 py-2 text-[14.5px] text-wait"
        >
            {{
                t(
                    'আপনি জানিয়েছেন আর চালাবেন না — {stops}। মত বদলালে নিচে দিন বেছে নিন।',
                    {
                        stops: campaign.stops ?? '',
                    },
                )
            }}
        </p>
        <p v-else class="mt-2 text-[15px] text-ink-2">
            {{
                t(
                    '{stops}। চালু রাখতে কত দিন বাড়াবেন বেছে নিন, তারপর pay করুন।',
                    { stops: campaign.stops ?? '' },
                )
            }}
        </p>

        <div class="mt-4 grid grid-cols-4 gap-2">
            <button
                v-for="d in choices"
                :key="d"
                type="button"
                class="h-12 rounded-xl border-2 text-[16px] font-semibold transition-colors"
                :class="
                    renewDays === d
                        ? 'border-brand bg-brand-soft text-brand'
                        : 'border-line-2'
                "
                @click="renewDays = d"
            >
                {{ t('{n} দিন', { n: d }) }}
            </button>
            <input
                :value="choices.includes(renewDays) ? '' : renewDays"
                type="number"
                min="1"
                max="90"
                inputmode="numeric"
                :placeholder="t('অন্য')"
                class="h-12 min-w-0 rounded-xl border-2 border-line-2 bg-transparent text-center text-[16px] font-semibold transition-colors focus:border-brand"
                @input="
                    renewDays = Math.min(
                        90,
                        Math.max(
                            1,
                            Number(($event.target as HTMLInputElement).value) ||
                                7,
                        ),
                    )
                "
            />
        </div>

        <p class="mt-3 text-[14.5px] text-ink-2">
            {{
                t('দিনে {budget} × {days} দিন', {
                    budget: money(renew.budget, cur),
                    days: renewDays,
                })
            }}
            <b v-if="price" class="num text-ink">
                = {{ money(price, 'bdt') }}</b
            >
        </p>
        <p v-if="error" class="mt-2 text-[14px] text-out">{{ error }}</p>

        <KButton
            variant="primary"
            size="lg"
            block
            class="mt-4"
            :loading="busy"
            @click="renewNow"
            >{{
                t('{days} দিন বাড়ান ও pay করুন', { days: renewDays })
            }}</KButton
        >
        <KButton
            v-if="renew.status !== 'declined'"
            variant="danger"
            block
            icon="close"
            class="mt-3"
            @click="stopIt"
            >{{ t('আর চালাবো না — campaign থামুক') }}</KButton
        >
    </section>

    <div class="mt-5">
        <RangePicker
            :url="`/portal/campaigns/${campaign.slug ?? campaign.id}`"
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

    <section v-if="extensions.length" class="panel mt-4 p-5">
        <h2 class="text-[16px] font-bold">
            {{ t('কবে কত দিন বাড়ানো হয়েছে') }}
        </h2>
        <ul class="mt-3 divide-y divide-line text-[14.5px]">
            <li
                v-for="(e, i) in extensions"
                :key="i"
                class="flex flex-col gap-0.5 py-2.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-3"
            >
                <span class="font-medium">{{
                    e.days
                        ? t('{n} দিন বাড়ানো হয়েছে', { n: e.days })
                        : t('বাড়ানো হয়েছে')
                }}</span>
                <span class="num text-[13.5px] text-ink-3"
                    >{{ e.at
                    }}<template v-if="e.until">
                        · {{ t('{date} পর্যন্ত', { date: e.until }) }}</template
                    ></span
                >
            </li>
        </ul>
    </section>
</template>
