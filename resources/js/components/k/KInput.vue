<script setup lang="ts">
import { ref } from 'vue';
import { t } from '@/lib/i18n';
import KIcon from './KIcon.vue';

withDefaults(
    defineProps<{
        type?: 'text' | 'email' | 'password' | 'search' | 'tel';
        placeholder?: string;
        invalid?: boolean;
        id?: string;
        name?: string;
        autocomplete?: string;
        autofocus?: boolean;
        prefix?: string;
        icon?: string;
        size?: 'md' | 'sm';
        maxlength?: number;
        inputmode?: 'text' | 'numeric' | 'decimal' | 'email' | 'tel';
        required?: boolean;
    }>(),
    { type: 'text', size: 'md' },
);

const model = defineModel<string | null>();
const input = ref<HTMLInputElement | null>(null);
const reveal = ref(false);

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div
        class="field-box"
        :class="size === 'sm' && 'min-h-9!'"
        :data-invalid="invalid"
        @click="input?.focus()"
    >
        <KIcon v-if="icon" :name="icon" :size="16" class="text-ink-3" />
        <span v-if="prefix" class="text-[13px] font-semibold text-ink-3">{{
            prefix
        }}</span>
        <input
            :id="id"
            ref="input"
            v-model="model"
            :name="name"
            :type="type === 'password' && reveal ? 'text' : type"
            :placeholder="placeholder"
            :autocomplete="autocomplete"
            :autofocus="autofocus"
            :maxlength="maxlength"
            :inputmode="inputmode"
            :required="required"
            class="h-full min-w-0 flex-1 py-2"
            :class="size === 'sm' ? 'text-[13px]' : 'text-[14.5px]'"
        />
        <button
            v-if="type === 'password'"
            type="button"
            tabindex="-1"
            class="-mr-1 grid size-7 place-items-center rounded-md text-ink-3 hover:text-ink"
            :aria-label="reveal ? t('Password লুকান') : t('Password দেখুন')"
            @click.stop="reveal = !reveal"
        >
            <KIcon :name="reveal ? 'eyeoff' : 'eye'" :size="16" />
        </button>
        <slot name="trail" />
    </div>
</template>
