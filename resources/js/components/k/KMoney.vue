<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{ currency: 'usd' | 'bdt'; invalid?: boolean; id?: string; placeholder?: string; size?: 'md' | 'sm' }>(),
    { size: 'md' },
);

const model = defineModel<string | null>();
const symbol = computed(() => (props.currency === 'usd' ? '$' : '\u09F3'));

// Digits and one dot with two decimals; thousands commas appear as you type.
function onInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    let raw = el.value.replace(/[^\d.]/g, '');
    const [whole, ...rest] = raw.split('.');
    raw = rest.length ? whole + '.' + rest.join('').slice(0, 2) : whole;
    model.value = raw;
    el.value = pretty(raw);
}

function pretty(raw: string | null | undefined): string {
    if (!raw) {
        return '';
    }

    const [whole, fraction] = String(raw).split('.');
    const grouped = whole.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return fraction !== undefined ? grouped + '.' + fraction : grouped;
}
</script>

<template>
    <div class="field-box" :class="size === 'sm' && 'min-h-9!'" :data-invalid="invalid">
        <span class="num text-[14px] font-medium text-ink-3">{{ symbol }}</span>
        <input
            :id="id"
            :value="pretty(model)"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            :placeholder="placeholder ?? '0.00'"
            class="num h-full min-w-0 flex-1 py-2 text-[15px]"
            @input="onInput"
        />
        <span class="text-[10.5px] font-bold tracking-wider text-ink-3 uppercase">{{ currency }}</span>
    </div>
</template>
