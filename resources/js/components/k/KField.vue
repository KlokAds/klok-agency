<script setup lang="ts">
import { t } from '@/lib/i18n';

defineProps<{
    label?: string;
    error?: string;
    hint?: string;
    for?: string;
    optional?: boolean;
}>();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1.5">
        <label
            v-if="label"
            :for="$props.for"
            class="flex items-baseline gap-2 text-[14px] font-semibold text-ink-2"
        >
            {{ label }}
            <span v-if="optional" class="text-[12px] font-normal text-ink-3"
                >({{ t('ঐচ্ছিক') }})</span
            >
        </label>
        <slot />
        <p
            v-if="error"
            class="flex items-start gap-1.5 text-[13.5px] font-medium text-out"
        >
            <svg
                viewBox="0 0 16 16"
                class="mt-0.5 size-3.5 shrink-0"
                fill="currentColor"
            >
                <path
                    d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zm-.75 3.25h1.5v4.5h-1.5v-4.5zm0 5.75h1.5V12h-1.5v-1.5z"
                />
            </svg>
            {{ error }}
        </p>
        <p v-else-if="hint" class="text-[13px] text-ink-3">{{ hint }}</p>
    </div>
</template>
