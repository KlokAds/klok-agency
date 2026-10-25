<script setup lang="ts">
defineProps<{ title: string; body?: string }>();
</script>

<template>
    <div class="flex flex-col items-center px-6 py-14 text-center">
        <svg
            viewBox="0 0 64 48"
            class="mb-4 h-12 w-16 text-line-2"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
        >
            <rect x="6" y="4" width="52" height="40" rx="6" />
            <path d="M6 14h52M18 24h28M18 32h18" stroke-dasharray="3 4" />
        </svg>
        <p class="text-[16px] font-semibold">{{ title }}</p>
        <p v-if="body" class="mt-1 max-w-sm text-[14.5px] text-ink-3">
            {{ body }}
        </p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
