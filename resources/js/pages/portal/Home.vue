<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SpendChart from '@/components/portal/SpendChart.vue';
import KIcon from '@/components/k/KIcon.vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

type Campaign = {
    id: number;
    name: string;
    live: boolean;
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
    };
    week: { day: string; spend: number; results: number }[];
    running: Campaign[];
    due: { count: number; amount: number };
    next_invoice: InvoiceRow | null;
    app?: string | null;
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
        <h2 class="mb-3 text-[16px] font-bold">{{ t('এখন চলছে') }}</h2>
        <p v-if="!running.length" class="panel p-5 text-[14px] text-ink-3">
            {{ t('এখন কোনো campaign চলছে না।') }}
        </p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Link
                v-for="c in running"
                :key="c.id"
                :href="`/portal/campaigns/${c.id}`"
                class="panel block p-4 transition-colors hover:border-brand"
            >
                <span class="flex items-center gap-2">
                    <span class="size-2 shrink-0 rounded-full bg-in" />
                    <span class="truncate text-[14.5px] font-semibold">{{
                        c.name
                    }}</span>
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
</template>
