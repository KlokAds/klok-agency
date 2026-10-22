<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { money, monthLabel } from '@/lib/format';
import type { TrendRow } from '@/components/dashboard/types';
import { t } from '@/lib/i18n';

const props = defineProps<{ rows: TrendRow[] }>();

// Drawn at the container's real width so text never scales up.
const box = ref<HTMLElement | null>(null);
const W = ref(0);
const H = 240;
const PAD = { top: 14, bottom: 28, left: 6, right: 6 };
let observer: ResizeObserver | undefined;

onMounted(() => {
    observer = new ResizeObserver(
        ([entry]) => (W.value = entry.contentRect.width),
    );
    if (box.value) observer.observe(box.value);
});
onBeforeUnmount(() => observer?.disconnect());

const cost = (r: TrendRow): number => r.ad_cost + r.office;

const top = computed(() =>
    Math.max(1, ...props.rows.flatMap((r) => [r.revenue, cost(r), r.net])),
);
const bottom = computed(() => Math.min(0, ...props.rows.map((r) => r.net)));
const y = (v: number): number =>
    PAD.top +
    ((top.value - v) / (top.value - bottom.value)) * (H - PAD.top - PAD.bottom);
const zeroY = computed(() => y(0));

const slot = computed(
    () => (W.value - PAD.left - PAD.right) / Math.max(1, props.rows.length),
);
const barW = computed(() => Math.max(4, Math.min(18, slot.value * 0.3)));

const cols = computed(() =>
    props.rows.map((r, i) => {
        const cx = PAD.left + slot.value * i + slot.value / 2;

        return {
            ...r,
            cx,
            revY: y(Math.max(0, r.revenue)),
            costY: y(Math.max(0, cost(r))),
            netY: y(r.net),
        };
    }),
);

const line = computed(() =>
    cols.value.map((c, i) => `${i ? 'L' : 'M'}${c.cx},${c.netY}`).join(''),
);

const hover = ref<number | null>(null);
const tip = computed(() =>
    hover.value === null ? null : cols.value[hover.value],
);
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
                :aria-label="t('মাসে মাসে আয়, খরচ আর লাভ')"
                @mouseleave="hover = null"
            >
                <line
                    :x1="PAD.left"
                    :x2="W - PAD.right"
                    :y1="zeroY"
                    :y2="zeroY"
                    stroke="var(--line-2)"
                />
                <g
                    v-for="(c, i) in cols"
                    :key="c.month"
                    :opacity="c.in_period ? 1 : 0.35"
                >
                    <rect
                        :x="PAD.left + slot * i"
                        y="0"
                        :width="slot"
                        :height="H"
                        :fill="hover === i ? 'var(--surface-2)' : 'transparent'"
                        @mouseenter="hover = i"
                    />
                    <rect
                        :x="c.cx - barW - 1"
                        :y="c.revY"
                        :width="barW"
                        :height="Math.max(0, zeroY - c.revY)"
                        rx="2"
                        fill="var(--brand)"
                        class="pointer-events-none"
                    />
                    <rect
                        :x="c.cx + 1"
                        :y="c.costY"
                        :width="barW"
                        :height="Math.max(0, zeroY - c.costY)"
                        rx="2"
                        fill="var(--ink-3)"
                        opacity="0.55"
                        class="pointer-events-none"
                    />
                    <text
                        v-if="slot >= 30 || (rows.length - 1 - i) % 2 === 0"
                        :x="c.cx"
                        :y="H - 8"
                        text-anchor="middle"
                        class="fill-ink-3 text-[11px]"
                        font-family="var(--font-sans)"
                    >
                        {{ c.label }}
                    </text>
                </g>
                <path
                    :d="line"
                    fill="none"
                    stroke="var(--ink)"
                    stroke-width="1.6"
                    stroke-linejoin="round"
                    class="pointer-events-none"
                />
                <circle
                    v-for="c in cols"
                    :key="`n${c.month}`"
                    :cx="c.cx"
                    :cy="c.netY"
                    r="3.5"
                    :fill="c.net >= 0 ? 'var(--pos)' : 'var(--neg)'"
                    stroke="var(--surface)"
                    stroke-width="1.5"
                    class="pointer-events-none"
                />
            </svg>

            <div
                v-if="tip"
                class="popover pointer-events-none absolute z-10 w-52 -translate-x-1/2 px-3.5 py-3 text-[12.5px]"
                :style="{
                    left: `${Math.min(Math.max((tip.cx / W) * 100, 18), 82)}%`,
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
        </div>

        <div
            class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-ink-3"
        >
            <span class="flex items-center gap-1.5"
                ><span class="size-2.5 rounded-[3px] bg-brand" />
                {{ t('আয়') }}</span
            >
            <span class="flex items-center gap-1.5"
                ><span class="size-2.5 rounded-[3px] bg-ink-3 opacity-55" />
                {{ t('মোট খরচ (ad + অফিস)') }}</span
            >
            <span class="flex items-center gap-1.5"
                ><span class="size-2.5 rounded-full bg-[var(--pos)]" />
                {{ t('লাভ / (লস)') }}</span
            >
        </div>
    </div>
</template>
