<script setup lang="ts">
import type { Option } from '@/types';
import KSelect from './KSelect.vue';
import { t } from '@/lib/i18n';

// A few choices side by side. Five or more would wrap into a ragged block on a phone, so there
// they become a dropdown; on a wider screen the row stays.
const props = defineProps<{
    options: Option[];
    size?: 'md' | 'sm';
    empty?: string;
}>();
const model = defineModel<string | number | null>();
const many = () => props.options.length > 4;
</script>

<template>
    <!-- Nothing to choose from: say so, never an empty bar. -->
    <p
        v-if="!options.length"
        class="rounded-[11px] border border-dashed border-line-2 px-3 py-2 text-[13.5px] text-ink-3"
    >
        {{ empty ?? t('এখনো কোনো option নেই') }}
    </p>
    <div v-else-if="many()" class="sm:hidden">
        <KSelect v-model="model" :options="options" size="sm" />
    </div>
    <div
        v-if="options.length"
        role="radiogroup"
        class="flex-wrap gap-2"
        :class="many() ? 'hidden sm:inline-flex' : 'inline-flex'"
    >
        <button
            v-for="o in options"
            :key="String(o.value)"
            type="button"
            role="radio"
            :aria-checked="model === o.value"
            class="rounded-[10px] border px-3.5 font-semibold transition-colors"
            :class="[
                size === 'sm' ? 'h-8 text-[13.5px]' : 'h-10 text-[14px]',
                model === o.value
                    ? 'border-brand bg-brand text-white shadow-[0_6px_14px_-8px_var(--brand)]'
                    : 'border-line-2 bg-surface text-ink-2 hover:border-ink-3 hover:text-ink',
            ]"
            @click="model = o.value"
        >
            {{ o.label }}
        </button>
    </div>
</template>
