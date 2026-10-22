<script setup lang="ts">
import { t } from '@/lib/i18n';

withDefaults(
    defineProps<{ size?: number; light?: boolean; compact?: boolean }>(),
    { size: 34 },
);
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <svg
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
        <span class="flex flex-col leading-none whitespace-nowrap">
            <span
                class="font-extrabold tracking-tight"
                :class="[
                    compact ? 'text-[14.5px]' : 'text-[15px]',
                    light ? 'text-white' : 'text-ink',
                ]"
                >Klok Finance</span
            >
            <span
                class="mt-1 font-semibold uppercase"
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
