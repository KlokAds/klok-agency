<script setup lang="ts">
defineProps<{ label?: string; hint?: string; disabled?: boolean }>();
const model = defineModel<boolean>({ default: false });
</script>

<template>
    <label
        class="group inline-flex cursor-pointer items-start gap-2.5 select-none"
        :class="disabled && 'pointer-events-none opacity-50'"
    >
        <input
            v-model="model"
            type="checkbox"
            class="peer sr-only"
            :disabled="disabled"
        />
        <span
            class="mt-0.5 grid size-[18px] shrink-0 place-items-center rounded-[5px] border-[1.5px] transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-brand peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-surface"
            :class="
                model
                    ? 'border-brand bg-brand text-white'
                    : 'border-line-2 bg-surface group-hover:border-ink-3'
            "
        >
            <svg
                v-if="model"
                viewBox="0 0 16 16"
                class="size-3"
                fill="none"
                stroke="currentColor"
                stroke-width="2.4"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M3.5 8.5l3 3 6-7" />
            </svg>
        </span>
        <span v-if="label || $slots.default" class="text-[14px] leading-snug">
            <slot>{{ label }}</slot>
            <span v-if="hint" class="block text-[12px] text-ink-3">{{
                hint
            }}</span>
        </span>
    </label>
</template>
