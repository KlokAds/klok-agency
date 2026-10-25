<script setup lang="ts">
import KIcon from '@/components/k/KIcon.vue';

// One block of a Settings page: what it is on the left, its fields in a
// card on the right, and the card's own actions along its bottom edge.
defineProps<{ title: string; description?: string; icon?: string }>();
</script>

<template>
    <section
        class="grid grid-cols-1 gap-4 border-b border-line py-8 first:pt-2 last:border-0 lg:grid-cols-[260px_minmax(0,1fr)] lg:gap-10"
    >
        <div>
            <h2 class="flex items-center gap-2 text-[15px] font-semibold">
                <KIcon v-if="icon" :name="icon" :size="17" class="text-brand" />
                {{ title }}
            </h2>
            <p
                v-if="description"
                class="mt-1.5 text-[13px] leading-relaxed text-ink-3"
            >
                {{ description }}
            </p>
        </div>
        <div class="panel min-w-0 overflow-hidden">
            <div class="flex flex-col gap-5 p-4 sm:p-6">
                <slot />
            </div>
            <div
                v-if="$slots.footer"
                class="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface-2 px-4 py-3 sm:px-6"
            >
                <slot name="footer" />
            </div>
        </div>
    </section>
</template>
