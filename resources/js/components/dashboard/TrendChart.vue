<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { money, monthLabel } from '@/lib/format';
import type { TrendRow } from '@/components/dashboard/types';
import { t } from '@/lib/i18n';

/**
 * Month by month: income and total cost as paired bars on a ruled scale, profit as a line over
 * them (below the baseline when a loss). Months before the first with anything in them are not
 * drawn. Touch or hover a month for its figures.
 */
const props = defineProps<{ rows: TrendRow[] }>();

const id = useId();
const box = ref<HTMLElement | null>(null);
const W = ref(0);
const H = 240;
let observer: ResizeObserver | undefined;

onMounted(() => {
    observer = new ResizeObserver(
        ([entry]) => (W.value = entry.contentRect.width),
    );
    if (box.value) observer.observe(box.value);
});
onBeforeUnmount(() => observer?.disconnect());

const cost = (r: TrendRow): number => r.ad_cost + r.office;

// From the first month with anything in it; at least the last six.
const rows = computed(() => {
    const first = props.rows.findIndex(
        (r) => r.revenue !== 0 || cost(r) !== 0 || r.net !== 0,
    );
    const start = Math.min(
        first < 0 ? props.rows.length : first,
        Math.max(0, props.rows.length - 6),
    );
    return props.rows.slice(start);
});
const empty = computed(() =>
    rows.value.every((r) => r.revenue === 0 && cost(r) === 0),
);

// A round ceiling: 1, 2, 2.5, 5 × 10ⁿ above the highest bar, in three steps.
const top = computed(() => {
    const max = Math.max(
        ...rows.value.flatMap((r) => [r.revenue, cost(r), r.net]),
        0,
    );
    if (max <= 0) {
        return 3 * 100000;
    }
    const raw = max / 3;
    const pow = Math.pow(10, Math.floor(Math.log10(raw)));
    const step =
        [1, 2, 2.5, 5, 10].map((m) => m * pow).find((v) => v >= raw) ??
        10 * pow;
    return step * 3;
});
const bottom = computed(() => Math.min(0, ...rows.value.map((r) => r.net)));

// Axis money: ৳1.2k / ৳45k / ৳1.2L, whole below a thousand.
function axis(minor: number): string {
    const v = Math.round(minor / 100);
    if (v >= 100000) {
        return '৳' + (v / 100000).toFixed(1).replace(/\.0$/, '') + 'L';
    }
    if (v >= 1000) {
        const k = v / 1000;
        return (
            '৳' +
            (k >= 10 ? Math.round(k) : k.toFixed(1).replace(/\.0$/, '')) +
            'k'
        );
    }
    return '৳' + v;
}
const ticks = computed(() => [1, 2, 3].map((i) => (top.value / 3) * i));
const pad = computed(() => ({
    top: 16,
    bottom: 28,
    left: axis(top.value).length * 6.8 + 12,
    right: 8,
}));
const innerH = computed(() => H - pad.value.top - pad.value.bottom);

const y = (v: number): number =>
    pad.value.top +
    ((top.value - v) / (top.value - bottom.value)) * innerH.value;
const zeroY = computed(() => y(0));

const slot = computed(
    () =>
        (W.value - pad.value.left - pad.value.right) /
        Math.max(1, rows.value.length),
);
const barW = computed(() => Math.max(4, Math.min(20, slot.value * 0.3)));

const cols = computed(() =>
    rows.value.map((r, i) => {
        const cx = pad.value.left + slot.value * i + slot.value / 2;
        return {
            ...r,
            cx,
            revY: y(Math.max(0, r.revenue)),
            costY: y(Math.max(0, cost(r))),
            netY: y(r.net),
        };
    }),
);

// A soft curve through the profit points, no overshoot.
const line = computed(() => {
    const pts = cols.value.map((c) => [c.cx, c.netY] as const);
    if (pts.length < 2) {
        return '';
    }
    let d = `M ${pts[0][0]} ${pts[0][1]}`;
    for (let i = 1; i < pts.length; i++) {
        const [x0, y0] = pts[i - 1];
        const [x1, y1] = pts[i];
        const c = (x1 - x0) / 2;
        d += ` C ${x0 + c} ${y0}, ${x1 - c} ${y1}, ${x1} ${y1}`;
    }
    return d;
});

// Which month labels fit: at most eight across.
const labelEvery = computed(() =>
    Math.max(1, Math.ceil(rows.value.length / 8)),
);

// The month under the finger or pointer; a tap keeps it.
const hover = ref<number | null>(null);
const pinned = ref<number | null>(null);
const active = computed(() => pinned.value ?? hover.value);
const tip = computed(() =>
    active.value === null ? null : (cols.value[active.value] ?? null),
);
function tap(i: number): void {
    pinned.value = pinned.value === i ? null : i;
}
</script>

<template>
    <div>
        <div
            ref="box"
            class="relative min-w-0 select-none"
            :style="{ height: `${H}px` }"
        >
            <svg
                v-if="W > 0"
                :viewBox="`0 0 ${W} ${H}`"
                :height="H"
                class="block w-full overflow-visible"
                role="img"
                :aria-label="t('মাসে মাসে আয়, খরচ আর লাভ')"
                @mouseleave="hover = null"
            >
                <defs>
                    <linearGradient
                        :id="`${id}-rev`"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
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
                    <linearGradient
                        :id="`${id}-cost`"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0"
                            stop-color="var(--out)"
                            stop-opacity="0.9"
                        />
                        <stop
                            offset="1"
                            stop-color="var(--out)"
                            stop-opacity="0.45"
                        />
                    </linearGradient>
                </defs>

                <!-- The rule -->
                <g v-for="tk in ticks" :key="tk">
                    <line
                        :x1="pad.left"
                        :x2="W - pad.right"
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
                    :x2="W - pad.right"
                    :y1="zeroY"
                    :y2="zeroY"
                    stroke="var(--line-2)"
                />

                <g
                    v-for="(c, i) in cols"
                    :key="c.month"
                    :opacity="c.in_period ? 1 : 0.4"
                >
                    <rect
                        :x="c.cx - barW - 1.5"
                        :y="c.revY"
                        :width="barW"
                        :height="Math.max(0, zeroY - c.revY)"
                        :rx="Math.min(4, barW / 2)"
                        :fill="`url(#${id}-rev)`"
                        :opacity="active !== null && active !== i ? 0.45 : 1"
                        class="pointer-events-none transition-opacity"
                    />
                    <rect
                        :x="c.cx + 1.5"
                        :y="c.costY"
                        :width="barW"
                        :height="Math.max(0, zeroY - c.costY)"
                        :rx="Math.min(4, barW / 2)"
                        :fill="`url(#${id}-cost)`"
                        :opacity="active !== null && active !== i ? 0.45 : 1"
                        class="pointer-events-none transition-opacity"
                    />
                    <text
                        v-if="i % labelEvery === 0"
                        :x="c.cx"
                        :y="H - 8"
                        text-anchor="middle"
                        class="text-[12.5px]"
                        :class="
                            active === i
                                ? 'fill-ink font-semibold'
                                : 'fill-ink-3'
                        "
                        font-family="var(--font-sans)"
                    >
                        {{ c.label }}
                    </text>
                </g>

                <!-- Profit over the months -->
                <template v-if="!empty">
                    <path
                        :d="line"
                        fill="none"
                        stroke="var(--wait)"
                        stroke-width="2"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        class="pointer-events-none"
                    />
                    <circle
                        v-for="(c, i) in cols"
                        :key="`n${c.month}`"
                        :cx="c.cx"
                        :cy="c.netY"
                        :r="active === i ? 4.5 : 3"
                        :fill="c.net >= 0 ? 'var(--surface)' : 'var(--neg)'"
                        stroke="var(--wait)"
                        stroke-width="2"
                        class="pointer-events-none"
                    />
                </template>

                <line
                    v-if="active !== null && cols[active]"
                    :x1="cols[active].cx"
                    :x2="cols[active].cx"
                    :y1="pad.top - 6"
                    :y2="zeroY"
                    stroke="var(--ink-3)"
                    stroke-dasharray="3 3"
                />
                <rect
                    v-for="(c, i) in cols"
                    :key="`h${c.month}`"
                    :x="pad.left + slot * i"
                    y="0"
                    :width="slot"
                    :height="H"
                    fill="transparent"
                    class="cursor-pointer"
                    @mouseenter="hover = i"
                    @click="tap(i)"
                />
            </svg>

            <p
                v-if="empty"
                class="pointer-events-none absolute inset-x-0 top-[45%] text-center text-[14px] text-ink-3"
            >
                {{ t('এই সময়ে কোনো আয়-খরচ নেই।') }}
            </p>

            <Transition name="fade">
                <div
                    v-if="tip"
                    class="popover pointer-events-none absolute z-10 w-52 -translate-x-1/2 px-3.5 py-3 text-[13.5px]"
                    :style="{
                        left: `${Math.min(Math.max(tip.cx, 110), W - 110)}px`,
                        top: '0',
                    }"
                >
                    <p class="font-semibold text-ink">
                        {{ monthLabel(tip.month) }}
                    </p>
                    <dl
                        class="mt-2 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-ink-2"
                    >
                        <dt>{{ t('আয়') }}</dt>
                        <dd class="num text-right text-ink">
                            {{ money(tip.revenue, 'bdt') }}
                        </dd>
                        <dt>{{ t('Ad খরচ') }}</dt>
                        <dd class="num text-right text-ink">
                            {{ money(-tip.ad_cost, 'bdt') }}
                        </dd>
                        <dt>{{ t('অফিস খরচ') }}</dt>
                        <dd class="num text-right text-ink">
                            {{ money(-tip.office, 'bdt') }}
                        </dd>
                        <dt
                            class="border-t border-line pt-1 font-semibold text-ink"
                        >
                            {{ t('লাভ / (লস)') }}
                        </dt>
                        <dd
                            class="num border-t border-line pt-1 text-right font-semibold"
                            :class="tip.net < 0 ? 'text-out' : 'text-in'"
                        >
                            {{ money(tip.net, 'bdt', { sign: true }) }}
                        </dd>
                    </dl>
                </div>
            </Transition>
        </div>

        <div
            class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink-3"
        >
            <span class="flex items-center gap-1.5"
                ><span class="size-2.5 rounded-[3px] bg-brand" />{{
                    t('আয়')
                }}</span
            >
            <span class="flex items-center gap-1.5"
                ><span class="size-2.5 rounded-[3px] bg-out opacity-80" />{{
                    t('মোট খরচ (ad + অফিস)')
                }}</span
            >
            <span class="flex items-center gap-1.5"
                ><span class="h-[3px] w-4 rounded-full bg-wait" />{{
                    t('লাভ / (লস)')
                }}</span
            >
        </div>
    </div>
</template>
