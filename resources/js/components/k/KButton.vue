<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import KIcon from './KIcon.vue';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'plain' | 'quiet' | 'danger' | 'ink';
        size?: 'sm' | 'md' | 'lg';
        icon?: string;
        href?: string;
        type?: 'button' | 'submit';
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
        external?: boolean;
    }>(),
    { variant: 'plain', size: 'md', type: 'button' },
);

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-[10px] font-semibold whitespace-nowrap transition-[background,border,color,transform] select-none active:translate-y-px',
    {
        sm: 'h-8 px-3 text-[12.5px]',
        md: 'h-10 px-4 text-[13.5px]',
        lg: 'h-12 px-5 text-[15px]',
    }[props.size],
    {
        primary: 'bg-brand text-white hover:bg-brand-ink dark:text-rail',
        ink: 'bg-ink text-paper hover:bg-ink-2',
        plain: 'border border-line-2 bg-surface text-ink hover:border-ink-3 hover:bg-surface-2',
        quiet: 'text-ink-2 hover:bg-sunken hover:text-ink',
        danger: 'border border-out/30 bg-out-soft text-out hover:border-out hover:bg-out hover:text-white',
    }[props.variant],
    props.block ? 'w-full' : '',
    props.disabled || props.loading ? 'pointer-events-none opacity-55' : 'cursor-pointer',
]);
</script>

<template>
    <a v-if="href && external" :href="href" :class="classes"><KIcon v-if="icon" :name="icon" :size="16" /><slot /></a>
    <Link v-else-if="href" :href="href" :class="classes"><KIcon v-if="icon" :name="icon" :size="16" /><slot /></Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading">
        <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 20 20" fill="none">
            <circle cx="10" cy="10" r="7" stroke="currentColor" stroke-opacity=".25" stroke-width="2.5" />
            <path d="M17 10a7 7 0 00-7-7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
        </svg>
        <KIcon v-else-if="icon" :name="icon" :size="16" />
        <slot />
    </button>
</template>
