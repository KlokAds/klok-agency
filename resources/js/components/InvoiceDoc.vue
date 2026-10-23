<script setup lang="ts">
import { computed } from 'vue';
import KTag from '@/components/k/KTag.vue';
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
    draft: { label: 'Draft', tone: 'neutral' },
    sent: { label: 'পরিশোধ বাকি', tone: 'brand' }, // i18n
    partial: { label: 'আংশিক পরিশোধ', tone: 'wait' }, // i18n
    paid: { label: 'পরিশোধিত', tone: 'in' }, // i18n
    overdue: { label: 'মেয়াদ পার', tone: 'out' }, // i18n
    void: { label: 'বাতিল', tone: 'neutral' }, // i18n
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
    <article class="invoice-doc panel overflow-hidden">
        <!-- Header -->
        <header
            class="flex flex-col gap-6 border-b border-line p-5 sm:flex-row sm:items-start sm:justify-between sm:p-8"
        >
            <div class="min-w-0">
                <img
                    v-if="business.logo"
                    :src="business.logo"
                    :alt="business.name"
                    class="mb-3 h-12 w-auto max-w-[200px] object-contain"
                />
                <div v-else class="mb-3 flex items-center gap-2.5">
                    <svg
                        width="40"
                        height="40"
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
                    <span class="text-[19px] font-bold tracking-[-0.02em]">{{
                        business.name
                    }}</span>
                </div>
                <p
                    v-if="business.logo"
                    class="text-[15px] font-bold tracking-tight"
                >
                    {{ business.name }}
                </p>
                <p
                    v-if="business.address"
                    class="mt-1 max-w-xs text-[12.5px] whitespace-pre-line text-ink-3"
                >
                    {{ business.address }}
                </p>
                <p class="mt-1 text-[12.5px] text-ink-3">
                    <span v-if="business.phone">{{ business.phone }}</span>
                    <span v-if="business.phone && business.email"> · </span>
                    <span v-if="business.email" class="break-all">{{
                        business.email
                    }}</span>
                </p>
            </div>
            <div class="sm:text-right">
                <p class="eyebrow">Invoice</p>
                <p class="num mt-1 text-[26px] leading-none font-semibold">
                    {{ invoice.number }}
                </p>
                <div class="mt-3">
                    <KTag :tone="status.tone">{{ t(status.label) }}</KTag>
                </div>
            </div>
        </header>

        <!-- Who and when -->
        <section
            class="grid grid-cols-2 gap-x-6 gap-y-4 border-b border-line bg-surface-2 px-5 py-5 text-[13px] sm:grid-cols-3 sm:px-8"
        >
            <div class="col-span-2 sm:col-span-1">
                <p class="eyebrow">{{ t('যার জন্য') }}</p>
                <p class="mt-1 text-[15px] font-semibold">
                    {{ invoice.client }}
                </p>
                <p v-if="invoice.company" class="text-ink-3">
                    {{ invoice.company }}
                </p>
            </div>
            <div>
                <p class="eyebrow">{{ t('তারিখ') }}</p>
                <p class="num mt-1 font-medium">
                    {{ niceDate(invoice.issue_date) }}
                </p>
            </div>
            <div>
                <p class="eyebrow">{{ t('শেষ তারিখ') }}</p>
                <p
                    class="num mt-1 font-medium"
                    :class="invoice.status === 'overdue' ? 'text-out' : ''"
                >
                    {{
                        invoice.due_date
                            ? niceDate(invoice.due_date)
                            : t('পেলেই')
                    }}
                </p>
            </div>
        </section>

        <!-- Lines: a table on wide screens, stacked rows on a phone -->
        <section class="px-5 sm:px-8">
            <table class="hidden w-full text-[13.5px] md:table">
                <thead>
                    <tr
                        class="border-b border-line text-left text-[12.5px] font-medium text-ink-3"
                    >
                        <th class="py-3 pr-4">{{ t('বিবরণ') }}</th>
                        <th v-if="hasUsd" class="py-3 pr-4 text-right">USD</th>
                        <th v-if="hasUsd" class="py-3 pr-4 text-right">Rate</th>
                        <th class="py-3 text-right">BDT</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in invoice.items"
                        :key="line.id"
                        class="border-b border-line align-top last:border-0"
                    >
                        <td class="py-3.5 pr-4">
                            <p class="font-semibold">{{ line.description }}</p>
                            <p
                                v-if="period(line)"
                                class="num mt-0.5 text-[12px] text-ink-3"
                            >
                                {{ period(line) }}
                            </p>
                            <p
                                v-if="outcome(line)"
                                class="mt-0.5 text-[12px] text-ink-3"
                            >
                                {{ outcome(line) }}
                            </p>
                        </td>
                        <td
                            v-if="hasUsd"
                            class="num py-3.5 pr-4 text-right whitespace-nowrap"
                        >
                            {{ usd(line.usd) }}
                        </td>
                        <td
                            v-if="hasUsd"
                            class="num py-3.5 pr-4 text-right whitespace-nowrap text-ink-2"
                        >
                            {{ line.usd !== null ? rate(line.rate) : '—' }}
                        </td>
                        <td
                            class="num py-3.5 text-right font-medium whitespace-nowrap"
                        >
                            {{ bdt(line.amount) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="divide-y divide-dashed divide-line md:hidden">
                <li v-for="line in invoice.items" :key="line.id" class="py-3.5">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 font-semibold break-words">
                            {{ line.description }}
                        </p>
                        <p class="num shrink-0 font-medium">
                            {{ bdt(line.amount) }}
                        </p>
                    </div>
                    <p
                        v-if="period(line)"
                        class="num mt-0.5 text-[12px] text-ink-3"
                    >
                        {{ period(line) }}
                    </p>
                    <p
                        v-if="outcome(line)"
                        class="mt-0.5 text-[12px] text-ink-3"
                    >
                        {{ outcome(line) }}
                    </p>
                    <p
                        v-if="line.usd !== null"
                        class="num mt-1 text-[12px] text-ink-2"
                    >
                        {{ usd(line.usd) }} × {{ rate(line.rate) }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Totals -->
        <section
            class="flex flex-col gap-6 border-t border-line px-5 py-5 sm:flex-row sm:justify-between sm:px-8"
        >
            <p
                v-if="invoice.notes"
                class="max-w-sm text-[12.5px] whitespace-pre-line text-ink-2"
            >
                {{ invoice.notes }}
            </p>
            <span v-else />
            <dl class="w-full max-w-xs space-y-1.5 text-[13.5px] sm:ml-auto">
                <div v-if="invoice.usd" class="flex justify-between text-ink-3">
                    <dt>{{ t('Ad খরচ (USD)') }}</dt>
                    <dd class="num">{{ usd(invoice.usd) }}</dd>
                </div>
                <div class="flex justify-between text-ink-2">
                    <dt>{{ t('মোট') }}</dt>
                    <dd class="num">{{ bdt(invoice.total) }}</dd>
                </div>
                <div v-if="invoice.paid" class="flex justify-between text-in">
                    <dt>{{ t('পরিশোধ হয়েছে') }}</dt>
                    <dd class="num">−{{ bdt(invoice.paid) }}</dd>
                </div>
                <div
                    class="flex justify-between border-t border-line pt-2 text-[17px] font-bold"
                >
                    <dt>{{ t('বাকি') }}</dt>
                    <dd class="num">{{ bdt(invoice.due) }}</dd>
                </div>
            </dl>
        </section>
    </article>
</template>
