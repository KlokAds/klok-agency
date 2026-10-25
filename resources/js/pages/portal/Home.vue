<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CampaignState from '@/components/portal/CampaignState.vue';
import type { CampaignStateKey } from '@/components/portal/CampaignState.vue';
import SpendChart from '@/components/portal/SpendChart.vue';
import KIcon from '@/components/k/KIcon.vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

type Campaign = {
    id: number;
    name: string;
    live: boolean;
    state: CampaignStateKey;
    stops: string | null;
    days_left: number | null;
    budget: number | null;
    currency: string;
};
type InvoiceRow = {
    number: string;
    due_date: string | null;
    due: number;
    overdue: boolean;
    url: string;
};

const props = defineProps<{
    client: string;
    credit: {
        paid: number;
        spent: number;
        left: number;
        daily: number;
        days_left: number | null;
        refund?: number | null;
    };
    week: { day: string; spend: number; results: number }[];
    running: Campaign[];
    due: { count: number; amount: number };
    next_invoice: InvoiceRow | null;
    app?: string | null;
    lifetime?: {
        spent_usd: number;
        paid_bdt: number;
        by_platform?: { name: string; usd: number }[];
    };
    payments?: {
        date: string | null;
        amount: number;
        via: string | null;
        invoice: string | null;
        refund: boolean;
    }[];
}>();

// Inside the KlokOne app there is nothing to download.
const inApp =
    typeof navigator !== 'undefined' &&
    /KlokFinanceApp/.test(navigator.userAgent);

const usedShare = computed(() =>
    props.credit.paid > 0
        ? Math.min(
              100,
              Math.round((props.credit.spent / props.credit.paid) * 100),
          )
        : 0,
);
// Payment history, a few at a time, so the page stays short in the app.
const PER_PAGE = 5;
const payPage = ref(1);
const payPages = computed(() =>
    Math.max(1, Math.ceil((props.payments?.length ?? 0) / PER_PAGE)),
);
const shownPayments = computed(() =>
    (props.payments ?? []).slice(
        (payPage.value - 1) * PER_PAGE,
        payPage.value * PER_PAGE,
    ),
);

const today = computed(() => props.week[props.week.length - 1]);
const weekSpend = computed(() => props.week.reduce((a, d) => a + d.spend, 0));
const weekResults = computed(() =>
    props.week.reduce((a, d) => a + d.results, 0),
);
const low = computed(
    () =>
        props.credit.paid > 0 &&
        props.credit.days_left !== null &&
        props.credit.days_left <= 2,
);
</script>

<template>
    <Head :title="t('হোম')" />

    <p class="text-[13px] font-semibold tracking-wide text-ink-3 uppercase">
        {{ client }}
    </p>
    <h1 class="mt-1 text-[24px] font-bold">
        {{ t('আপনার বিজ্ঞাপন এক নজরে') }}
    </h1>

    <!-- Everything so far, small, first thing on the page. -->
    <section class="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <div class="panel p-3.5">
            <p class="text-[12px] text-ink-3">{{ t('বিজ্ঞাপনে খরচ') }}</p>
            <p class="num mt-0.5 text-[18px] font-bold">
                {{ money(lifetime?.spent_usd ?? credit.spent, 'usd') }}
            </p>
            <!-- Where it went, when more than one platform: Meta $195 · Google $5 -->
            <p
                v-if="(lifetime?.by_platform?.length ?? 0) > 1"
                class="num mt-0.5 truncate text-[11px] text-ink-3"
            >
                {{
                    lifetime?.by_platform
                        ?.map((p) => `${p.name} ${money(p.usd, 'usd')}`)
                        .join(' · ')
                }}
            </p>
        </div>
        <div class="panel p-3.5">
            <p class="text-[12px] text-ink-3">{{ t('আপনি দিয়েছেন') }}</p>
            <p class="num mt-0.5 text-[18px] font-bold">
                {{ money(lifetime?.paid_bdt ?? 0, 'bdt') }}
            </p>
        </div>
        <div class="panel p-3.5">
            <p class="text-[12px] text-ink-3">
                {{ t('বিজ্ঞাপনের জন্য বাকি') }}
            </p>
            <p class="mt-0.5 flex flex-wrap items-baseline gap-x-2">
                <span
                    class="num text-[18px] font-bold"
                    :class="credit.left <= 0 ? 'text-out' : 'text-in'"
                    >{{ money(credit.left, 'usd') }}</span
                >
                <!-- Asked back now: at our dollar rate; the commission stays. -->
                <span
                    v-if="credit.refund"
                    class="num text-[11.5px] text-ink-3"
                    :title="t('আমাদের ডলার রেটে; commission ফেরতযোগ্য নয়')"
                    >{{
                        t('ফেরত ≈ {amount}', {
                            amount: money(credit.refund, 'bdt'),
                        })
                    }}</span
                >
            </p>
        </div>
        <component
            :is="next_invoice ? 'a' : 'div'"
            :href="due.count > 1 ? '/portal/invoices' : next_invoice?.url"
            class="panel p-3.5"
            :class="next_invoice ? 'transition-colors hover:border-brand' : ''"
        >
            <p class="text-[12px] text-ink-3">{{ t('Invoice বাকি') }}</p>
            <p class="mt-0.5 flex items-baseline gap-1.5">
                <span
                    class="num text-[18px] font-bold"
                    :class="
                        due.amount > 0
                            ? next_invoice?.overdue
                                ? 'text-out'
                                : 'text-wait'
                            : ''
                    "
                    >{{ money(due.amount, 'bdt') }}</span
                >
                <span
                    v-if="due.count > 0"
                    class="rounded-full bg-sunken px-1.5 py-0.5 text-[11px] font-semibold text-ink-2"
                    >{{ t('{n}টা', { n: due.count }) }}</span
                >
            </p>
        </component>
    </section>

    <a
        v-if="app && !inApp"
        href="/app/get"
        class="panel mt-5 flex items-center gap-4 p-4 transition-colors hover:border-brand"
    >
        <span
            class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand text-white"
            ><KIcon name="bell" :size="22"
        /></span>
        <span class="min-w-0 flex-1">
            <span class="block text-[14.5px] font-semibold">{{
                t('KlokOne app নামান')
            }}</span>
            <span class="block text-[12.5px] text-ink-3">{{
                t(
                    'Invoice, পেমেন্ট আর বিজ্ঞাপনের খবর সাথে সাথে phone-এ — আঙুলের ছাপে খোলা',
                )
            }}</span>
        </span>
        <span class="shrink-0 text-[12px] font-semibold text-brand"
            >Android · v{{ app }}</span
        >
    </a>

    <!-- Money for ads: paid, spent, left, and for how long -->
    <section class="panel mt-5 p-5 sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-[13px] text-ink-3">
                    {{ t('বিজ্ঞাপনের জন্য বাকি') }}
                </p>
                <p
                    class="num mt-1 text-[34px] leading-none font-bold"
                    :class="credit.left <= 0 ? 'text-out' : 'text-ink'"
                >
                    {{ money(credit.left, 'usd') }}
                </p>
            </div>
            <p
                v-if="credit.days_left !== null && credit.paid > 0"
                class="rounded-full px-3 py-1 text-[13px] font-semibold"
                :class="low ? 'bg-out-soft text-out' : 'bg-in-soft text-in'"
            >
                {{ t('আর প্রায় {n} দিন চলবে', { n: credit.days_left }) }}
            </p>
        </div>
        <div
            v-if="credit.paid > 0"
            class="mt-4 h-2.5 overflow-hidden rounded-full bg-sunken"
        >
            <div
                class="h-full rounded-full bg-brand"
                :style="{ width: usedShare + '%' }"
            />
        </div>
        <div class="mt-3 grid grid-cols-1 gap-2 text-[13px] sm:grid-cols-3">
            <p class="text-ink-3">
                {{ t('দেওয়া হয়েছে') }}
                <b class="num text-ink">{{ money(credit.paid, 'usd') }}</b>
            </p>
            <p class="text-ink-3">
                {{ t('খরচ হয়েছে') }}
                <b class="num text-ink">{{ money(credit.spent, 'usd') }}</b>
            </p>
            <p v-if="credit.daily > 0" class="text-ink-3">
                {{ t('দিনে খরচ') }}
                <b class="num text-ink">{{ money(credit.daily, 'usd') }}</b>
            </p>
        </div>
        <p v-if="low" class="mt-3 text-[13px] font-medium text-out">
            {{ t('টাকা শেষের দিকে — বিজ্ঞাপন না থামাতে চাইলে আগেই pay করুন।') }}
        </p>
    </section>

    <!-- A bill to pay -->
    <a
        v-if="next_invoice"
        :href="next_invoice.url"
        class="panel mt-4 flex items-center gap-4 p-5 transition-colors hover:border-brand"
    >
        <span
            class="grid size-11 shrink-0 place-items-center rounded-full"
            :class="
                next_invoice.overdue
                    ? 'bg-out-soft text-out'
                    : 'bg-wait-soft text-wait'
            "
            ><KIcon name="doc" :size="22"
        /></span>
        <span class="min-w-0 flex-1">
            <span class="block text-[15px] font-semibold">
                {{
                    due.count > 1
                        ? t('{n}টা invoice বাকি · মোট {amount}', {
                              n: due.count,
                              amount: money(due.amount, 'bdt'),
                          })
                        : t('Invoice {number} · {amount} বাকি', {
                              number: next_invoice.number,
                              amount: money(next_invoice.due, 'bdt'),
                          })
                }}
            </span>
            <span class="block text-[13px] text-ink-3">
                {{
                    next_invoice.overdue
                        ? t('শেষ তারিখ পার হয়ে গেছে')
                        : t('শেষ তারিখ {date}', {
                              date: next_invoice.due_date ?? '—',
                          })
                }}
            </span>
        </span>
        <span
            class="shrink-0 rounded-lg bg-brand px-4 py-2 text-[14px] font-semibold text-white"
            >{{ t('Pay করুন') }}</span
        >
    </a>

    <!-- Today and the week -->
    <section class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="panel p-4">
            <p class="text-[12.5px] text-ink-3">{{ t('আজ খরচ') }}</p>
            <p class="num mt-1 text-[22px] font-bold">
                {{ money(today?.spend ?? 0, 'usd') }}
            </p>
        </div>
        <div class="panel p-4">
            <p class="text-[12.5px] text-ink-3">{{ t('আজ result') }}</p>
            <p class="num mt-1 text-[22px] font-bold">
                {{ today?.results ?? 0 }}
            </p>
        </div>
        <div class="panel p-4">
            <p class="text-[12.5px] text-ink-3">{{ t('৭ দিনে খরচ') }}</p>
            <p class="num mt-1 text-[22px] font-bold">
                {{ money(weekSpend, 'usd') }}
            </p>
        </div>
        <div class="panel p-4">
            <p class="text-[12.5px] text-ink-3">{{ t('৭ দিনে result') }}</p>
            <p class="num mt-1 text-[22px] font-bold">{{ weekResults }}</p>
        </div>
    </section>

    <section class="panel mt-4 p-5">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-[15px] font-bold">{{ t('গত ৭ দিন') }}</h2>
            <Link
                href="/portal/campaigns"
                class="text-[13px] font-semibold text-brand hover:underline"
                >{{ t('সব দেখুন') }} →</Link
            >
        </div>
        <SpendChart :series="week" />
    </section>

    <!-- What runs now -->
    <section class="mt-6">
        <h2 class="mb-3 text-[16px] font-bold">{{ t('আপনার campaign') }}</h2>
        <p v-if="!running.length" class="panel p-5 text-[14px] text-ink-3">
            {{ t('এখন কোনো চালু campaign নেই।') }}
        </p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Link
                v-for="c in running"
                :key="c.id"
                :href="`/portal/campaigns/${c.id}`"
                class="panel block p-4 transition-colors hover:border-brand"
            >
                <span class="flex items-center justify-between gap-2">
                    <span class="truncate text-[14.5px] font-semibold">{{
                        c.name
                    }}</span>
                    <CampaignState :state="c.state" />
                </span>
                <span class="mt-2 block text-[13px] text-ink-2">
                    {{ c.stops ?? t('শেষের তারিখ নেই') }}
                </span>
                <span
                    v-if="c.days_left !== null"
                    class="mt-1 block text-[12.5px] text-ink-3"
                    >{{ t('আর {n} দিন', { n: c.days_left }) }}
                    <template v-if="c.budget">
                        ·
                        {{
                            t('দিনে {amount}', {
                                amount: money(
                                    c.budget,
                                    c.currency === 'BDT' ? 'bdt' : 'usd',
                                ),
                            })
                        }}</template
                    ></span
                >
            </Link>
        </div>
    </section>

    <!-- Every payment with its date, at the very end. -->
    <section class="panel mt-6 p-5">
        <h2 class="text-[16px] font-bold">{{ t('পেমেন্টের ইতিহাস') }}</h2>
        <p v-if="!payments?.length" class="mt-2 text-[13px] text-ink-3">
            {{ t('এখনো কোনো পেমেন্ট নেই।') }}
        </p>
        <ul v-else class="mt-2 divide-y divide-line">
            <li
                v-for="(p, i) in shownPayments"
                :key="`${payPage}-${i}`"
                class="flex items-center justify-between gap-3 py-2.5 text-[13.5px]"
            >
                <span class="min-w-0">
                    <span class="block font-medium">{{ p.date }}</span>
                    <span class="block truncate text-[12px] text-ink-3">
                        {{ p.refund ? t('ফেরত') : (p.via ?? t('পেমেন্ট')) }}
                        <template v-if="p.invoice"> · {{ p.invoice }}</template>
                    </span>
                </span>
                <span
                    class="num shrink-0 font-semibold"
                    :class="p.refund ? 'text-out' : 'text-in'"
                    >{{ p.refund ? '−' : '+'
                    }}{{ money(Math.abs(p.amount), 'bdt') }}</span
                >
            </li>
        </ul>
        <div
            v-if="payPages > 1"
            class="mt-3 flex items-center justify-between gap-3 border-t border-line pt-3"
        >
            <button
                type="button"
                class="inline-flex h-9 items-center gap-1 rounded-lg border border-line px-3 text-[13px] font-semibold text-ink-2 transition-colors hover:border-brand hover:text-brand disabled:opacity-40 disabled:hover:border-line disabled:hover:text-ink-2"
                :disabled="payPage <= 1"
                @click="payPage--"
            >
                ← {{ t('আগের') }}
            </button>
            <span class="num text-[12.5px] text-ink-3"
                >{{ payPage }} / {{ payPages }}</span
            >
            <button
                type="button"
                class="inline-flex h-9 items-center gap-1 rounded-lg border border-line px-3 text-[13px] font-semibold text-ink-2 transition-colors hover:border-brand hover:text-brand disabled:opacity-40 disabled:hover:border-line disabled:hover:text-ink-2"
                :disabled="payPage >= payPages"
                @click="payPage++"
            >
                {{ t('পরের') }} →
            </button>
        </div>
    </section>
</template>
