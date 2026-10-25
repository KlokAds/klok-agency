<script setup lang="ts">
import type { Option } from '@/types';

defineProps<{ options: Option[]; size?: 'md' | 'sm' }>();
const model = defineModel<string | number | null>();
</script>

<template>
    <div
        role="radiogroup"
        class="inline-flex flex-wrap gap-1 rounded-[11px] bg-sunken p-1"
    >
        <button
            v-for="o in options"
            :key="String(o.value)"
            type="button"
            role="radio"
            :aria-checked="model === o.value"
            class="rounded-lg px-3 font-semibold transition-colors"
            :class="[
                size === 'sm' ? 'h-7 text-[13px]' : 'h-8 text-[14px]',
                model === o.value
                    ? 'bg-surface text-ink shadow-[0_1px_2px_rgb(0_0_0/0.08)]'
                    : 'text-ink-3 hover:text-ink',
            ]"
            @click="model = o.value"
        >
            {{ o.label }}
        </button>
    </div>
</template>
