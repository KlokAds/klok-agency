<script setup lang="ts">
import { computed } from 'vue';
import { isoDate, money, niceDate, today } from '@/lib/format';
import type { Currency } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * A campaign's days as a table, newest first: date, spend, results, cost per result — and the
 * clicks once the screen is wide enough, so a phone never scrolls sideways.
 */
const props = defineProps<{
    days: {
        day: string;
        spend: number;
        results: number;
        clicks: number;
        cpr: number | null;
    }[];
    currency?: string;
    resultName: string;
}>();

const cur = computed<Currency>(() =>
    props.currency === 'BDT' ? 'bdt' : 'usd',
);
const now = today();
const yesterday = isoDate(new Date(Date.now() - 86_400_000));

function when(day: string): string {
    if (day === now) {
        return t('আজ');
    }
    if (day === yesterday) {
        return t('গতকাল');
    }
    return niceDate(day, false);
}
</script>

<template>
    <table class="w-full text-[14px]">
        <thead class="bg-surface-2 text-left text-[13px] text-ink-3">
            <tr>
                <th class="px-4 py-2 font-medium whitespace-nowrap sm:px-5">
                    {{ t('তারিখ') }}
                </th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">
                    {{ t('খরচ') }}
                </th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">
                    {{ resultName }}
                </th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">
                    {{ t('প্রতি result') }}
                </th>
                <th
                    class="hidden px-5 py-2 text-right font-medium whitespace-nowrap sm:table-cell"
                >
                    Click
                </th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="d in days"
                :key="d.day"
                class="border-t border-line"
                :class="d.spend === 0 ? 'text-ink-3' : ''"
            >
                <td class="px-4 py-2.5 font-semibold whitespace-nowrap sm:px-5">
                    {{ when(d.day) }}
                </td>
                <td class="num px-2 py-2.5 text-right font-semibold">
                    {{ money(d.spend, cur) }}
                </td>
                <td class="num px-2 py-2.5 text-right">{{ d.results }}</td>
                <td class="num px-2 py-2.5 text-right">
                    {{ d.cpr !== null ? money(d.cpr, cur) : '—' }}
                </td>
                <td class="num hidden px-5 py-2.5 text-right sm:table-cell">
                    {{ d.clicks }}
                </td>
            </tr>
        </tbody>
    </table>
</template>
