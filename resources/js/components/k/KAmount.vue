<script setup lang="ts">
import { computed } from 'vue';
import { money } from '@/lib/format';
import type { Currency } from '@/lib/format';

const props = withDefaults(
    defineProps<{
        value: number | null | undefined;
        currency: Currency;
        tone?: 'auto' | 'none';
        sign?: boolean;
        round?: boolean;
    }>(),
    // round stays undefined unless passed, so BDT rounds by default.
    { tone: 'auto', sign: false, round: undefined },
);

const text = computed(() =>
    money(props.value, props.currency, {
        sign: props.sign,
        round: props.round,
    }),
);
const color = computed(() => {
    const threshold = (props.round ?? props.currency === 'bdt') ? 50 : 0;

    if (
        props.tone === 'none' ||
        !props.value ||
        Math.abs(props.value) <= threshold
    ) {
        return '';
    }

    return props.value < 0 ? 'text-out' : 'text-in';
});
</script>

<template>
    <span class="num whitespace-nowrap" :class="color">{{ text }}</span>
</template>
