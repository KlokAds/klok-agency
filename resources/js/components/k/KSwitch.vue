<script setup lang="ts">
defineProps<{ label?: string; hint?: string }>();
const model = defineModel<boolean>({ default: false });
</script>

<template>
    <label
        class="flex cursor-pointer items-start justify-between gap-4 select-none"
    >
        <span v-if="label" class="text-[14px] font-medium">
            {{ label }}
            <span
                v-if="hint"
                class="block text-[12.5px] font-normal text-ink-3"
                >{{ hint }}</span
            >
        </span>
        <input
            v-model="model"
            type="checkbox"
            role="switch"
            class="peer sr-only"
        />
        <span
            class="relative mt-0.5 h-6 w-[42px] shrink-0 rounded-full transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-brand peer-focus-visible:ring-offset-2"
            :class="model ? 'bg-brand' : 'bg-line-2'"
        >
            <span
                class="absolute top-[3px] size-[18px] rounded-full bg-white shadow-sm transition-[left]"
                :class="model ? 'left-[21px]' : 'left-[3px]'"
            />
        </span>
    </label>
</template>
