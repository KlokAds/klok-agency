<script setup lang="ts">
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';

/**
 * A page's headline totals: one tile each with a coloured icon badge, the figure, and an
 * optional line under it. Phone: badge and words side by side so the tiles stay short. A
 * `dark` tile is the one the page is about (the card), with an optional button on it.
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
    dark?: boolean;
    action?: { label: string; href: string; icon?: string };
};

withDefaults(defineProps<{ tiles: StatTile[]; cols?: 3 | 4 }>(), { cols: 3 });
</script>

<template>
    <div
        class="grid grid-cols-1 gap-3"
        :class="cols === 4 ? 'sm:grid-cols-2 xl:grid-cols-4' : 'sm:grid-cols-3'"
    >
        <div
            v-for="tile in tiles"
            :key="tile.key"
            class="flex items-center gap-3.5 px-4 py-3.5 sm:block sm:p-5"
            :class="
                tile.dark ? 'rounded-[14px] bg-rail text-rail-ink' : 'panel'
            "
        >
            <span
                class="grid size-11 shrink-0 place-items-center rounded-xl"
                :class="tile.badge"
                ><KIcon :name="tile.icon" :size="20"
            /></span>
            <span class="min-w-0 flex-1 sm:mt-4 sm:block">
                <span
                    class="block truncate text-[13px] font-medium"
                    :class="tile.dark ? 'text-rail-dim' : 'text-ink-3'"
                    >{{ tile.label }}</span
                >
                <span
                    class="num mt-0.5 block truncate text-[24px] leading-tight font-semibold tracking-[-0.02em] sm:mt-1.5 sm:text-[28px]"
                    :class="tile.text ?? (tile.dark ? 'text-white' : '')"
                    >{{ tile.value }}</span
                >
                <span
                    v-if="tile.sub"
                    class="mt-0.5 block text-[13px] leading-snug sm:mt-1.5"
                    :class="
                        tile.subClass ??
                        (tile.dark ? 'text-rail-dim' : 'text-ink-3')
                    "
                    >{{ tile.sub }}</span
                >
            </span>
            <KButton
                v-if="tile.action"
                :href="tile.action.href"
                size="sm"
                :icon="tile.action.icon"
                class="no-print shrink-0 sm:mt-3"
                >{{ tile.action.label }}</KButton
            >
        </div>
    </div>
</template>
