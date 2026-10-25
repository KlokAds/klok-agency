<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CampaignState from '@/components/portal/CampaignState.vue';
import type { CampaignStateKey } from '@/components/portal/CampaignState.vue';
import SpendChart from '@/components/portal/SpendChart.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KTextarea from '@/components/k/KTextarea.vue';
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
        round?: {
            paid: number;
            carried: number;
            spent: number;
            since: string;
        } | null;
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
// Asking for the money left back: where to send it, and a note.
const refundOpen = ref(false);
const refundForm = useForm({ to: '', note: '' });
function askRefund(): void {
    refundForm.post('/portal/refund-request', {
        preserveScroll: true,
        onSuccess: () => {
            refundOpen.value = false;
            refundForm.reset();
        },
    });
}

// The latest payment in, and how many there were (refunds aside).
const lastPayment = computed(
    () => props.payments?.find((p) => !p.refund) ?? null,
);
const paidCount = computed(
    () => props.payments?.filter((p) => !p.refund).length ?? 0,
);

// A platform's name short enough for the small card: FB Ads, GL Ads, TT Ads, else as it is.
const shortPlatform = (name: string) =>
    /facebook|meta/i.test(name)
        ? 'FB Ads'
        : /google/i.test(name)
          ? 'GL Ads'
          : /tiktok/i.test(name)
            ? 'TT Ads'
            : name;

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
// What share of the paid money is still left, and the ring's colour for it.
// This round: what there was right after the latest payment (what is left plus what was spent
// since); the ring starts full with each payment and empties from it.
const roundBase = computed(() =>
    props.credit.round
        ? props.credit.left + props.credit.round.spent
        : props.credit.paid,
);
const leftShare = computed(() =>
    roundBase.value > 0
        ? Math.max(
              0,
              Math.min(
                  100,
                  Math.round((props.credit.left / roundBase.value) * 100),
              ),
          )
        : 0,
);
const ringColor = computed(() =>
    leftShare.value < 20
        ? '#ef4444'
        : leftShare.value < 50
          ? '#f59e0b'
          : '#5eead4',
);

// How long the money lasts, in plain words: today, tomorrow, or about so many days.
const lasts = computed(() => {
    const d = props.credit.days_left;
    if (props.credit.left <= 0) {
        return t('টাকা শেষ');
    }
    if (d === null) {
        return t('চলছে');
    }
    if (d <= 0) {
        return t('আজই শেষ হবে');
    }
    if (d === 1) {
        return t('কাল শেষ হবে');
    }
    return t('আর প্রায় {n} দিন চলবে', { n: d });
});
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
            <p class="text-[12px] text-ink-3">
                {{ t('বিজ্ঞাপনের ডলার নিয়েছেন') }}
            </p>
            <p class="num mt-0.5 text-[18px] font-bold">
                {{ money(lifetime?.spent_usd ?? credit.spent, 'usd') }}
            </p>
            <!-- Where it went, when more than one platform: FB Ads, GL Ads, others — a line each. -->
            <ul
                v-if="(lifetime?.by_platform?.length ?? 0) > 1"
                class="mt-1.5 space-y-0.5 border-t border-line pt-1.5 text-[11px] text-ink-3"
            >
                <li
                    v-for="p in lifetime?.by_platform"
                    :key="p.name"
                    class="flex items-center justify-between gap-2"
                >
                    <span class="truncate">{{ shortPlatform(p.name) }}</span>
                    <span class="num shrink-0 font-semibold text-ink-2">{{
                        money(p.usd, 'usd')
                    }}</span>
                </li>
            </ul>
        </div>
        <div class="panel p-3.5">
            <p class="text-[12px] text-ink-3">{{ t('আপনি দিয়েছেন') }}</p>
            <p class="num mt-0.5 text-[18px] font-bold">
                {{ money(lifetime?.paid_bdt ?? 0, 'bdt') }}
            </p>
            <ul
                v-if="lastPayment"
                class="mt-1.5 space-y-0.5 border-t border-line pt-1.5 text-[11px] text-ink-3"
            >
                <li class="flex items-center justify-between gap-2">
                    <span class="truncate">{{ t('শেষ পেমেন্ট') }}</span>
                    <span class="num shrink-0 font-semibold text-ink-2">{{
                        lastPayment.date
                    }}</span>
                </li>
                <li class="flex items-center justify-between gap-2">
                    <span class="truncate">{{
                        t('{n}টা পেমেন্ট', { n: paidCount })
                    }}</span>
                    <span class="num shrink-0 font-semibold text-ink-2">{{
                        money(lastPayment.amount, 'bdt')
                    }}</span>
                </li>
            </ul>
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
            <KButton
                v-if="credit.refund"
                size="sm"
                variant="plain"
                class="mt-2"
                @click="refundOpen = true"
                >{{ t('বাকি টাকা ফেরত চান') }}</KButton
            >
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

    <!-- The money running the ads now: what is left, how long it lasts, and what keeps it going. -->
    <section
        class="mt-5 overflow-hidden rounded-[20px] bg-gradient-to-br from-[#0f766e] via-[#0d5f59] to-[#11211e] p-5 text-white shadow-[0_10px_30px_-12px_rgb(15_118_110/0.55)] sm:p-6"
    >
        <div class="flex items-center gap-5">
            <!-- How much of the paid money is left: teal, then amber, then red as it runs out -->
            <span
                class="relative grid size-[104px] shrink-0 place-items-center"
            >
                <svg
                    viewBox="0 0 120 120"
                    class="absolute inset-0 size-full -rotate-90"
                    aria-hidden="true"
                >
                    <circle
                        cx="60"
                        cy="60"
                        r="50"
                        fill="none"
                        stroke="rgb(255 255 255 / 0.16)"
                        stroke-width="12"
                    />
                    <circle
                        cx="60"
                        cy="60"
                        r="50"
                        fill="none"
                        :stroke="ringColor"
                        stroke-width="12"
                        stroke-linecap="round"
                        :stroke-dasharray="`${(314.16 * leftShare) / 100} 314.16`"
                    />
                </svg>
                <span class="relative text-center leading-tight">
                    <b class="num block text-[20px]">{{ leftShare }}%</b>
                    <span class="block text-[10.5px] text-white/70">{{
                        t('বাকি')
                    }}</span>
                </span>
            </span>
            <div class="min-w-0">
                <p class="text-[13px] text-white/70">
                    {{ t('বিজ্ঞাপনের জন্য বাকি') }}
                </p>
                <p class="num mt-0.5 text-[34px] leading-none font-bold">
                    {{ money(credit.left, 'usd') }}
                </p>
                <p
                    v-if="credit.paid > 0"
                    class="mt-2 inline-flex rounded-full px-2.5 py-0.5 text-[12.5px] font-semibold"
                    :class="
                        leftShare < 20
                            ? 'bg-[#ef4444] text-white'
                            : leftShare < 50
                              ? 'bg-[#f59e0b] text-[#1f1300]'
                              : 'bg-white/15 text-white'
                    "
                >
                    {{ lasts }}
                </p>
            </div>
        </div>

        <!-- Three even cells: a one-word label, the figure, one small line under it. -->
        <div
            v-if="credit.paid > 0"
            class="mt-5 grid grid-cols-3 divide-x divide-white/10 rounded-2xl bg-white/[0.08] py-3 text-center"
        >
            <p class="px-1">
                <span
                    class="block text-[11.5px] whitespace-nowrap text-white/65"
                    >{{ t('দেওয়া') }}</span
                >
                <b class="num mt-0.5 block text-[16px]">{{
                    money(credit.round?.paid ?? credit.paid, 'usd')
                }}</b>
                <span
                    class="num mt-0.5 block text-[10.5px] whitespace-nowrap text-white/55"
                    >{{ credit.round?.since ?? t('এ পর্যন্ত') }}</span
                >
            </p>
            <p class="px-1">
                <span
                    class="block text-[11.5px] whitespace-nowrap text-white/65"
                    >{{ t('খরচ') }}</span
                >
                <b class="num mt-0.5 block text-[16px]">{{
                    money(credit.round?.spent ?? credit.spent, 'usd')
                }}</b>
                <span
                    class="mt-0.5 block text-[10.5px] whitespace-nowrap text-white/55"
                    >{{
                        credit.round
                            ? t('{date} থেকে', { date: credit.round.since })
                            : t('এ পর্যন্ত')
                    }}</span
                >
            </p>
            <p class="px-1">
                <span
                    class="block text-[11.5px] whitespace-nowrap text-white/65"
                    >{{ t('দিনে') }}</span
                >
                <b class="num mt-0.5 block text-[16px]">{{
                    credit.daily > 0
                        ? money(credit.daily, 'usd')
                        : t('এখনো নেই')
                }}</b>
                <span
                    class="mt-0.5 block text-[10.5px] whitespace-nowrap text-white/55"
                    >{{ t('গড়ে') }}</span
                >
            </p>
        </div>

        <!-- What keeps it going: the bill to pay, right here. -->
        <a
            v-if="next_invoice"
            :href="due.count > 1 ? '/portal/invoices' : next_invoice.url"
            class="mt-4 flex items-center gap-3 rounded-2xl bg-white p-3.5 text-[#14211f] transition-transform active:scale-[0.99]"
        >
            <span
                class="grid size-10 shrink-0 place-items-center rounded-full"
                :class="
                    next_invoice.overdue
                        ? 'bg-[#fee2e2] text-[#b91c1c]'
                        : 'bg-[#ccfbf1] text-[#0f766e]'
                "
                ><KIcon name="doc" :size="20"
            /></span>
            <span class="min-w-0 flex-1">
                <span class="block text-[14px] font-semibold text-[#14211f]">{{
                    due.count > 1
                        ? t('{n}টা invoice বাকি · মোট {amount}', {
                              n: due.count,
                              amount: money(due.amount, 'bdt'),
                          })
                        : t('চালু রাখতে {amount} pay করুন', {
                              amount: money(next_invoice.due, 'bdt'),
                          })
                }}</span>
                <span class="block text-[12px] text-[#5b6b68]">{{
                    next_invoice.overdue
                        ? t('Invoice {number} · শেষ তারিখ পার হয়ে গেছে', {
                              number: next_invoice.number,
                          })
                        : next_invoice.due_date
                          ? t('Invoice {number} · শেষ তারিখ {date}', {
                                number: next_invoice.number,
                                date: next_invoice.due_date,
                            })
                          : `Invoice ${next_invoice.number}`
                }}</span>
            </span>
            <span
                class="shrink-0 rounded-xl bg-[#0f766e] px-4 py-2 text-[13.5px] font-bold text-white"
                >{{ t('Pay') }}</span
            >
        </a>
        <p
            v-else-if="low"
            class="mt-4 rounded-xl bg-white/10 px-3.5 py-2.5 text-[13px] text-white/90"
        >
            {{ t('টাকা শেষের দিকে — চালু রাখতে চাইলে আমাদের জানান।') }}
        </p>
    </section>

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
            <KButton size="sm" variant="plain" href="/portal/campaigns">{{
                t('সব দেখুন')
            }}</KButton>
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
            <KButton
                size="sm"
                variant="plain"
                icon="back"
                :disabled="payPage <= 1"
                @click="payPage--"
                >{{ t('আগের') }}</KButton
            >
            <span class="num text-[12.5px] text-ink-3"
                >{{ payPage }} / {{ payPages }}</span
            >
            <KButton
                size="sm"
                variant="plain"
                :disabled="payPage >= payPages"
                @click="payPage++"
                >{{ t('পরের') }}<KIcon name="arrow" :size="16"
            /></KButton>
        </div>
    </section>

    <KModal
        v-model="refundOpen"
        width="md"
        :title="t('বাকি টাকা ফেরত চান')"
        :subtitle="
            credit.refund
                ? `${money(credit.left, 'usd')} ≈ ${money(credit.refund, 'bdt')}`
                : undefined
        "
    >
        <p class="text-[13px] text-ink-3">
            {{
                t(
                    'আমাদের ডলার রেটে, commission বাদে — চূড়ান্ত অঙ্ক Klok Ads নিশ্চিত করবে।',
                )
            }}
        </p>
        <div class="mt-4 flex flex-col gap-4">
            <KField
                :label="t('কোথায় পাঠাব? (যেমন bKash 01712345678)')"
                :error="refundForm.errors.to"
            >
                <KInput
                    v-model="refundForm.to"
                    :invalid="!!refundForm.errors.to"
                />
            </KField>
            <KField
                :label="t('কিছু বলার থাকলে (ঐচ্ছিক)')"
                :error="refundForm.errors.note"
            >
                <KTextarea v-model="refundForm.note" :rows="3" />
            </KField>
        </div>
        <template #footer>
            <KButton variant="quiet" @click="refundOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                :loading="refundForm.processing"
                :disabled="refundForm.to.trim().length < 5"
                @click="askRefund"
                >{{ t('অনুরোধ পাঠান') }}</KButton
            >
        </template>
    </KModal>
</template>
