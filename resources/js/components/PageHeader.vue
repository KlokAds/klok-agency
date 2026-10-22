<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ title: string; eyebrow?: string; lead?: string }>();
</script>

<template>
    <Head :title="title" />
    <div class="mb-6 flex flex-wrap items-end gap-x-6 gap-y-4">
        <div class="min-w-0 flex-1">
            <p v-if="eyebrow" class="eyebrow mb-1.5">{{ eyebrow }}</p>
            <h1 class="text-[26px] leading-tight font-extrabold tracking-tight sm:text-[30px]">{{ title }}</h1>
            <p v-if="lead" class="mt-1.5 max-w-2xl text-[14px] text-ink-2">{{ lead }}</p>
        </div>
        <div v-if="$slots.default" class="no-print flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </div>
</template>
