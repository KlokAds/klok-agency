<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KTag from '@/components/k/KTag.vue';
import { useFilters } from '@/composables/useFilters';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

export type RenewalRow = {
    id: number;
    status: string;
    campaign: string | null;
    campaign_id: number;
    client: string | null;
    client_id: number;
    ends_on: string;
    budget: number | null;
    days: number | null;
    usd: number | null;
    invoice: {
        id: number;
        uid: string;
        number: string;
        total: string;
        status: string;
    } | null;
    new_end: string | null;
    decided_at: string | null;
    paid_at: string | null;
    approved_at: string | null;
};

// Every renewal link for a prepaid campaign and where it stands; the paid
// ones that wait for an OK come first.
const props = defineProps<{
    renewals: Paginated<RenewalRow>;
    filters: { status: string };
    counts: Record<string, number>;
    statuses: Record<string, string>;
}>();

const { filters } = useFilters('/renewals', { status: props.filters.status });

const options = computed<Option[]>(() => [
    { value: 'all', label: t('সব') },
    ...['paid', 'open', 'invoiced', 'declined', 'approved'].map((s) => ({
        value: s,
        label:
            t(props.statuses[s]) +
            (props.counts[s] ? ` · ${props.counts[s]}` : ''),
    })),
]);
</script>

<template>
    <SectionTabs section="campaigns" />
    <PageHeader
        :eyebrow="t('Client ও Ads')"
        title="Renewal"
        :lead="
            t(
                'Campaign শেষের ২ দিন আগে client নিজে link পায় — আরও দিন বেছে pay করে, অথবা contract শেষ করে। টাকা এলে আপনার OK-তে Meta-তে শেষের তারিখ বাড়ে।',
            )
        "
    />

    <section class="panel mb-4 p-4 sm:p-5">
        <KSegment v-model="filters.status" :options="options" size="sm" />
    </section>

    <section class="panel overflow-hidden">
        <KEmpty
            v-if="!renewals.data.length"
            :title="t('কোনো renewal নেই')"
            :body="
                t(
                    'Client-এর সাথে যুক্ত, আগে টাকা দেওয়া campaign শেষের ২ দিন আগে এখানে নিজে আসবে।',
                )
            "
        />
        <div v-else class="overflow-x-auto">
            <table
                v-stack
                class="stack ledger w-full min-w-[720px] text-[13px]"
            >
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left">Campaign</th>
                        <th class="px-3 py-3 text-left">{{ t('শেষ') }}</th>
                        <th class="px-3 py-3 text-right">{{ t('দিন') }}</th>
                        <th class="px-3 py-3 text-right">Invoice</th>
                        <th class="px-5 py-3 text-left">{{ t('অবস্থা') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in renewals.data" :key="r.id">
                        <td class="max-w-[320px] px-5 py-3">
                            <Link
                                :href="`/renewals/${r.id}`"
                                class="block truncate font-medium hover:text-brand"
                                >{{ r.campaign }}</Link
                            >
                            <span class="text-[12px] text-ink-3">{{
                                r.client
                            }}</span>
                        </td>
                        <td class="num px-3 py-3">
                            {{ niceDate(r.new_end ?? r.ends_on, false) }}
                        </td>
                        <td class="num px-3 py-3 text-right">
                            {{ r.days ?? '—' }}
                            <span
                                v-if="r.usd"
                                class="block text-[11.5px] text-ink-3"
                                >{{ money(r.usd, 'usd') }}</span
                            >
                        </td>
                        <td class="num px-3 py-3 text-right">
                            <Link
                                v-if="r.invoice"
                                :href="`/invoices/${r.invoice.uid}`"
                                class="hover:text-brand"
                                >{{ r.invoice.number }}</Link
                            >
                            <span v-else>—</span>
                            <span
                                v-if="r.invoice"
                                class="block text-[11.5px] text-ink-3"
                                >{{ r.invoice.total }}</span
                            >
                        </td>
                        <td class="px-5 py-3">
                            <KTag
                                :tone="
                                    r.status === 'paid'
                                        ? 'wait'
                                        : r.status === 'approved'
                                          ? 'in'
                                          : r.status === 'declined'
                                            ? 'out'
                                            : 'neutral'
                                "
                                >{{ t(statuses[r.status] ?? r.status) }}</KTag
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <KPager :page="renewals" :sizes="[10, 25, 50, 100]" />
    </section>
</template>
