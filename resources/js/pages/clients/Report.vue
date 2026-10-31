<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Business } from '@/components/InvoiceDoc.vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import RangePicker from '@/components/portal/RangePicker.vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * A client's report for a period, laid out as a document: the agency's letterhead, the
 * figures that matter, campaign by campaign, month by month, the payments, and where the
 * account stands. One button prints it (or saves it as a PDF). Nothing of the agency's own
 * cost, rate or margin is on it, so it can be handed to the client as it is.
 */
type CampaignRow = {
    id: number;
    name: string;
    account: string | null;
    status: 'live' | 'ended' | 'off';
    days: number;
    first: string | null;
    last: string | null;
    spend: number;
    results: number;
    clicks: number;
    cpr: number | null;
};
type DayRow = {
    day: string;
    spend: number;
    results: number;
    clicks: number;
    campaigns: number;
};
type Month = { month: string; spend: number; results: number; days: DayRow[] };
type Payment = {
    id: number;
    date: string | null;
    description: string;
    via: string | null;
    amount: number;
    usd: number | null;
    refund: boolean;
};
type InvoiceRow = {
    id: number;
    uid: string;
    number: string;
    issue_date: string;
    status: string;
    total: number;
    paid: number;
    due: number;
};

const props = defineProps<{
    client: {
        id: number;
        name: string;
        company: string | null;
        phone: string | null;
        email: string | null;
    };
    business: Business;
    range: { key: string; from: string; to: string };
    generated_at: string;
    summary: {
        spend: number;
        results: number;
        clicks: number;
        days: number;
        paid: number;
        refunded: number;
        campaigns: number;
    };
    standing: {
        credit_usd: number;
        spent_usd: number;
        left_usd: number;
        owed_usd: number;
        daily_usd: number;
        days_left: number | null;
    };
    campaigns: CampaignRow[];
    months: Month[];
    payments: Payment[];
    invoices: InvoiceRow[];
}>();

const usd = (cents: number) => money(cents, 'usd');
const bdt = (poisha: number) => money(poisha, 'bdt');
const n = (v: number) => v.toLocaleString('en-US');

const period = computed(() =>
    props.range.from === props.range.to
        ? niceDate(props.range.from)
        : `${niceDate(props.range.from)} – ${niceDate(props.range.to)}`,
);

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];
const monthName = (ym: string) =>
    `${MONTHS[Number(ym.slice(5, 7)) - 1]} ${ym.slice(0, 4)}`;

const STATUS: Record<CampaignRow['status'], { label: string; cls: string }> = {
    live: { label: 'চলছে', cls: 'rep-pill rep-pill-in' }, // i18n
    off: { label: 'বন্ধ', cls: 'rep-pill rep-pill-wait' }, // i18n
    ended: { label: 'শেষ', cls: 'rep-pill' }, // i18n
};

// The one line that says where the account stands.
const standingLine = computed(() => {
    const s = props.standing;
    if (s.owed_usd > 0) {
        return {
            tone: 'out',
            text: t('{amount} বাকি আছে — এই খরচের টাকা এখনো দেওয়া হয়নি।', {
                amount: usd(s.owed_usd),
            }),
        };
    }
    if (s.left_usd > 0) {
        return {
            tone: 'in',
            text:
                s.days_left !== null
                    ? t(
                          '{amount} credit বাকি — রোজ গড়ে {daily} খরচে আরো প্রায় {days} দিন।',
                          {
                              amount: usd(s.left_usd),
                              daily: usd(s.daily_usd),
                              days: s.days_left,
                          },
                      )
                    : t('{amount} credit বাকি আছে।', {
                          amount: usd(s.left_usd),
                      }),
        };
    }
    return { tone: 'muted', text: t('কোনো বাকি নেই, credit-ও শেষ।') };
});

function print(): void {
    window.print();
}
</script>

<template>
    <Head :title="`${t('Report')} · ${client.name}`" />

    <div class="rep-page">
        <!-- On screen: the period and the print button; neither is on paper -->
        <!-- On screen: back, the print button, and the period on its own line; none of it on paper -->
        <div class="no-print mx-auto mb-5 max-w-[900px] px-4 pt-5 sm:px-0">
            <div class="flex items-center justify-between gap-3">
                <Link
                    :href="`/clients/${client.id}`"
                    class="inline-flex items-center gap-1.5 text-[14px] font-medium text-brand-ink hover:underline"
                >
                    <KIcon name="back" :size="15" />{{ client.name }}
                </Link>
                <KButton variant="primary" icon="print" @click="print">{{
                    t('Print / PDF')
                }}</KButton>
            </div>
            <div class="mt-3">
                <RangePicker
                    :url="`/clients/${client.id}/report`"
                    :range="range"
                    dense
                />
            </div>
        </div>

        <article class="rep mx-auto max-w-[900px]">
            <div class="rep-rule" />

            <!-- Letterhead -->
            <header
                class="flex flex-col gap-5 px-6 pt-7 pb-6 sm:flex-row sm:items-start sm:justify-between sm:px-10 sm:pt-9"
            >
                <div class="min-w-0">
                    <img
                        v-if="business.logo"
                        :src="business.logo"
                        :alt="business.name"
                        class="mb-3 h-11 w-auto max-w-[190px] object-contain"
                    />
                    <p class="text-[18px] font-semibold tracking-[-0.02em]">
                        {{ business.name }}
                    </p>
                    <p
                        v-if="business.address"
                        class="rep-muted mt-1 max-w-xs text-[13px] leading-relaxed whitespace-pre-line"
                    >
                        {{ business.address }}
                    </p>
                    <p class="rep-muted mt-0.5 text-[13px]">
                        <span v-if="business.phone">{{ business.phone }}</span>
                        <span v-if="business.phone && business.email"> · </span>
                        <span v-if="business.email">{{ business.email }}</span>
                    </p>
                </div>
                <div class="sm:text-right">
                    <p class="rep-title">{{ t('Client Report') }}</p>
                    <p class="num rep-muted mt-1 text-[14px]">{{ period }}</p>
                    <p class="rep-muted mt-0.5 text-[12.5px]">
                        {{ t('তৈরি') }}
                        {{ niceDate(generated_at.slice(0, 10)) }}
                    </p>
                </div>
            </header>

            <!-- Who it is for, and the four figures of the period -->
            <section
                class="rep-band grid grid-cols-2 gap-x-6 gap-y-5 px-6 py-5 sm:grid-cols-[1.3fr_1fr_1fr_1fr_1fr] sm:px-10"
            >
                <div class="col-span-2 sm:col-span-1">
                    <p class="rep-label">{{ t('Client') }}</p>
                    <p class="mt-1 text-[16px] font-semibold">
                        {{ client.name }}
                    </p>
                    <p v-if="client.company" class="rep-muted text-[13px]">
                        {{ client.company }}
                    </p>
                    <p
                        v-if="client.phone || client.email"
                        class="rep-muted text-[13px]"
                    >
                        {{
                            [client.phone, client.email]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </p>
                </div>
                <div>
                    <p class="rep-label">{{ t('Ad খরচ') }}</p>
                    <p class="num mt-1 text-[18px] font-semibold">
                        {{ usd(summary.spend) }}
                    </p>
                    <p class="rep-muted text-[12.5px]">
                        {{
                            t('{n} দিন · {c}টা campaign', {
                                n: summary.days,
                                c: summary.campaigns,
                            })
                        }}
                    </p>
                </div>
                <div>
                    <p class="rep-label">{{ t('ফলাফল') }}</p>
                    <p class="num mt-1 text-[18px] font-semibold">
                        {{ n(summary.results) }}
                    </p>
                    <p class="rep-muted text-[12.5px]">
                        {{ t('{n} click', { n: n(summary.clicks) }) }}
                    </p>
                </div>
                <div>
                    <p class="rep-label">{{ t('দিয়েছেন') }}</p>
                    <p class="num mt-1 text-[18px] font-semibold">
                        {{ bdt(summary.paid) }}
                    </p>
                    <p v-if="summary.refunded" class="rep-muted text-[12.5px]">
                        {{
                            t('ফেরত {amount}', {
                                amount: bdt(summary.refunded),
                            })
                        }}
                    </p>
                </div>
                <div class="sm:text-right">
                    <p class="rep-label">{{ t('এখন') }}</p>
                    <p
                        class="num mt-1 text-[18px] font-semibold"
                        :class="standing.owed_usd > 0 ? 'rep-out' : 'rep-in'"
                    >
                        {{
                            standing.owed_usd > 0
                                ? usd(standing.owed_usd)
                                : usd(standing.left_usd)
                        }}
                    </p>
                    <p class="rep-muted text-[12.5px]">
                        {{
                            standing.owed_usd > 0 ? t('বাকি') : t('credit বাকি')
                        }}
                    </p>
                </div>
            </section>

            <!-- Campaigns -->
            <section class="px-6 pt-7 sm:px-10">
                <h2 class="rep-h">{{ t('Campaign ধরে') }}</h2>
                <p
                    v-if="!campaigns.length"
                    class="rep-muted mt-2 text-[13.5px]"
                >
                    {{ t('এই সময়ে কোনো campaign চলেনি।') }}
                </p>
                <table v-else class="rep-table num mt-3 w-full text-[13.5px]">
                    <thead>
                        <tr>
                            <th class="text-left font-sans">
                                {{ t('Campaign') }}
                            </th>
                            <th class="text-left font-sans">{{ t('সময়') }}</th>
                            <th class="text-right">{{ t('দিন') }}</th>
                            <th class="text-right">{{ t('খরচ') }}</th>
                            <th class="text-right">{{ t('ফলাফল') }}</th>
                            <th class="text-right">{{ t('প্রতি result') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in campaigns" :key="c.id">
                            <td class="font-sans">
                                <span class="font-semibold">{{ c.name }}</span>
                                <span
                                    :class="STATUS[c.status].cls"
                                    class="ml-2"
                                    >{{ t(STATUS[c.status].label) }}</span
                                >
                                <span
                                    v-if="c.account"
                                    class="rep-muted block text-[12px]"
                                    >{{ c.account }}</span
                                >
                            </td>
                            <td class="rep-muted whitespace-nowrap">
                                <template v-if="c.first && c.last"
                                    >{{ niceDate(c.first, false) }} –
                                    {{ niceDate(c.last, false) }}</template
                                >
                                <template v-else>—</template>
                            </td>
                            <td class="text-right">{{ c.days }}</td>
                            <td class="text-right font-semibold">
                                {{ usd(c.spend) }}
                            </td>
                            <td class="text-right">{{ n(c.results) }}</td>
                            <td class="text-right">
                                {{ c.cpr === null ? '—' : usd(c.cpr) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-sans font-semibold" colspan="2">
                                {{ t('মোট') }}
                            </td>
                            <td class="text-right font-semibold">
                                {{ summary.days }}
                            </td>
                            <td class="text-right font-semibold">
                                {{ usd(summary.spend) }}
                            </td>
                            <td class="text-right font-semibold">
                                {{ n(summary.results) }}
                            </td>
                            <td class="text-right font-semibold">
                                {{
                                    summary.results
                                        ? usd(
                                              Math.round(
                                                  summary.spend /
                                                      summary.results,
                                              ),
                                          )
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <!-- Day by day, month by month -->
            <section v-if="months.length" class="px-6 pt-7 sm:px-10">
                <h2 class="rep-h">{{ t('দিনে দিনে খরচ') }}</h2>
                <div v-for="m in months" :key="m.month" class="rep-month mt-3">
                    <div
                        class="flex items-baseline justify-between gap-3 py-1.5"
                    >
                        <p class="text-[14px] font-semibold">
                            {{ monthName(m.month) }}
                        </p>
                        <p class="num text-[13.5px]">
                            <span class="rep-muted">{{
                                t('{n} result', { n: n(m.results) })
                            }}</span>
                            <span class="ml-3 font-semibold">{{
                                usd(m.spend)
                            }}</span>
                        </p>
                    </div>
                    <div
                        class="rep-days num grid grid-cols-2 gap-x-6 text-[13px] sm:grid-cols-3"
                    >
                        <div
                            v-for="d in m.days"
                            :key="d.day"
                            class="flex items-baseline justify-between gap-2 py-1"
                        >
                            <span class="rep-muted">{{
                                niceDate(d.day, false)
                            }}</span>
                            <span>
                                <span
                                    v-if="d.results"
                                    class="rep-muted mr-2 text-[12px]"
                                    >{{ n(d.results) }}</span
                                >
                                <span :class="d.spend ? '' : 'rep-muted'">{{
                                    usd(d.spend)
                                }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Payments and invoices -->
            <section
                class="grid grid-cols-1 gap-7 px-6 pt-7 sm:grid-cols-2 sm:px-10"
            >
                <div>
                    <h2 class="rep-h">{{ t('পেমেন্ট') }}</h2>
                    <p
                        v-if="!payments.length"
                        class="rep-muted mt-2 text-[13.5px]"
                    >
                        {{ t('এই সময়ে কোনো পেমেন্ট নেই।') }}
                    </p>
                    <table
                        v-else
                        class="rep-table num mt-3 w-full text-[13.5px]"
                    >
                        <thead>
                            <tr>
                                <th class="text-left">{{ t('তারিখ') }}</th>
                                <th class="text-left font-sans">
                                    {{ t('কীভাবে') }}
                                </th>
                                <th class="text-right">{{ t('টাকা') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in payments" :key="p.id">
                                <td class="whitespace-nowrap">
                                    {{ p.date ? niceDate(p.date, false) : '—' }}
                                </td>
                                <td class="font-sans">
                                    {{
                                        p.via ??
                                        (p.refund ? t('ফেরত') : t('পেমেন্ট'))
                                    }}
                                    <span
                                        v-if="p.usd"
                                        class="rep-muted num block text-[12px]"
                                        >{{
                                            t('{amount} ad credit', {
                                                amount: usd(p.usd),
                                            })
                                        }}</span
                                    >
                                </td>
                                <td
                                    class="text-right font-semibold"
                                    :class="p.refund && 'rep-out'"
                                >
                                    {{ p.refund ? '−' : ''
                                    }}{{ bdt(Math.abs(p.amount)) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="font-sans font-semibold" colspan="2">
                                    {{ t('মোট') }}
                                </td>
                                <td class="text-right font-semibold">
                                    {{ bdt(summary.paid - summary.refunded) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div>
                    <h2 class="rep-h">{{ t('Invoice') }}</h2>
                    <p
                        v-if="!invoices.length"
                        class="rep-muted mt-2 text-[13.5px]"
                    >
                        {{ t('এই সময়ে কোনো invoice নেই।') }}
                    </p>
                    <table
                        v-else
                        class="rep-table num mt-3 w-full text-[13.5px]"
                    >
                        <thead>
                            <tr>
                                <th class="text-left">{{ t('নম্বর') }}</th>
                                <th class="text-left">{{ t('তারিখ') }}</th>
                                <th class="text-right">{{ t('মোট') }}</th>
                                <th class="text-right">{{ t('বাকি') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in invoices" :key="i.id">
                                <td class="font-semibold">{{ i.number }}</td>
                                <td class="whitespace-nowrap">
                                    {{ niceDate(i.issue_date, false) }}
                                </td>
                                <td class="text-right">{{ bdt(i.total) }}</td>
                                <td
                                    class="text-right"
                                    :class="
                                        i.due > 0
                                            ? 'rep-out font-semibold'
                                            : 'rep-muted'
                                    "
                                >
                                    {{ i.due > 0 ? bdt(i.due) : t('পরিশোধিত') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Where it stands -->
            <section class="px-6 pt-7 pb-8 sm:px-10">
                <div
                    class="rep-stand"
                    :class="`rep-stand-${standingLine.tone}`"
                >
                    <p class="rep-label">{{ t('Account-এর অবস্থা, আজ') }}</p>
                    <p class="mt-1 text-[15px] font-semibold">
                        {{ standingLine.text }}
                    </p>
                    <p class="num rep-muted mt-2 text-[13px]">
                        {{
                            t('মোট credit {credit} · খরচ হয়েছে {spent}', {
                                credit: usd(standing.credit_usd),
                                spent: usd(standing.spent_usd),
                            })
                        }}
                    </p>
                </div>
            </section>

            <footer class="rep-foot px-6 py-4 text-[12.5px] sm:px-10">
                <p>
                    {{
                        t(
                            'এই report-এ ad-এর খরচ Meta-র হিসাবে, USD-তে; পেমেন্ট টাকায়। প্রশ্ন থাকলে {name}-এর সাথে কথা বলুন।',
                            { name: business.name },
                        )
                    }}
                </p>
            </footer>
        </article>
    </div>
</template>

<style scoped>
.rep-page {
    padding-bottom: 40px;
}

.rep {
    --paper: #ffffff;
    --ink: #16141f;
    --muted: #6b6878;
    --line: #e7e3ee;
    --band: #f6f5f9;
    --brand: #5a3b82;
    --brand-2: #a07fbd;

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

.rep-rule {
    height: 4px;
    background: linear-gradient(90deg, var(--brand), var(--brand-2));
}

.rep-title {
    font-size: 26px;
    line-height: 1;
    font-weight: 600;
    letter-spacing: -0.02em;
}

.rep-muted {
    color: var(--muted);
}

.rep-label {
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.rep-band {
    background: var(--band);
    border-top: 1px solid var(--line);
    border-bottom: 1px solid var(--line);
}

.rep-h {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--brand);
    display: flex;
    align-items: center;
    gap: 10px;
}

.rep-h::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--line);
}

.rep-table th {
    padding: 6px 8px;
    color: var(--muted);
    font-size: 11.5px;
    font-weight: 600;
    border-bottom: 1px solid var(--ink);
}

.rep-table td {
    padding: 8px;
    border-bottom: 1px solid var(--line);
    vertical-align: top;
}

.rep-table tfoot td {
    border-bottom: 0;
    border-top: 1px solid var(--ink);
    background: var(--band);
}

.rep-month {
    break-inside: avoid;
    border-top: 1px solid var(--line);
}

.rep-days > div + div,
.rep-days > div {
    border-bottom: 1px dashed var(--line);
}

.rep-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 1px 8px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid var(--line);
    color: var(--muted);
    vertical-align: middle;
}

.rep-pill-in {
    color: #067647;
    border-color: rgb(6 118 71 / 30%);
    background: rgb(6 118 71 / 6%);
}

.rep-pill-wait {
    color: #b54708;
    border-color: rgb(181 71 8 / 30%);
    background: rgb(181 71 8 / 6%);
}

.rep-out {
    color: #b42318;
}

.rep-in {
    color: #067647;
}

.rep-stand {
    border-radius: 12px;
    padding: 14px 18px;
    border: 1px solid var(--line);
    background: var(--band);
}

.rep-stand-in {
    border-color: rgb(6 118 71 / 30%);
    background: rgb(6 118 71 / 5%);
}

.rep-stand-out {
    border-color: rgb(180 35 24 / 30%);
    background: rgb(180 35 24 / 5%);
}

.rep-foot {
    color: var(--muted);
    border-top: 1px solid var(--line);
    background: var(--band);
}

/* On screen in dark mode the paper is still paper: a report is read on white. */
@media print {
    .rep-page {
        padding: 0;
    }

    .rep {
        max-width: none;
        margin: 0;
        border: 0;
        border-radius: 0;
        box-shadow: none;
    }

    .rep-month,
    .rep-stand,
    table {
        break-inside: avoid;
    }
}
</style>
