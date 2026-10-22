<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { t } from '@/lib/i18n';

// Six separate boxes for an authenticator code; paste fills all of them.
const props = withDefaults(
    defineProps<{ length?: number; invalid?: boolean }>(),
    { length: 6 },
);
const model = defineModel<string>({ default: '' });
const boxes = ref<HTMLInputElement[]>([]);

function setAt(index: number, digit: string): void {
    const chars = model.value.padEnd(props.length, ' ').split('');
    chars[index] = digit;
    model.value = chars.join('').replace(/\s+$/, '');
}

async function onInput(index: number, event: Event): Promise<void> {
    const el = event.target as HTMLInputElement;
    const digits = el.value.replace(/\D/g, '');

    if (digits.length > 1) {
        model.value = digits.slice(0, props.length);
        await nextTick();
        boxes.value[Math.min(digits.length, props.length) - 1]?.focus();

        return;
    }

    setAt(index, digits);
    el.value = digits;

    if (digits && index < props.length - 1) {
        boxes.value[index + 1]?.focus();
    }
}

function onKey(index: number, event: KeyboardEvent): void {
    if (event.key === 'Backspace' && !model.value[index] && index > 0) {
        boxes.value[index - 1]?.focus();
    } else if (event.key === 'ArrowLeft' && index > 0) {
        boxes.value[index - 1]?.focus();
    } else if (event.key === 'ArrowRight' && index < props.length - 1) {
        boxes.value[index + 1]?.focus();
    }
}
</script>

<template>
    <div class="flex gap-2" role="group" :aria-label="t('কোড')">
        <input
            v-for="i in length"
            :key="i"
            :ref="(el) => (boxes[i - 1] = el as HTMLInputElement)"
            :value="model[i - 1] ?? ''"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            class="num h-13 w-full min-w-0 rounded-[10px] border bg-surface text-center text-[22px] font-medium transition-[border,box-shadow] focus:border-brand focus:shadow-[0_0_0_3px_var(--brand-soft)]"
            :class="invalid ? 'border-out' : 'border-line-2'"
            :autofocus="i === 1"
            @input="onInput(i - 1, $event)"
            @keydown="onKey(i - 1, $event)"
            @focus="($event.target as HTMLInputElement).select()"
        />
    </div>
</template>
