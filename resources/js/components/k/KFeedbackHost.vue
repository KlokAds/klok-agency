<script setup lang="ts">
import { dismiss, feedback } from '@/lib/feedback';
import KButton from './KButton.vue';
import KModal from './KModal.vue';
import { computed } from 'vue';

const tone = {
    success: 'bg-in',
    error: 'bg-out',
    warning: 'bg-wait',
    info: 'bg-brand',
};

const confirmOpen = computed({
    get: () => feedback.confirm !== null,
    set: (value) => {
        if (!value) {
            answer(false);
        }
    },
});

function answer(ok: boolean): void {
    const request = feedback.confirm;
    feedback.confirm = null;
    request?.resolve(ok);
}
</script>

<template>
    <Teleport to="body">
        <div class="pointer-events-none fixed inset-x-0 bottom-0 z-[90] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6" aria-live="polite">
            <div
                v-for="t in feedback.toasts"
                :key="t.id"
                class="k-rise pointer-events-auto flex w-full max-w-[380px] items-stretch overflow-hidden rounded-xl border border-line bg-surface shadow-pop"
            >
                <span class="w-1 shrink-0" :class="tone[t.type]" />
                <p class="flex-1 px-4 py-3 text-[13.5px] leading-snug font-medium">{{ t.message }}</p>
                <button type="button" class="px-3 text-ink-3 hover:text-ink" aria-label="বন্ধ" @click="dismiss(t.id)">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15" /></svg>
                </button>
            </div>
        </div>
    </Teleport>

    <KModal v-model="confirmOpen" width="sm" :title="feedback.confirm?.title">
        <p class="text-[14px] leading-relaxed text-ink-2">{{ feedback.confirm?.body }}</p>
        <template #footer>
            <KButton variant="quiet" @click="answer(false)">বাতিল</KButton>
            <KButton :variant="feedback.confirm?.danger ? 'danger' : 'primary'" data-autofocus @click="answer(true)">{{ feedback.confirm?.confirm ?? 'হ্যাঁ, করুন' }}</KButton>
        </template>
    </KModal>
</template>
