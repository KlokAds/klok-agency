<script setup lang="ts">
import type { Option } from '@/types';
import KSelect from './KSelect.vue';

// A few choices side by side. Five or more would wrap into a ragged block on a phone, so there
// they become a dropdown; on a wider screen the row stays.
const props = defineProps<{ options: Option[]; size?: 'md' | 'sm' }>();
const model = defineModel<string | number | null>();
const many = () => props.options.length > 4;
</script>

<template>
    <div v-if="many()" class="sm:hidden">
        <KSelect v-model="model" :options="options" size="sm" />
    </div>
    <div
        role="radiogroup"
        class="flex-wrap gap-1 rounded-[11px] border border-line-2 bg-sunken p-1"
        :class="many() ? 'hidden sm:inline-flex' : 'inline-flex'"
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
                    ? 'bg-brand text-white shadow-[0_6px_14px_-8px_var(--brand)]'
                    : 'text-ink-2 hover:bg-surface hover:text-ink',
            ]"
            @click="model = o.value"
        >
            {{ o.label }}
        </button>
    </div>
</template>
