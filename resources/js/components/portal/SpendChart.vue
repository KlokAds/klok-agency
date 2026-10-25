<script setup lang="ts">
import { computed } from 'vue';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

/** Spend day by day as bars, results under each; a long period groups days into weeks. */
const props = defineProps<{
    series: { day: string; spend: number; results: number }[];
    currency?: string;
}>();

const cur = computed(() => (props.currency === 'BDT' ? 'bdt' : 'usd'));

const bars = computed(() => {
    const s = props.series;
    if (s.length <= 31) {
        return s.map((d) => ({ ...d, label: d.day.slice(8, 10) }));
    }
    // More than a month: one bar a week.
    const weeks: {
        day: string;
        spend: number;
        results: number;
        label: string;
    }[] = [];
    for (let i = 0; i < s.length; i += 7) {
        const part = s.slice(i, i + 7);
        weeks.push({
            day: part[0].day,
            spend: part.reduce((a, d) => a + d.spend, 0),
            results: part.reduce((a, d) => a + d.results, 0),
            label: `${part[0].day.slice(8, 10)}/${part[0].day.slice(5, 7)}`,
        });
    }
    return weeks;
});

const top = computed(() => Math.max(1, ...bars.value.map((b) => b.spend)));
</script>

<template>
    <div>
        <div class="flex h-40 items-end gap-[3px]">
            <div
                v-for="b in bars"
                :key="b.day"
                class="group relative flex h-full min-w-0 flex-1 flex-col justify-end"
            >
                <div
                    class="rounded-t-[4px] bg-brand transition-opacity group-hover:opacity-80"
                    :class="b.spend === 0 ? 'bg-line' : ''"
                    :style="{
                        height: Math.max(2, (b.spend / top) * 100) + '%',
                    }"
                />
                <span
                    class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded-md bg-ink px-2 py-1 text-[11px] whitespace-nowrap text-paper group-hover:block"
                    >{{ money(b.spend, cur) }} · {{ b.results }}
                    {{ t('result') }}</span
                >
            </div>
        </div>
        <div class="mt-1.5 flex gap-[3px]">
            <span
                v-for="(b, i) in bars"
                :key="b.day"
                class="min-w-0 flex-1 text-center text-[10px] text-ink-3"
                >{{
                    bars.length <= 10 || i % Math.ceil(bars.length / 10) === 0
                        ? b.label
                        : ''
                }}</span
            >
        </div>
    </div>
</template>
