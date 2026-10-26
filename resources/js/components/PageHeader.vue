<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';

defineProps<{ title: string; eyebrow?: string; lead?: string }>();
</script>

<template>
    <Head :title="t(title)" />
    <!-- Phone: the words take the whole width and the buttons go under them, at the right. -->
    <div
        class="mb-6 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end sm:gap-x-6"
    >
        <div class="min-w-0 sm:flex-1">
            <p
                v-if="eyebrow"
                class="mb-2 flex items-center gap-2 text-[12.5px] font-bold tracking-[0.12em] text-brand-ink uppercase"
            >
                <span class="h-[3px] w-5 rounded-full bg-brand" />{{
                    t(eyebrow)
                }}
            </p>
            <h1
                class="text-[26px] leading-tight font-semibold tracking-[-0.02em] sm:text-[30px]"
            >
                {{ t(title) }}
            </h1>
            <p v-if="lead" class="mt-1.5 max-w-2xl text-[15px] text-ink-2">
                {{ t(lead) }}
            </p>
        </div>
        <div
            v-if="$slots.default"
            class="no-print flex flex-wrap items-center justify-end gap-2"
        >
            <slot />
        </div>
    </div>
</template>
