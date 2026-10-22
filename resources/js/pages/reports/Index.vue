<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MonthlyChart from '@/components/MonthlyChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KSegment from '@/components/k/KSegment.vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type ClientRow = { id: number; name: string; usd_settled: number; cost_settled: number; received: number; margin: number; usd_pending: number; cost_pending: number };

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
    monthly: { month: string; label: string; received: number; cost: number; expense: number; result: number; usd_spent: number }[];
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

    router.get('/reports', query, { preserveState: true, preserveScroll: true, replace: true });
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

const periodLabel = computed(() => (props.period.from ? `${niceDate(props.period.from)} — ${niceDate(props.period.to)}` : t('শুরু থেকে আজ পর্যন্ত')));
function printPage(): void {
    window.print();
}

const rows = computed(() => props.pl.clients.filter((c) => c.received || c.usd_settled || c.usd_pending));
</script>

<template>
    <PageHeader :eyebrow="t('হিসাব')" title="Profit & Loss" :lead="t('প্রতিটা USD খরচ তার নিজের batch-এর rate-এ cost ধরা (FIFO)। Client যা দিয়েছে তার থেকে cost বাদ দিলে আসল margin।')">
        <KButton icon="print" @click="printPage">Print / PDF</KButton>
        <KButton v-if="can['reports.export']" :href="exportUrl" external icon="down">CSV</KButton>
    </PageHeader>

    <section class="no-print mb-6 flex flex-wrap items-center gap-3">
        <KSegment v-model="preset" :options="presets" size="sm" />
        <div v-if="preset === 'custom'" class="flex items-center gap-2">
            <KDate v-model="from" :placeholder="t('শুরু')" size="sm" clearable />
            <span class="text-ink-3">—</span>
            <KDate v-model="to" :placeholder="t('শেষ')" size="sm" clearable />
        </div>
    </section>

    <!-- Printable statement -->
    <article class="panel overflow-hidden">
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-line bg-surface-2 px-5 py-5 sm:px-8">
            <div>
                <p class="eyebrow">Klok Ads · Profit &amp; Loss</p>
                <p class="mt-1 text-[15px] font-semibold">{{ periodLabel }}</p>
            </div>
            <div class="text-right">
                <p class="eyebrow">Net profit / (loss)</p>
                <p class="mt-1 text-[28px] leading-none font-medium"><KAmount :value="pl.net" currency="bdt" sign /></p>
            </div>
        </header>

        <div class="px-5 py-6 sm:px-8">
            <h2 class="eyebrow mb-3">{{ t('১. Revenue — client-ভিত্তিক margin (শুধু Settled)') }}</h2>
            <div class="overflow-x-auto">
                <table class="ledger">
                    <thead>
                        <tr><th>Client</th><th class="r">{{ t('USD খরচ') }}</th><th class="r">FIFO cost</th><th class="r">{{ t('BDT পেয়েছি') }}</th><th class="r">Margin</th><th class="r">Pending</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in rows" :key="c.id">
                            <td class="font-medium">{{ c.name }}</td>
                            <td class="r num">{{ c.usd_settled ? money(c.usd_settled, 'usd') : '–' }}</td>
                            <td class="r num">{{ c.cost_settled ? money(c.cost_settled, 'bdt') : '–' }}</td>
                            <td class="r num">{{ money(c.received, 'bdt') }}</td>
                            <td class="r font-semibold"><KAmount :value="c.margin" currency="bdt" /></td>
                            <td class="r num text-wait">{{ c.usd_pending ? money(c.usd_pending, 'usd') : '–' }}</td>
                        </tr>
                        <tr v-if="pl.unlinked_income">
                            <td class="font-medium">{{ t('অন্যান্য আয়') }}</td><td /><td /><td class="r num">{{ money(pl.unlinked_income, 'bdt') }}</td><td class="r num">{{ money(pl.unlinked_income, 'bdt') }}</td><td />
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td class="border-t-2 border-ink px-3 py-3">{{ t('মোট margin') }}</td><td class="border-t-2 border-ink" /><td class="border-t-2 border-ink" /><td class="border-t-2 border-ink" />
                            <td class="r num border-t-2 border-ink px-3 py-3">{{ money(pl.margin, 'bdt') }}</td>
                            <td class="r num border-t-2 border-ink px-3 py-3 text-wait">{{ pl.pending_usd ? money(pl.pending_usd, 'usd') : '' }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <h2 class="eyebrow mt-8 mb-3">{{ t('২. Expenses') }}</h2>
            <dl class="max-w-xl text-[14px]">
                <div class="flex justify-between border-b border-dashed border-line py-2.5">
                    <dt>{{ t('নিজের USD খরচ') }} <span class="num text-ink-3">({{ money(pl.own_usd, 'usd') }} — KlokAds ad + research)</span></dt>
                    <dd class="num">{{ money(pl.own_cost, 'bdt') }}</dd>
                </div>
                <div v-for="x in pl.expenses" :key="x.name" class="flex justify-between border-b border-dashed border-line py-2.5">
                    <dt>{{ t(x.name) }}</dt>
                    <dd class="num">{{ money(x.amount, 'bdt') }}</dd>
                </div>
                <div class="flex justify-between py-3 font-semibold">
                    <dt>{{ t('মোট খরচ') }}</dt>
                    <dd class="num">{{ money(pl.total_expense, 'bdt') }}</dd>
                </div>
            </dl>

            <h2 class="eyebrow mt-8 mb-3">{{ t('৩. ফলাফল') }}</h2>
            <dl class="max-w-xl rounded-xl bg-surface-2 px-4 text-[14.5px]">
                <div class="flex justify-between border-b border-dashed border-line py-2.5"><dt>{{ t('মোট margin') }}</dt><dd class="num">{{ money(pl.margin, 'bdt') }}</dd></div>
                <div class="flex justify-between border-b border-dashed border-line py-2.5"><dt>{{ t('বাদ: মোট খরচ') }}</dt><dd class="num">{{ money(-pl.total_expense, 'bdt') }}</dd></div>
                <div class="flex justify-between py-3 text-[16px] font-bold"><dt>Net profit / (loss)</dt><dd><KAmount :value="pl.net" currency="bdt" sign /></dd></div>
            </dl>
            <p v-if="pl.pending_cost" class="mt-3 text-[13px] text-ink-3">{{ t('এখনো বাকি (client দেবে):') }} <b class="num text-ink-2">{{ money(pl.pending_cost, 'bdt') }}</b> {{ t('cost — এই টাকা পেলে Settled হয়ে margin-এ ঢুকবে।') }}</p>
            <ul class="mt-6 list-disc space-y-1 pl-5 text-[12.5px] text-ink-3">
                <li>{{ t('Owner-এর তোলা টাকা (drawing) খরচ নয় — তাই P&L-এ নেই, capital থেকে কমে।') }}</li>
                <li>{{ t('নিজের বা টিমের সময়ের দাম এখানে ধরা নেই, শুধু যে বেতন দেওয়া হয়েছে তা আছে।') }}</li>
                <li>{{ t('সময় বাছাই করলে তারিখ ছাড়া পুরনো row গুলো বাদ পড়ে।') }}</li>
            </ul>
        </div>
    </article>

    <section class="panel no-print mt-6 p-5 sm:p-6">
        <h2 class="mb-4 text-[16px] font-bold">{{ t('শেষ ১২ মাস') }}</h2>
        <MonthlyChart :rows="monthly" />
    </section>
</template>
