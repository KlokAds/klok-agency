<script setup lang="ts">
import { computed } from 'vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

export type InvoiceLine = {
    id: number;
    kind: string;
    description: string;
    period_from: string | null;
    period_to: string | null;
    results: number | null;
    clicks: number | null;
    usd: number | null;
    rate: number | null;
    amount: number;
};

export type InvoiceDocument = {
    number: string;
    client: string | null;
    company: string | null;
    issue_date: string;
    due_date: string | null;
    status: string;
    overdue?: boolean;
    notes: string | null;
    total: number;
    usd: number;
    paid: number;
    due: number;
    items: InvoiceLine[];
};

export type Business = {
    name: string;
    address: string;
    phone: string;
    email: string;
    logo: string | null;
};

// The invoice as the client sees it — the same on the agency's page and
// the public link, and on paper. No cost or margin, only what is billed.
const props = defineProps<{ invoice: InvoiceDocument; business: Business }>();

const STATUS: Record<
    string,
    { label: string; tone: 'neutral' | 'brand' | 'in' | 'out' | 'wait' }
> = {
    sent: { label: 'Sent', tone: 'brand' },
    partial: { label: 'Partial', tone: 'wait' },
    paid: { label: 'Paid', tone: 'in' },
    void: { label: 'Cancel', tone: 'neutral' },
};

const status = computed(() => STATUS[props.invoice.status] ?? STATUS.sent);
const bdt = (poisha: number) => money(poisha, 'bdt', { round: false });
const usd = (cents: number | null) =>
    cents === null ? '—' : money(cents, 'usd');
const rate = (value: number | null) =>
    value === null
        ? '—'
        : String.fromCharCode(0x09f3) +
          value.toLocaleString('en-US', { maximumFractionDigits: 2 });

function period(line: InvoiceLine): string | null {
    if (!line.period_from || !line.period_to) {
        return null;
    }

    return `${niceDate(line.period_from, false)} – ${niceDate(line.period_to)}`;
}

/** "1,240 results · 3,410 clicks · $0.42 / result" */
function outcome(line: InvoiceLine): string | null {
    if (line.kind !== 'ad_spend' || (!line.results && !line.clicks)) {
        return null;
    }

    const parts: string[] = [];

    if (line.results) {
        parts.push(
            t('{n} result', { n: line.results.toLocaleString('en-US') }),
        );
    }

    if (line.clicks) {
        parts.push(t('{n} click', { n: line.clicks.toLocaleString('en-US') }));
    }

    if (line.results && line.usd) {
        parts.push(
            t('প্রতি result {cost}', {
                cost: money(Math.round(line.usd / line.results), 'usd'),
            }),
        );
    }

    return parts.join(' · ');
}

const hasUsd = computed(() => props.invoice.items.some((i) => i.usd !== null));
</script>

<template>
    <article class="inv @container">
        <!-- A thin brand rule, then who sends it and what it is -->
        <div class="inv-rule" />
        <header
            class="flex flex-col gap-6 px-6 pt-7 pb-6 @xl:flex-row @xl:items-start @xl:justify-between @xl:px-10 @xl:pt-9"
        >
            <div class="min-w-0">
                <img
                    v-if="business.logo"
                    :src="business.logo"
                    :alt="business.name"
                    class="mb-3 h-11 w-auto max-w-[190px] object-contain"
                />
                <div v-else class="mb-3 flex items-center gap-2.5">
                    <svg
                        width="36"
                        height="36"
                        viewBox="0 0 64 64"
                        aria-hidden="true"
                    >
                        <rect width="64" height="64" rx="16" fill="#11211e" />
                        <path
                            d="M20 16v32M20 34l14-18M26 27l12 21"
                            fill="none"
                            stroke="#2dd4bf"
                            stroke-width="6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <span
                        class="text-[18px] font-semibold tracking-[-0.02em]"
                        >{{ business.name }}</span
                    >
                </div>
                <p v-if="business.logo" class="text-[15px] font-semibold">
                    {{ business.name }}
                </p>
                <p
                    v-if="business.address"
                    class="inv-muted mt-1 max-w-xs text-[12.5px] leading-relaxed whitespace-pre-line"
                >
                    {{ business.address }}
                </p>
                <p class="inv-muted mt-0.5 text-[12.5px]">
                    <span v-if="business.phone">{{ business.phone }}</span>
                    <span v-if="business.phone && business.email"> · </span>
                    <span v-if="business.email" class="break-all">{{
                        business.email
                    }}</span>
                </p>
            </div>
            <div class="@xl:text-right">
                <p class="inv-title">Invoice</p>
                <p class="num inv-muted mt-1 text-[14px] font-medium">
                    {{ invoice.number }}
                </p>
                <p class="mt-3 flex flex-wrap gap-1.5 @xl:justify-end">
                    <span class="inv-pill" :class="`inv-pill-${status.tone}`">{{
                        t(status.label)
                    }}</span>
                    <span
                        v-if="invoice.overdue"
                        class="inv-pill inv-pill-out"
                        >{{ t('মেয়াদ পার') }}</span
                    >
                </p>
            </div>
        </header>

        <!-- Bill to, dates, and the one number that matters -->
        <section
            class="inv-band grid grid-cols-2 gap-x-6 gap-y-5 px-6 py-5 @xl:grid-cols-[1.4fr_1fr_1fr_1.2fr] @xl:px-10"
        >
            <div class="col-span-2 @xl:col-span-1">
                <p class="inv-label">{{ t('যার জন্য') }}</p>
                <p class="mt-1 text-[15px] font-semibold">
                    {{ invoice.client }}
                </p>
                <p v-if="invoice.company" class="inv-muted text-[12.5px]">
                    {{ invoice.company }}
                </p>
            </div>
            <div>
                <p class="inv-label">{{ t('তারিখ') }}</p>
                <p class="num mt-1 text-[13.5px] font-medium">
                    {{ niceDate(invoice.issue_date) }}
                </p>
            </div>
            <div>
                <p class="inv-label">{{ t('শেষ তারিখ') }}</p>
                <p
                    class="num mt-1 text-[13.5px] font-medium"
                    :class="invoice.overdue ? 'inv-out' : ''"
                >
                    {{
                        invoice.due_date
                            ? niceDate(invoice.due_date)
                            : t('পেলেই')
                    }}
                </p>
            </div>
            <div class="col-span-2 @xl:col-span-1 @xl:text-right">
                <p class="inv-label">
                    {{ invoice.due ? t('দিতে হবে') : t('পরিশোধিত') }}
                </p>
                <p
                    class="num mt-0.5 text-[24px] leading-tight font-semibold tracking-[-0.01em]"
                >
                    {{ bdt(invoice.due ? invoice.due : invoice.total) }}
                </p>
            </div>
        </section>

        <!-- Lines: a table when there is room (and on paper), stacked on a phone -->
        <section class="px-6 pt-2 @xl:px-10">
            <table class="inv-table hidden w-full text-[13px] @xl:table">
                <thead>
                    <tr>
                        <th class="w-8 py-3 pr-2 text-left">#</th>
                        <th class="py-3 pr-4 text-left">{{ t('বিবরণ') }}</th>
                        <th v-if="hasUsd" class="py-3 pr-4 text-right">USD</th>
                        <th v-if="hasUsd" class="py-3 pr-4 text-right">Rate</th>
                        <th class="py-3 text-right">{{ t('পরিমাণ') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, i) in invoice.items" :key="line.id">
                        <td class="num inv-muted py-4 pr-2 align-top">
                            {{ String(i + 1).padStart(2, '0') }}
                        </td>
                        <td class="py-4 pr-4 align-top">
                            <p class="font-semibold">{{ line.description }}</p>
                            <p
                                v-if="period(line)"
                                class="num inv-muted mt-0.5 text-[12px]"
                            >
                                {{ period(line) }}
                            </p>
                            <p
                                v-if="outcome(line)"
                                class="inv-muted mt-0.5 text-[12px]"
                            >
                                {{ outcome(line) }}
                            </p>
                        </td>
                        <td
                            v-if="hasUsd"
                            class="num py-4 pr-4 text-right align-top whitespace-nowrap"
                        >
                            {{ usd(line.usd) }}
                        </td>
                        <td
                            v-if="hasUsd"
                            class="num inv-muted py-4 pr-4 text-right align-top whitespace-nowrap"
                        >
                            {{ line.usd !== null ? rate(line.rate) : '—' }}
                        </td>
                        <td
                            class="num py-4 text-right align-top font-semibold whitespace-nowrap"
                        >
                            {{ bdt(line.amount) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="inv-stack @xl:hidden">
                <li v-for="line in invoice.items" :key="line.id" class="py-4">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 font-semibold break-words">
                            {{ line.description }}
                        </p>
                        <p class="num shrink-0 font-semibold">
                            {{ bdt(line.amount) }}
                        </p>
                    </div>
                    <p
                        v-if="period(line)"
                        class="num inv-muted mt-0.5 text-[12px]"
                    >
                        {{ period(line) }}
                    </p>
                    <p
                        v-if="outcome(line)"
                        class="inv-muted mt-0.5 text-[12px]"
                    >
                        {{ outcome(line) }}
                    </p>
                    <p
                        v-if="line.usd !== null"
                        class="num inv-muted mt-1 text-[12px]"
                    >
                        {{ usd(line.usd) }} × {{ rate(line.rate) }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Notes and totals -->
        <section
            class="flex flex-col gap-6 px-6 pt-2 pb-7 @xl:flex-row @xl:items-start @xl:justify-between @xl:px-10"
        >
            <div class="max-w-sm text-[12.5px]">
                <template v-if="invoice.notes">
                    <p class="inv-label">{{ t('Note') }}</p>
                    <p class="inv-muted mt-1 whitespace-pre-line">
                        {{ invoice.notes }}
                    </p>
                </template>
            </div>
            <dl
                class="inv-totals w-full text-[13px] @xl:ml-auto @xl:max-w-[300px]"
            >
                <div v-if="invoice.usd" class="flex justify-between py-1.5">
                    <dt class="inv-muted">{{ t('Ad খরচ (USD)') }}</dt>
                    <dd class="num">{{ usd(invoice.usd) }}</dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="inv-muted">{{ t('মোট') }}</dt>
                    <dd class="num">{{ bdt(invoice.total) }}</dd>
                </div>
                <div v-if="invoice.paid" class="flex justify-between py-1.5">
                    <dt class="inv-muted">{{ t('পরিশোধ হয়েছে') }}</dt>
                    <dd class="num inv-in">−{{ bdt(invoice.paid) }}</dd>
                </div>
                <div
                    class="inv-due mt-2 flex items-baseline justify-between px-4 py-3"
                >
                    <dt class="font-semibold">{{ t('বাকি') }}</dt>
                    <dd class="num text-[18px] font-semibold">
                        {{ bdt(invoice.due) }}
                    </dd>
                </div>
            </dl>
        </section>

        <footer
            class="inv-foot flex flex-col gap-1 px-6 py-4 text-[11.5px] @xl:flex-row @xl:justify-between @xl:px-10"
        >
            <span>{{
                t('ধন্যবাদ — {name}-এর সাথে কাজ করার জন্য।', {
                    name: business.name,
                })
            }}</span>
            <span class="num"
                >{{ invoice.number }} · {{ niceDate(invoice.issue_date) }}</span
            >
        </footer>
    </article>
</template>

<style scoped>
/* A document with its own ink: white in the light theme and on paper,
   the app's dark surfaces in the dark theme. */
.inv {
    --paper: #ffffff;
    --ink: #111816;
    --muted: #66706d;
    --line: #e6e9e8;
    --band: #f6f8f7;
    --brand: #0f766e;

    /* A column, so beside a taller pay panel the footer sits at the bottom. */
    display: flex;
    flex-direction: column;
    background: var(--paper);
    color: var(--ink);
    border: 1px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
    box-shadow:
        0 1px 2px rgb(0 0 0 / 4%),
        0 8px 24px -12px rgb(0 0 0 / 18%);
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
}

.inv-rule {
    height: 4px;
    background: linear-gradient(90deg, var(--brand), #2dd4bf);
}

.inv-title {
    font-size: 30px;
    line-height: 1;
    font-weight: 600;
    letter-spacing: -0.02em;
}

.inv-muted {
    color: var(--muted);
}

.inv-label {
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 500;
}

.inv-band {
    background: var(--band);
    border-top: 1px solid var(--line);
    border-bottom: 1px solid var(--line);
}

.inv-table thead th {
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 500;
    border-bottom: 1px solid var(--ink);
}

.inv-table tbody td {
    border-bottom: 1px solid var(--line);
}

.inv-stack > li + li {
    border-top: 1px solid var(--line);
}

.inv-due {
    background: var(--ink);
    color: var(--paper);
    border-radius: 10px;
}

.inv-foot {
    margin-top: auto;
    color: var(--muted);
    border-top: 1px solid var(--line);
    background: var(--band);
}

.inv-out {
    color: #b42318;
}

.inv-in {
    color: #067647;
}

.inv-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 11.5px;
    font-weight: 600;
    border: 1px solid var(--line);
    color: var(--muted);
}

.inv-pill-brand {
    color: var(--brand);
    border-color: rgb(15 118 110 / 30%);
    background: rgb(15 118 110 / 6%);
}

.inv-pill-in {
    color: #067647;
    border-color: rgb(6 118 71 / 30%);
    background: rgb(6 118 71 / 6%);
}

.inv-pill-wait {
    color: #b54708;
    border-color: rgb(181 71 8 / 30%);
    background: rgb(181 71 8 / 6%);
}

.inv-pill-out {
    color: #b42318;
    border-color: rgb(180 35 24 / 30%);
    background: rgb(180 35 24 / 6%);
}

.dark .inv {
    --paper: #121b19;
    --ink: #e7eeeb;
    --muted: #8d9a96;
    --line: #26332f;
    --band: #16211f;
    --brand: #2dd4bf;

    box-shadow: none;
}

.dark .inv-due {
    background: #e7eeeb;
    color: #0c1311;
}

.dark .inv-out {
    color: #f97066;
}

.dark .inv-in,
.dark .inv-pill-in {
    color: #47cd89;
}

.dark .inv-pill-wait {
    color: #fdb022;
}

.dark .inv-pill-out {
    color: #f97066;
}

@media print {
    /* Paper is always white, whatever the screen theme. */
    .dark .inv,
    .inv {
        --paper: #ffffff;
        --ink: #111816;
        --muted: #66706d;
        --line: #e6e9e8;
        --band: #f6f8f7;
        --brand: #0f766e;
    }

    .dark .inv-due {
        background: #111816;
        color: #ffffff;
    }

    .inv {
        margin: 14mm;
        border: 0;
        border-radius: 0;
        box-shadow: none;
    }

    /* Paper is wide: always the table. */
    .inv-table {
        display: table !important;
    }

    .inv-stack {
        display: none !important;
    }
}
</style>
