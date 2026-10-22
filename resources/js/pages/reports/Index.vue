<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import TrendChart from '@/components/dashboard/TrendChart.vue';
import type { TrendRow } from '@/components/dashboard/types';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KSegment from '@/components/k/KSegment.vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type ClientRow = {
    id: number;
    name: string;
    usd_settled: number;
    cost_settled: number;
    received: number;
    margin: number;
    usd_pending: number;
    cost_pending: number;
};

type Kpi = {
    revenue: number;
    ad_cost: number;
    gross: number;
    office: number;
    net: number;
};

const props = defineProps<{
    pl: {
        clients: ClientRow[];
        unlinked_income: number;
        margin: number;
        own_usd: number;
        own_cost: number;
        expenses: { name: string; amount: number }[];
        expense_total: number;
        total_expense: number;
        net: number;
        pending_usd: number;
        pending_cost: number;
    };
    kpi: Kpi;
    previous: Kpi | null;
    previous_period: { from: string; to: string } | null;
    trend: TrendRow[];
    outside: { unearned: number; owner_due: number };
    period: { from: string | null; to: string | null; preset: string };
}>();

const can = computed(() => usePage().props.auth.can);

const presets = computed<Option[]>(() => [
    { value: 'all', label: t('শুরু থেকে') },
    { value: 'this_month', label: t('এই মাস') },
    { value: 'last_month', label: t('গত মাস') },
    { value: 'this_year', label: t('এই বছর') },
    { value: 'custom', label: t('নিজে বাছুন') },
]);

const preset = ref(props.period.preset);
const from = ref(props.period.from);
const to = ref(props.period.to);

function load(): void {
    const query: Record<string, string> = { preset: preset.value };

    if (preset.value === 'custom') {
        if (from.value) query.from = from.value;
        if (to.value) query.to = to.value;
    }

    router.get('/reports', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(preset, (value) => value !== 'custom' && load());
watch([from, to], () => preset.value === 'custom' && load());

const exportUrl = computed(() => {
    const q = new URLSearchParams({ preset: preset.value });
    if (preset.value === 'custom') {
        if (from.value) q.set('from', from.value);
        if (to.value) q.set('to', to.value);
    }

    return `/reports/export/pl?${q}`;
});

const periodLabel = computed(() =>
    props.period.from
        ? `${niceDate(props.period.from)} — ${niceDate(props.period.to)}`
        : t('শুরু থেকে আজ পর্যন্ত'),
);
const previousLabel = computed(() =>
    props.previous_period
        ? `${niceDate(props.previous_period.from)} — ${niceDate(props.previous_period.to)}`
        : '',
);

function printPage(): void {
    window.print();
}

function pct(part: number, whole: number): string {
    return whole ? `${((part / whole) * 100).toFixed(1)}%` : '–';
}

// KPI cards, each against the period before.
type Card = {
    key: keyof Kpi;
    label: string;
    hint: string;
    upIsGood: boolean;
};

const cards = computed<Card[]>(() => [
    {
        key: 'revenue',
        label: t('মোট আয়'),
        hint: t('Client-দের থেকে + service'),
        upIsGood: true,
    },
    {
        key: 'ad_cost',
        label: t('Ad খরচ'),
        hint: t('Client-দের ad, কেনা দামে (FIFO)'),
        upIsGood: false,
    },
    {
        key: 'gross',
        label: 'Gross margin',
        hint: t('আয়ের {pct}', {
            pct: pct(props.kpi.gross, props.kpi.revenue),
        }),
        upIsGood: true,
    },
    {
        key: 'office',
        label: t('অফিস খরচ'),
        hint: t('বেতন, অফিস আর নিজেদের ad'),
        upIsGood: false,
    },
    {
        key: 'net',
        label: 'Net profit / (loss)',
        hint: t('আয়ের {pct}', { pct: pct(props.kpi.net, props.kpi.revenue) }),
        upIsGood: true,
    },
]);

function delta(card: Card): {
    text: string;
    tone: string;
    arrow: string;
} | null {
    if (!props.previous) return null;

    const now = props.kpi[card.key];
    const before = props.previous[card.key];
    const diff = now - before;

    if (Math.abs(diff) < 100) {
        return { text: t('একই'), tone: 'text-ink-3', arrow: '→' };
    }

    const good = diff > 0 === card.upIsGood;
    const text = before
        ? `${Math.abs((diff / Math.abs(before)) * 100).toFixed(0)}%`
        : money(Math.abs(diff), 'bdt');

    return {
        text,
        tone: good ? 'text-in' : 'text-out',
        arrow: diff > 0 ? '↑' : '↓',
    };
}

const tiles = computed(() =>
    cards.value.map((c) => {
        const cost = c.key === 'ad_cost' || c.key === 'office';

        return {
            ...c,
            value: cost ? -props.kpi[c.key] : props.kpi[c.key],
            before: props.previous
                ? cost
                    ? -props.previous[c.key]
                    : props.previous[c.key]
                : 0,
            change: delta(c),
            net: c.key === 'net',
        };
    }),
);

// Where the money goes: every expense head plus our own ad spend.
const spend = computed(() => {
    const items = props.pl.expenses.map((x) => ({
        name: t(x.name),
        amount: x.amount,
        note: '',
    }));

    if (props.pl.own_cost) {
        items.push({
            name: t('নিজেদের ad খরচ'),
            amount: props.pl.own_cost,
            note: money(props.pl.own_usd, 'usd'),
        });
    }

    items.sort((a, b) => b.amount - a.amount);

    const total = items.reduce((n, x) => n + x.amount, 0);
    const top = Math.max(1, ...items.map((x) => Math.abs(x.amount)));

    return {
        total,
        items: items.map((x) => ({
            ...x,
            share: pct(x.amount, total),
            width: (Math.abs(x.amount) / top) * 100,
        })),
    };
});

// Profit per client, best first.
const clients = computed(() => {
    const rows = props.pl.clients
        .filter((c) => c.received || c.usd_settled)
        .map((c) => ({
            ...c,
            pct: pct(c.margin, c.received),
        }))
        .sort((a, b) => b.margin - a.margin);
    const top = Math.max(1, ...rows.map((c) => Math.abs(c.margin)));

    return rows.map((c) => ({ ...c, width: (Math.abs(c.margin) / top) * 100 }));
});
</script>

<template>
    <PageHeader
        :eyebrow="t('হিসাব')"
        title="Profit & Loss"
        :lead="
            t(
                'কত আয় হলো, কোথায় খরচ হলো, কোন client থেকে কত লাভ। USD খরচ তার নিজের batch-এর rate-এ ধরা (FIFO)।',
            )
        "
    >
        <KButton icon="print" class="max-sm:hidden" @click="printPage"
            >Print / PDF</KButton
        >
        <KButton
            v-if="can['reports.export']"
            class="max-sm:hidden"
            :href="exportUrl"
            external
            icon="down"
            >CSV / Excel</KButton
        >
    </PageHeader>

    <section class="no-print mb-6 flex flex-wrap items-center gap-3">
        <KSegment v-model="preset" :options="presets" size="sm" />
        <div class="flex w-full gap-2 sm:hidden">
            <KButton size="sm" icon="print" @click="printPage"
                >Print / PDF</KButton
            >
            <KButton
                size="sm"
                v-if="can['reports.export']"
                :href="exportUrl"
                external
                icon="down"
                >CSV / Excel</KButton
            >
        </div>
        <div v-if="preset === 'custom'" class="flex items-center gap-2">
            <KDate
                v-model="from"
                :placeholder="t('শুরু')"
                size="sm"
                clearable
            />
            <span class="text-ink-3">—</span>
            <KDate v-model="to" :placeholder="t('শেষ')" size="sm" clearable />
        </div>
    </section>

    <div class="report">
        <!-- Period -->
        <div
            class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
        >
            <p class="text-[15px] font-semibold">
                <span class="eyebrow mr-2">Klok Ads</span>{{ periodLabel }}
            </p>
            <p v-if="previous" class="text-[12.5px] text-ink-3">
                {{ t('তুলনা: আগের সময় {period}', { period: previousLabel }) }}
            </p>
        </div>

        <!-- KPIs -->
        <section
            class="grid grid-cols-2 gap-px overflow-hidden rounded-[16px] border border-line bg-line lg:grid-cols-5"
        >
            <div
                v-for="c in tiles"
                :key="c.key"
                class="min-w-0 p-4 sm:p-5"
                :class="
                    c.key === 'net'
                        ? 'col-span-2 bg-rail text-rail-ink lg:col-span-1'
                        : 'bg-surface'
                "
            >
                <p
                    :class="
                        c.key === 'net'
                            ? 'text-[11px] font-bold tracking-[0.14em] text-rail-dim uppercase'
                            : 'eyebrow'
                    "
                >
                    {{ c.label }}
                </p>
                <p
                    class="num mt-2.5 text-[24px] leading-none font-medium sm:text-[26px]"
                    :class="
                        c.key === 'net'
                            ? kpi.net < 0
                                ? 'text-[#f0875e]'
                                : 'text-white'
                            : ''
                    "
                >
                    {{ money(c.value, 'bdt', { sign: c.key === 'net' }) }}
                </p>
                <p
                    class="mt-2 text-[12px]"
                    :class="c.key === 'net' ? 'text-rail-dim' : 'text-ink-3'"
                >
                    {{ c.hint }}
                </p>
                <p
                    v-if="c.change"
                    class="mt-1.5 flex flex-wrap items-baseline gap-x-1.5 text-[12px]"
                >
                    <span
                        class="num font-semibold"
                        :class="
                            c.key === 'net'
                                ? c.change.tone === 'text-out'
                                    ? 'text-[#f0875e]'
                                    : c.change.tone === 'text-in'
                                      ? 'text-[#2dd4bf]'
                                      : 'text-rail-dim'
                                : c.change.tone
                        "
                        >{{ c.change.arrow }} {{ c.change.text }}</span
                    >
                    <span
                        :class="
                            c.key === 'net' ? 'text-rail-dim' : 'text-ink-3'
                        "
                        >{{ t('আগে') }}
                        <span class="num">{{
                            money(c.before, 'bdt')
                        }}</span></span
                    >
                </p>
            </div>
        </section>

        <!-- Trend -->
        <section class="panel mt-6 p-5 sm:p-6">
            <h2 class="text-[16px] font-bold">{{ t('মাসে মাসে') }}</h2>
            <p class="mb-4 text-[12.5px] text-ink-3">
                {{
                    t(
                        'প্রতি মাসের আয়, মোট খরচ আর লাভ। বাছাই করা সময়ের বাইরের মাস হালকা দেখানো।',
                    )
                }}
            </p>
            <TrendChart :rows="trend" />
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_1.25fr]">
            <!-- Expense breakdown -->
            <section class="panel p-5 sm:p-6">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-[16px] font-bold">
                        {{ t('খরচ কোথায় যাচ্ছে') }}
                    </h2>
                    <span class="num text-[14px] font-semibold">{{
                        money(spend.total, 'bdt')
                    }}</span>
                </div>
                <p class="text-[12.5px] text-ink-3">
                    {{ t('Client-দের ad খরচ বাদে — অফিস চালাতে যা লাগল।') }}
                </p>

                <KEmpty
                    v-if="!spend.items.length"
                    :title="t('এই সময়ে কোনো খরচ নেই')"
                />
                <ul v-else class="mt-4 space-y-3.5">
                    <li v-for="x in spend.items" :key="x.name">
                        <div
                            class="flex items-baseline justify-between gap-3 text-[13.5px]"
                        >
                            <span class="min-w-0 truncate font-medium"
                                >{{ x.name }}
                                <span
                                    v-if="x.note"
                                    class="num text-[12px] font-normal text-ink-3"
                                    >{{ x.note }}</span
                                ></span
                            >
                            <span class="shrink-0 whitespace-nowrap">
                                <span class="num font-semibold">{{
                                    money(x.amount, 'bdt')
                                }}</span>
                                <span
                                    class="num ml-2 inline-block w-12 text-right text-[12px] text-ink-3"
                                    >{{ x.share }}</span
                                >
                            </span>
                        </div>
                        <div
                            class="mt-1.5 h-2 overflow-hidden rounded-full bg-sunken"
                        >
                            <div
                                class="h-full rounded-full bg-[var(--out)] opacity-75"
                                :style="{ width: `${x.width}%` }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Profit by client -->
            <section class="panel p-5 sm:p-6">
                <h2 class="text-[16px] font-bold">{{ t('Client ধরে লাভ') }}</h2>
                <p class="text-[12.5px] text-ink-3">
                    {{
                        t(
                            'Client যা দিয়েছে − তাদের ad-এর cost (শুধু Settled)। বড় লাভ আগে।',
                        )
                    }}
                </p>

                <KEmpty
                    v-if="!clients.length"
                    :title="t('এই সময়ে কোনো client-এর হিসাব নেই।')"
                />
                <ul v-else class="mt-4 space-y-4">
                    <li v-for="c in clients" :key="c.id">
                        <div
                            class="flex items-baseline justify-between gap-3 text-[13.5px]"
                        >
                            <Link
                                :href="`/clients/${c.id}`"
                                class="min-w-0 truncate font-semibold hover:text-brand-ink hover:underline"
                                >{{ c.name }}</Link
                            >
                            <span class="shrink-0 whitespace-nowrap">
                                <KAmount
                                    :value="c.margin"
                                    currency="bdt"
                                    class="font-semibold"
                                />
                                <span
                                    class="num ml-2 inline-block w-12 text-right text-[12px]"
                                    :class="
                                        c.margin < 0 ? 'text-out' : 'text-ink-3'
                                    "
                                    >{{ c.pct }}</span
                                >
                            </span>
                        </div>
                        <div
                            class="mt-1.5 h-2 overflow-hidden rounded-full bg-sunken"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="
                                    c.margin >= 0
                                        ? 'bg-[var(--pos)]'
                                        : 'bg-[var(--neg)]'
                                "
                                :style="{ width: `${c.width}%` }"
                            />
                        </div>
                        <p
                            class="mt-1 flex flex-wrap gap-x-3 text-[12px] text-ink-3"
                        >
                            <span class="whitespace-nowrap"
                                >{{ t('আয়') }}
                                <b class="num font-medium text-ink-2">{{
                                    money(c.received, 'bdt')
                                }}</b></span
                            >
                            <span class="whitespace-nowrap"
                                >{{ t('Ad খরচ') }}
                                <b class="num font-medium text-ink-2">{{
                                    money(c.cost_settled, 'bdt')
                                }}</b>
                                <span class="num">
                                    ({{ money(c.usd_settled, 'usd') }})</span
                                ></span
                            >
                            <span
                                v-if="c.usd_pending"
                                class="whitespace-nowrap text-wait"
                                >{{ t('বাকি') }}
                                <b class="num font-medium">{{
                                    money(c.usd_pending, 'usd')
                                }}</b></span
                            >
                        </p>
                    </li>
                </ul>
                <p
                    v-if="pl.unlinked_income"
                    class="mt-4 border-t border-dashed border-line pt-3 text-[12.5px] text-ink-3"
                >
                    {{ t('Client ছাড়া service / অন্যান্য আয়') }}
                    <b class="num font-semibold text-ink-2">{{
                        money(pl.unlinked_income, 'bdt')
                    }}</b>
                </p>
            </section>
        </div>

        <!-- Not profit -->
        <h2 class="eyebrow mt-8 mb-2.5">{{ t('লাভ নয় — মনে রাখুন') }}</h2>
        <section
            class="grid gap-px overflow-hidden rounded-[16px] border border-line bg-line sm:grid-cols-3"
        >
            <div class="bg-surface p-5">
                <p class="eyebrow">{{ t('Client-দের আগাম টাকা') }}</p>
                <p class="num mt-2.5 text-[22px] leading-none font-medium">
                    {{ money(outside.unearned, 'bdt') }}
                </p>
                <p class="mt-2 text-[12.5px] text-ink-3">
                    {{
                        t(
                            'আগাম দিয়েছে, এখনো খরচ হয়নি — client-দের টাকা, আয় নয়। (আজ পর্যন্ত)',
                        )
                    }}
                </p>
            </div>
            <div class="bg-surface p-5">
                <p class="eyebrow">{{ t('Client-দের কাছে পাওনা') }}</p>
                <p
                    class="num mt-2.5 text-[22px] leading-none font-medium"
                    :class="pl.pending_cost > 0 && 'text-wait'"
                >
                    {{ money(pl.pending_cost, 'bdt') }}
                </p>
                <p class="mt-2 text-[12.5px] text-ink-3">
                    {{
                        t(
                            '{amount} খরচ হয়েছে, টাকা আসেনি (কেনা দামে) — টাকা পেলে আয়ে ঢুকবে।',
                            { amount: money(pl.pending_usd, 'usd') },
                        )
                    }}
                </p>
            </div>
            <div class="bg-surface p-5">
                <p class="eyebrow">{{ t('Owner-এর কাছে পাওনা') }}</p>
                <p class="num mt-2.5 text-[22px] leading-none font-medium">
                    {{ money(outside.owner_due, 'bdt') }}
                </p>
                <p class="mt-2 text-[12.5px] text-ink-3">
                    {{ t('Owner নিয়েছেন, ফেরত দেবেন — খরচও নয়, লাভও নয়।') }}
                </p>
            </div>
        </section>

        <p class="mt-4 text-[12px] text-ink-3">
            {{
                t(
                    'Owner-এর তোলা টাকা (drawing) খরচ নয় — তাই P&L-এ নেই, capital থেকে কমে।',
                )
            }}
            {{ t('সময় বাছাই করলে তারিখ ছাড়া পুরনো row গুলো বাদ পড়ে।') }}
        </p>
    </div>
</template>

<style scoped>
/* Keep the bars and tiles coloured on paper. */
.report {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
</style>
