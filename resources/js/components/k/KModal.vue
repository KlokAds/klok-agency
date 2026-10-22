<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ title?: string; subtitle?: string; width?: 'sm' | 'md' | 'lg' | 'xl' }>(), { width: 'md' });
const open = defineModel<boolean>({ default: false });

const panel = ref<HTMLElement | null>(null);
let lastFocus: HTMLElement | null = null;

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        open.value = false;
    }

    // Keep Tab inside the dialog.
    if (event.key === 'Tab' && panel.value) {
        const focusable = panel.value.querySelectorAll<HTMLElement>('button:not([disabled]), input, textarea, [tabindex="0"], a[href]');
        if (!focusable.length) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}

watch(
    open,
    async (value) => {
        if (value) {
            lastFocus = document.activeElement as HTMLElement;
            document.addEventListener('keydown', onKey);
            document.documentElement.style.overflow = 'hidden';
            await nextTick();
            panel.value?.querySelector<HTMLElement>('[data-autofocus], input, button.field-box, textarea')?.focus();
        } else {
            document.removeEventListener('keydown', onKey);
            document.documentElement.style.overflow = '';
            lastFocus?.focus?.();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.documentElement.style.overflow = '';
});

const widths = { sm: 'sm:max-w-[420px]', md: 'sm:max-w-[560px]', lg: 'sm:max-w-[720px]', xl: 'sm:max-w-[920px]' };
void props;
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[70] flex items-end justify-center sm:items-start sm:p-6 sm:pt-[8vh]">
            <div class="k-fade absolute inset-0 bg-[rgb(10_20_18/0.45)] backdrop-blur-[2px]" @click="open = false" />
            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                class="k-rise relative flex max-h-[92vh] w-full min-w-0 flex-col rounded-t-[18px] border border-line bg-surface shadow-pop sm:rounded-[16px]"
                :class="widths[width]"
            >
                <header v-if="title || $slots.header" class="flex items-start gap-4 border-b border-line px-5 pt-4 pb-3.5 sm:px-6">
                    <div class="min-w-0 flex-1">
                        <slot name="header">
                            <h2 class="text-[17px] font-bold tracking-tight">{{ title }}</h2>
                            <p v-if="subtitle" class="mt-0.5 text-[13px] text-ink-3">{{ subtitle }}</p>
                        </slot>
                    </div>
                    <button type="button" class="-mr-2 grid size-9 place-items-center rounded-lg text-ink-3 hover:bg-sunken hover:text-ink" aria-label="বন্ধ করুন" @click="open = false">
                        <svg viewBox="0 0 20 20" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15" /></svg>
                    </button>
                </header>
                <div class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto px-5 py-5 sm:px-6">
                    <slot />
                </div>
                <footer v-if="$slots.footer" class="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface-2 px-5 py-3.5 sm:rounded-b-[16px] sm:px-6">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
