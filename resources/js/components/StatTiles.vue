<script setup lang="ts">
import KIcon from '@/components/k/KIcon.vue';

/**
 * A page's headline totals: one tile each with a coloured icon badge, the figure, and an
 * optional line under it. Phone: badge and words side by side so three tiles stay short.
 */
export type StatTile = {
    key: string;
    label: string;
    value: string;
    icon: string;
    /** Tailwind classes for the badge: background and icon colour. */
    badge: string;
    /** Tailwind classes for the figure; empty for plain ink. */
    text?: string;
    sub?: string | null;
    subClass?: string;
};

defineProps<{ tiles: StatTile[] }>();
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div
            v-for="tile in tiles"
            :key="tile.key"
            class="panel flex items-center gap-3.5 px-4 py-3.5 sm:block sm:p-5"
        >
            <span
                class="grid size-11 shrink-0 place-items-center rounded-xl"
                :class="tile.badge"
                ><KIcon :name="tile.icon" :size="20"
            /></span>
            <span class="min-w-0 flex-1 sm:mt-4 sm:block">
                <span
                    class="block truncate text-[13px] font-medium text-ink-3"
                    >{{ tile.label }}</span
                >
                <span
                    class="num mt-0.5 block truncate text-[24px] leading-tight font-semibold tracking-[-0.02em] sm:mt-1.5 sm:text-[28px]"
                    :class="tile.text"
                    >{{ tile.value }}</span
                >
                <span
                    v-if="tile.sub"
                    class="mt-0.5 block truncate text-[12.5px] sm:mt-1.5"
                    :class="tile.subClass ?? 'text-ink-3'"
                    >{{ tile.sub }}</span
                >
            </span>
        </div>
    </div>
</template>
