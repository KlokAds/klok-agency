<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { isoDate, money, niceDate, today } from '@/lib/format';
import type { Currency } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * A campaign's days as cards, newest first: the day and what it cost, a bar against the busiest
 * day, and what it brought on one line. A small filter above shows the last 7, 15 or 30 days of
 * the period, or all of it — no page load.
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

const spans = [
    { key: 7, label: '৭ দিন' }, // i18n
    { key: 15, label: '১৫ দিন' }, // i18n
    { key: 30, label: '১ মাস' }, // i18n
    { key: 0, label: 'সব' }, // i18n
];
// Long periods open on the last fifteen days; a short one shows everything.
const span = ref(props.days.length > 15 ? 15 : 0);
watch(
    () => props.days.length,
    (n) => {
        if (span.value && n <= span.value) {
            span.value = 0;
        }
    },
);
const shown = computed(() =>
    span.value ? props.days.slice(0, span.value) : props.days,
);
const top = computed(() => Math.max(1, ...shown.value.map((d) => d.spend)));

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
    <div>
        <div
            class="flex flex-wrap items-center gap-1.5 border-b border-line px-4 py-2.5 sm:px-5"
        >
            <button
                v-for="s in spans"
                :key="s.key"
                type="button"
                class="h-8 rounded-lg border px-2.5 text-[13.5px] font-semibold transition-colors"
                :class="span === s.key ? 'k-accent' : 'k-chip'"
                :disabled="
                    s.key !== 0 && days.length <= s.key && span !== s.key
                "
                @click="span = s.key"
            >
                {{ t(s.label) }}
            </button>
            <span class="num ml-auto text-[13px] text-ink-3"
                >{{ shown.length }} / {{ days.length }}</span
            >
        </div>
        <ul class="divide-y divide-line">
            <li
                v-for="d in shown"
                :key="d.day"
                class="px-4 py-3 sm:px-5"
                :class="d.spend === 0 ? 'opacity-60' : ''"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <p class="text-[15px] font-bold">
                        {{ when(d.day) }}
                        <span
                            v-if="d.day === now || d.day === yesterday"
                            class="num ml-1 text-[13px] font-normal text-ink-3"
                            >{{ niceDate(d.day, false) }}</span
                        >
                    </p>
                    <p class="num text-[16px] font-bold">
                        {{ money(d.spend, cur) }}
                    </p>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-sunken">
                    <div
                        class="h-full rounded-full bg-brand"
                        :style="{
                            width: Math.round((d.spend / top) * 100) + '%',
                        }"
                    />
                </div>
                <p class="mt-1.5 truncate text-[13.5px] text-ink-3">
                    <template v-if="d.spend === 0">{{
                        t('খরচ হয়নি')
                    }}</template>
                    <template v-else>
                        <b class="num font-semibold text-ink">{{
                            d.results
                        }}</b>
                        {{ resultName }}
                        <template v-if="d.cpr !== null">
                            ·
                            <b class="num font-semibold text-ink">{{
                                money(d.cpr, cur)
                            }}</b
                            >/result</template
                        >
                        ·
                        <b class="num font-semibold text-ink">{{ d.clicks }}</b>
                        click
                    </template>
                </p>
            </li>
        </ul>
    </div>
</template>
