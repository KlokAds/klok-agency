<script setup lang="ts">
import { t } from '@/lib/i18n';

withDefaults(
    defineProps<{
        size?: number;
        light?: boolean;
        compact?: boolean;
        /** Whose app this is: Owner, Team or Client — a small tag beside the name. */
        badge?: string | null;
    }>(),
    { size: 34 },
);
</script>

<template>
    <span class="inline-flex max-w-full min-w-0 items-center gap-2.5">
        <svg
            class="shrink-0"
            :width="size"
            :height="size"
            viewBox="0 0 64 64"
            aria-hidden="true"
        >
            <rect
                width="64"
                height="64"
                rx="16"
                :fill="light ? '#1b302c' : '#11211e'"
            />
            <path
                d="M20 16v32M20 34l14-18M26 27l12 21"
                fill="none"
                stroke="#2dd4bf"
                stroke-width="6"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path
                d="M42 40h6M42 32h6M44 24h4"
                stroke="#d5e2de"
                stroke-width="4"
                stroke-linecap="round"
            />
        </svg>
        <!-- compact: the phone top bar — same two lines, tighter, never wrapping -->
        <span
            class="flex min-w-0 flex-col overflow-hidden leading-none whitespace-nowrap"
        >
            <span class="flex min-w-0 items-center gap-1.5">
                <span
                    class="truncate font-bold tracking-[-0.02em]"
                    :class="[
                        compact ? 'text-[14.5px]' : 'text-[15px]',
                        light ? 'text-white' : 'text-ink',
                    ]"
                    >Klok Finance</span
                >
                <span
                    v-if="badge"
                    class="shrink-0 rounded-[5px] px-1.5 py-[3px] text-[9px] font-bold tracking-[0.06em] uppercase"
                    :class="
                        light
                            ? 'bg-[#2dd4bf]/20 text-[#5eead4]'
                            : 'bg-brand-soft text-brand'
                    "
                    >{{ badge }}</span
                >
            </span>
            <span
                class="mt-1 truncate font-semibold"
                :class="[
                    compact
                        ? 'text-[9.5px] tracking-[0.1em]'
                        : 'text-[10.5px] tracking-[0.16em]',
                    light ? 'text-rail-dim' : 'text-ink-3',
                ]"
            >
                {{ t('Klok Ads · হিসাবের খাতা') }}
            </span>
        </span>
    </span>
</template>
