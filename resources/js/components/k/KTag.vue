<script setup lang="ts">
withDefaults(
    defineProps<{
        tone?: 'neutral' | 'brand' | 'in' | 'out' | 'wait' | 'done';
    }>(),
    { tone: 'neutral' },
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[13px] leading-tight font-semibold whitespace-nowrap"
        :class="
            {
                neutral: 'bg-sunken text-ink-2',
                brand: 'bg-brand-soft text-brand-ink',
                in: 'bg-in-soft text-in',
                out: 'bg-out-soft text-out',
                wait: 'bg-wait-soft text-wait',
                done: 'bg-done-soft text-done',
            }[tone]
        "
    >
        <slot />
    </span>
</template>
