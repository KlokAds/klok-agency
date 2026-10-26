<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Spend day by day as bars with the results drawn as a line over them, on a ruled scale; a long
 * period groups days into weeks. Touch or hover a day for its figures.
 */
const props = defineProps<{
    series: { day: string; spend: number; results: number }[];
    currency?: string;
}>();

const cur = computed(() => (props.currency === 'BDT' ? 'bdt' : 'usd'));
const symbol = computed(() => (cur.value === 'bdt' ? '৳' : '$'));
const id = useId();

type Bar = {
    day: string;
    spend: number;
    results: number;
    label: string;
    week: boolean;
};

const bars = computed<Bar[]>(() => {
    const s = props.series;
    if (s.length <= 31) {
        return s.map((d) => ({ ...d, label: d.day.slice(8, 10), week: false }));
    }
    // More than a month: one bar a week.
    const weeks: Bar[] = [];
    for (let i = 0; i < s.length; i += 7) {
        const part = s.slice(i, i + 7);
        weeks.push({
            day: part[0].day,
            spend: part.reduce((a, d) => a + d.spend, 0),
            results: part.reduce((a, d) => a + d.results, 0),
            label: `${part[0].day.slice(8, 10)}/${part[0].day.slice(5, 7)}`,
            week: true,
        });
    }
    return weeks;
});

// The drawing: real pixels, measured from the box, so the type never scales down on a phone.
const box = ref<HTMLElement | null>(null);
const width = ref(360);
const HEIGHT = 220;
let observer: ResizeObserver | undefined;

onMounted(() => {
    if (box.value) {
        width.value = box.value.clientWidth || width.value;
        observer = new ResizeObserver(([e]) => {
            if (e && e.contentRect.width > 0) {
                width.value = e.contentRect.width;
            }
        });
        observer.observe(box.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());

const innerW = computed(() =>
    Math.max(40, width.value - pad.value.left - pad.value.right),
);
const innerH = computed(() => HEIGHT - pad.value.top - pad.value.bottom);
const empty = computed(() => bars.value.every((b) => b.spend === 0));

// A round ceiling for the scale: 1, 2, 2.5, 5 × 10ⁿ above the highest bar.
const top = computed(() => {
    const max = Math.max(...bars.value.map((b) => b.spend), 0);
    if (max <= 0) {
        return 300 * 100;
    }
    const raw = max / 3;
    const pow = Math.pow(10, Math.floor(Math.log10(raw)));
    const step =
        [1, 2, 2.5, 5, 10].map((m) => m * pow).find((v) => v >= raw) ??
        10 * pow;
    return step * 3;
});
const ticks = computed(() => [1, 2, 3].map((i) => (top.value / 3) * i));
const pad = computed(() => ({
    top: 18,
    right: 8,
    bottom: 26,
    left: axis(top.value).length * 6.5 + 12,
}));
const resultsTop = computed(() =>
    Math.max(1, ...bars.value.map((b) => b.results)),
);

const slot = computed(() => innerW.value / Math.max(1, bars.value.length));
const barW = computed(() => Math.max(3, Math.min(28, slot.value * 0.62)));
const x = (i: number) =>
    pad.value.left + slot.value * i + (slot.value - barW.value) / 2;
const y = (spend: number) =>
    pad.value.top + innerH.value - (spend / top.value) * innerH.value;
const ry = (results: number) =>
    pad.value.top +
    8 +
    (innerH.value - 8) * (1 - results / resultsTop.value) * 0.78;
const cx = (i: number) => x(i) + barW.value / 2;

const line = computed(() => {
    const pts = bars.value.map((b, i) => [cx(i), ry(b.results)] as const);
    if (pts.length < 2) {
        return '';
    }
    // A soft curve through the points, no overshoot.
    let d = `M ${pts[0][0]} ${pts[0][1]}`;
    for (let i = 1; i < pts.length; i++) {
        const [x0, y0] = pts[i - 1];
        const [x1, y1] = pts[i];
        const c = (x1 - x0) / 2;
        d += ` C ${x0 + c} ${y0}, ${x1 - c} ${y1}, ${x1} ${y1}`;
    }
    return d;
});

// Axis money: $1.2k / ৳45k, whole below a thousand.
function axis(minor: number): string {
    const v = minor / 100;
    if (v >= 1000) {
        const k = v / 1000;
        return (
            symbol.value +
            (k >= 10 ? Math.round(k) : k.toFixed(1).replace(/\.0$/, '')) +
            'k'
        );
    }
    return symbol.value + Math.round(v);
}

// Which day labels fit: at most eight across.
const labelEvery = computed(() =>
    Math.max(1, Math.ceil(bars.value.length / 8)),
);

// The day under the finger or pointer; a tap keeps it.
const hover = ref<number | null>(null);
const pinned = ref<number | null>(null);
const active = computed(() => pinned.value ?? hover.value);
const tip = computed(() => {
    const i = active.value;
    if (i === null || !bars.value[i]) {
        return null;
    }
    const b = bars.value[i];
    const left = Math.min(Math.max(cx(i), 84), width.value - 84);
    return {
        b,
        left,
        when: b.week
            ? t('{date} সপ্তাহ', { date: niceDate(b.day, false) })
            : niceDate(b.day),
        cpr:
            b.results > 0
                ? money(Math.round(b.spend / b.results), cur.value)
                : null,
    };
});

function tap(i: number): void {
    pinned.value = pinned.value === i ? null : i;
}
</script>

<template>
    <div ref="box" class="relative select-none">
        <div
            class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink-3"
        >
            <span class="inline-flex items-center gap-1.5"
                ><i class="h-2.5 w-2.5 rounded-[3px] bg-brand" />{{
                    t('খরচ')
                }}</span
            >
            <span class="inline-flex items-center gap-1.5"
                ><i class="h-[3px] w-4 rounded-full bg-wait" />{{
                    t('result')
                }}</span
            >
            <span v-if="bars[0]?.week" class="ml-auto">{{
                t('সপ্তাহ ধরে')
            }}</span>
        </div>

        <svg
            :width="width"
            :height="HEIGHT"
            :viewBox="`0 0 ${width} ${HEIGHT}`"
            class="block overflow-visible"
        >
            <defs>
                <linearGradient :id="`${id}-bar`" x1="0" y1="0" x2="0" y2="1">
                    <stop
                        offset="0"
                        stop-color="var(--brand)"
                        stop-opacity="1"
                    />
                    <stop
                        offset="1"
                        stop-color="var(--brand)"
                        stop-opacity="0.55"
                    />
                </linearGradient>
                <linearGradient :id="`${id}-dim`" x1="0" y1="0" x2="0" y2="1">
                    <stop
                        offset="0"
                        stop-color="var(--brand)"
                        stop-opacity="0.45"
                    />
                    <stop
                        offset="1"
                        stop-color="var(--brand)"
                        stop-opacity="0.2"
                    />
                </linearGradient>
            </defs>

            <!-- The rule: three lines and their amounts -->
            <g v-for="tk in ticks" :key="tk">
                <line
                    :x1="pad.left"
                    :x2="width - pad.right"
                    :y1="y(tk)"
                    :y2="y(tk)"
                    stroke="var(--line)"
                    stroke-dasharray="2 4"
                />
                <text
                    :x="pad.left - 8"
                    :y="y(tk) + 4"
                    text-anchor="end"
                    class="fill-ink-3 text-[12.5px]"
                >
                    {{ axis(tk) }}
                </text>
            </g>
            <line
                :x1="pad.left"
                :x2="width - pad.right"
                :y1="y(0)"
                :y2="y(0)"
                stroke="var(--line-2, var(--line))"
            />

            <!-- Spend, one bar a day -->
            <g v-for="(b, i) in bars" :key="b.day">
                <rect
                    v-if="b.spend > 0"
                    :x="x(i)"
                    :y="y(b.spend)"
                    :width="barW"
                    :height="Math.max(2, y(0) - y(b.spend))"
                    :rx="Math.min(5, barW / 2)"
                    :fill="`url(#${id}-${active !== null && active !== i ? 'dim' : 'bar'})`"
                    class="transition-[fill] duration-200"
                />
                <rect
                    v-else
                    :x="x(i)"
                    :y="y(0) - 2"
                    :width="barW"
                    height="2"
                    :rx="1"
                    fill="var(--line)"
                />
                <text
                    v-if="i % labelEvery === 0"
                    :x="cx(i)"
                    :y="HEIGHT - 8"
                    text-anchor="middle"
                    class="text-[12.5px]"
                    :class="
                        active === i ? 'fill-ink font-semibold' : 'fill-ink-3'
                    "
                >
                    {{ b.label }}
                </text>
            </g>

            <!-- Results over the days -->
            <template v-if="!empty">
                <path
                    :d="line"
                    fill="none"
                    stroke="var(--wait)"
                    stroke-width="2"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />
                <circle
                    v-for="(b, i) in bars"
                    :key="`r${b.day}`"
                    :cx="cx(i)"
                    :cy="ry(b.results)"
                    :r="active === i ? 4.5 : bars.length > 40 ? 0 : 2.5"
                    fill="var(--surface)"
                    stroke="var(--wait)"
                    stroke-width="2"
                />
            </template>

            <!-- The marker and the touch targets -->
            <line
                v-if="active !== null"
                :x1="cx(active)"
                :x2="cx(active)"
                :y1="pad.top - 6"
                :y2="y(0)"
                stroke="var(--ink-3)"
                stroke-width="1"
                stroke-dasharray="3 3"
            />
            <rect
                v-for="(b, i) in bars"
                :key="`h${b.day}`"
                :x="pad.left + slot * i"
                :y="0"
                :width="slot"
                :height="HEIGHT"
                fill="transparent"
                class="cursor-pointer"
                @mouseenter="hover = i"
                @mouseleave="hover = null"
                @click="tap(i)"
            />
        </svg>

        <p
            v-if="empty"
            class="pointer-events-none absolute inset-x-0 top-[45%] text-center text-[14px] text-ink-3"
        >
            {{ t('এই সময়ে কোনো খরচ হয়নি।') }}
        </p>

        <!-- The figures of the chosen day -->
        <Transition name="fade">
            <div
                v-if="tip"
                class="pointer-events-none absolute top-6 z-10 w-[168px] -translate-x-1/2 rounded-xl border border-line bg-surface p-3 text-[13px] shadow-[0_12px_32px_-12px_rgba(0,0,0,0.35)]"
                :style="{ left: tip.left + 'px' }"
            >
                <p class="font-semibold">{{ tip.when }}</p>
                <dl class="mt-1.5 space-y-1">
                    <div class="flex justify-between gap-2">
                        <dt class="text-ink-3">{{ t('খরচ') }}</dt>
                        <dd class="num font-semibold">
                            {{ money(tip.b.spend, cur) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-ink-3">{{ t('result') }}</dt>
                        <dd class="num font-semibold">{{ tip.b.results }}</dd>
                    </div>
                    <div v-if="tip.cpr" class="flex justify-between gap-2">
                        <dt class="text-ink-3">{{ t('প্রতি result') }}</dt>
                        <dd class="num">{{ tip.cpr }}</dd>
                    </div>
                </dl>
            </div>
        </Transition>
    </div>
</template>
