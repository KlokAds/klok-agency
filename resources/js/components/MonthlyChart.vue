<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { money, monthLabel } from '@/lib/format';
import { t } from '@/lib/i18n';

type Row = {
    month: string;
    label: string;
    received: number;
    cost: number;
    expense: number;
    result: number;
    usd_spent: number;
};

const props = defineProps<{ rows: Row[] }>();

// One series with polarity: each month's result above or below zero.
// Drawn at the container's real width so text never scales up.
const box = ref<HTMLElement | null>(null);
// 0 until measured, so the chart never pushes a phone page wider than the screen.
const width = ref(0);
const W = computed(() => width.value);
const H = 220;
let observer: ResizeObserver | undefined;

onMounted(() => {
    observer = new ResizeObserver(
        ([entry]) => (width.value = entry.contentRect.width),
    );
    if (box.value) observer.observe(box.value);
});

onBeforeUnmount(() => observer?.disconnect());
const PAD = { top: 16, bottom: 28, left: 8, right: 8 };

const max = computed(() =>
    Math.max(1, ...props.rows.map((r) => Math.abs(r.result))),
);
const hasNegative = computed(() => props.rows.some((r) => r.result < 0));
const zeroY = computed(() =>
    hasNegative.value
        ? PAD.top + (H - PAD.top - PAD.bottom) / 2
        : H - PAD.bottom,
);
const scale = computed(
    () =>
        (hasNegative.value
            ? (H - PAD.top - PAD.bottom) / 2
            : H - PAD.top - PAD.bottom) / max.value,
);

const slot = computed(
    () => (W.value - PAD.left - PAD.right) / Math.max(1, props.rows.length),
);
const barW = computed(() => Math.min(34, slot.value * 0.46));

const bars = computed(() =>
    props.rows.map((r, i) => {
        const h = Math.max(
            r.result === 0 ? 0 : 2,
            Math.abs(r.result) * scale.value,
        );
        const x = PAD.left + slot.value * i + (slot.value - barW.value) / 2;

        return {
            ...r,
            x,
            h,
            y: r.result >= 0 ? zeroY.value - h : zeroY.value,
            cx: x + barW.value / 2,
        };
    }),
);

// Rounded only at the data end; flat against the baseline.
function path(b: (typeof bars.value)[number]): string {
    const r = Math.min(4, b.h, barW.value / 2);
    const x = b.x;
    const w = barW.value;

    if (b.result >= 0) {
        return `M${x},${zeroY.value}V${b.y + r}Q${x},${b.y} ${x + r},${b.y}H${x + w - r}Q${x + w},${b.y} ${x + w},${b.y + r}V${zeroY.value}Z`;
    }

    const bottom = b.y + b.h;

    return `M${x},${zeroY.value}V${bottom - r}Q${x},${bottom} ${x + r},${bottom}H${x + w - r}Q${x + w},${bottom} ${x + w},${bottom - r}V${zeroY.value}Z`;
}

const hover = ref<number | null>(null);
const tip = computed(() =>
    hover.value === null ? null : bars.value[hover.value],
);
const showTable = ref(false);
</script>

<template>
    <div>
        <div ref="box" class="relative min-w-0" :style="{ height: `${H}px` }">
            <svg
                v-if="W > 0"
                :viewBox="`0 0 ${W} ${H}`"
                :height="H"
                class="block w-full overflow-visible"
                role="img"
                :aria-label="t('মাসভিত্তিক ফলাফল')"
                @mouseleave="hover = null"
            >
                <line
                    :x1="PAD.left"
                    :x2="W - PAD.right"
                    :y1="zeroY"
                    :y2="zeroY"
                    stroke="var(--line-2)"
                    stroke-width="1"
                />
                <g v-for="(b, i) in bars" :key="b.month">
                    <rect
                        :x="PAD.left + slot * i"
                        :y="0"
                        :width="slot"
                        :height="H - PAD.bottom + 4"
                        fill="transparent"
                        @mouseenter="hover = i"
                    />
                    <path
                        :d="path(b)"
                        :fill="b.result >= 0 ? 'var(--pos)' : 'var(--neg)'"
                        :opacity="hover === null || hover === i ? 1 : 0.45"
                        class="pointer-events-none transition-opacity"
                    />
                    <text
                        :x="b.cx"
                        :y="H - 8"
                        text-anchor="middle"
                        class="fill-ink-3 text-[12px]"
                        font-family="var(--font-sans)"
                    >
                        {{ b.label }}
                    </text>
                </g>
            </svg>

            <div
                v-if="tip"
                class="popover pointer-events-none absolute z-10 w-52 -translate-x-1/2 px-3.5 py-3 text-[13.5px]"
                :style="{ left: `${(tip.cx / W) * 100}%`, top: '0' }"
            >
                <p class="font-bn font-semibold text-ink">
                    {{ monthLabel(tip.month) }}
                </p>
                <dl
                    class="mt-2 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-ink-2"
                >
                    <dt>{{ t('Client দিয়েছে') }}</dt>
                    <dd class="num text-right text-ink">
                        {{ money(tip.received, 'bdt') }}
                    </dd>
                    <dt>{{ t('USD-এর cost') }}</dt>
                    <dd class="num text-right text-ink">
                        {{ money(-tip.cost, 'bdt') }}
                    </dd>
                    <dt>{{ t('খরচ') }}</dt>
                    <dd class="num text-right text-ink">
                        {{ money(-tip.expense, 'bdt') }}
                    </dd>
                    <dt
                        class="border-t border-line pt-1 font-semibold text-ink"
                    >
                        {{ t('ফলাফল') }}
                    </dt>
                    <dd
                        class="num border-t border-line pt-1 text-right font-semibold text-ink"
                    >
                        {{ money(tip.result, 'bdt', { sign: true }) }}
                    </dd>
                </dl>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-4 text-[13px] text-ink-3">
            <span class="flex items-center gap-1.5"
                ><span class="size-2.5 rounded-[3px] bg-[var(--pos)]" />
                {{ t('লাভ') }}</span
            >
            <span class="flex items-center gap-1.5"
                ><span class="size-2.5 rounded-[3px] bg-[var(--neg)]" />
                {{ t('লস') }}</span
            >
            <span class="flex-1" />
            <button
                type="button"
                class="font-semibold text-ink-2 hover:text-ink"
                @click="showTable = !showTable"
            >
                {{ showTable ? t('Chart দেখুন') : t('Table দেখুন') }}
            </button>
        </div>

        <!-- Phone: one compact row per month instead of a five-column table -->
        <ul
            v-if="showTable"
            class="mt-3 divide-y divide-dashed divide-line sm:hidden"
        >
            <li v-for="r in rows" :key="r.month" class="py-3">
                <div class="flex items-baseline justify-between gap-3">
                    <span
                        class="font-bn text-[15px] font-semibold whitespace-nowrap"
                        >{{ monthLabel(r.month) }}</span
                    >
                    <span
                        class="num font-semibold whitespace-nowrap"
                        :class="
                            r.result > 50
                                ? 'text-in'
                                : r.result < -50
                                  ? 'text-out'
                                  : 'text-ink-3'
                        "
                        >{{ money(r.result, 'bdt', { sign: true }) }}</span
                    >
                </div>
                <p
                    v-if="r.received || r.cost || r.expense"
                    class="mt-1 flex flex-wrap gap-x-3 text-[13px] text-ink-3"
                >
                    <span class="whitespace-nowrap"
                        >{{ t('পেয়েছি') }}
                        <b class="num font-medium text-ink-2">{{
                            money(r.received, 'bdt')
                        }}</b></span
                    >
                    <span class="whitespace-nowrap"
                        >cost
                        <b class="num font-medium text-ink-2">{{
                            money(r.cost, 'bdt')
                        }}</b></span
                    >
                    <span class="whitespace-nowrap"
                        >{{ t('খরচ') }}
                        <b class="num font-medium text-ink-2">{{
                            money(r.expense, 'bdt')
                        }}</b></span
                    >
                </p>
            </li>
        </ul>

        <table v-if="showTable" class="ledger mt-3 hidden sm:table">
            <thead>
                <tr>
                    <th>{{ t('মাস') }}</th>
                    <th class="r">{{ t('পেয়েছি') }}</th>
                    <th class="r">Cost</th>
                    <th class="r">{{ t('খরচ') }}</th>
                    <th class="r">{{ t('ফলাফল') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.month">
                    <td class="font-bn">{{ monthLabel(r.month) }}</td>
                    <td class="r num">{{ money(r.received, 'bdt') }}</td>
                    <td class="r num">{{ money(r.cost, 'bdt') }}</td>
                    <td class="r num">{{ money(r.expense, 'bdt') }}</td>
                    <td class="r num font-semibold">
                        {{ money(r.result, 'bdt', { sign: true }) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
