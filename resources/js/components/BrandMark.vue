<script setup lang="ts">
withDefaults(defineProps<{ size?: number; light?: boolean; compact?: boolean }>(), { size: 34 });
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <svg :width="size" :height="size" viewBox="0 0 64 64" aria-hidden="true">
            <rect width="64" height="64" rx="16" :fill="light ? '#1b302c' : '#11211e'" />
            <path d="M20 16v32M20 34l14-18M26 27l12 21" fill="none" stroke="#2dd4bf" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M42 40h6M42 32h6M44 24h4" stroke="#d5e2de" stroke-width="4" stroke-linecap="round" />
        </svg>
        <span v-if="compact" class="text-[15px] font-extrabold tracking-tight whitespace-nowrap text-ink">Klok Finance</span>
        <span v-else class="flex flex-col leading-none">
            <span class="text-[15px] font-extrabold tracking-tight" :class="light ? 'text-white' : 'text-ink'">Klok Finance</span>
            <span class="mt-1 text-[10.5px] font-semibold tracking-[0.16em] uppercase" :class="light ? 'text-rail-dim' : 'text-ink-3'">Klok Ads · হিসাবের খাতা</span>
        </span>
    </span>
</template>
