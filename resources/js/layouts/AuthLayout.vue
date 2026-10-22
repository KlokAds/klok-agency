<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue';
import KFeedbackHost from '@/components/k/KFeedbackHost.vue';
import { t } from '@/lib/i18n';

defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <div class="grid min-h-dvh bg-paper lg:grid-cols-[1.05fr_1fr]">
        <!-- Ledger panel -->
        <section
            class="relative hidden overflow-hidden bg-rail p-12 text-rail-ink lg:flex lg:flex-col"
        >
            <BrandMark light />

            <div class="relative mt-auto max-w-md">
                <p
                    class="text-[11px] font-bold tracking-[0.2em] text-rail-dim uppercase"
                >
                    Klok Ads · Agency Books
                </p>
                <p
                    class="mt-4 text-[34px] leading-[1.15] font-extrabold tracking-tight text-white"
                >
                    {{
                        t(
                            'প্রতিটা ডলার কোন rate-এ কেনা, কোন client-এ গেল — এক খাতায়।',
                        )
                    }}
                </p>
                <p class="mt-4 text-[14.5px] leading-relaxed text-rail-ink/75">
                    {{
                        t(
                            'USD আর BDT দুই খাতা, FIFO cost, client-এর margin আর বেতন — সব এখানে, সব পরিবর্তন log করা।',
                        )
                    }}
                </p>
            </div>

            <!-- ruled ledger lines -->
            <svg
                class="pointer-events-none absolute -top-10 -right-16 h-[520px] w-[520px] text-rail-2"
                viewBox="0 0 400 400"
                fill="none"
                aria-hidden="true"
            >
                <g stroke="currentColor" stroke-width="1.2">
                    <path v-for="i in 14" :key="i" :d="`M0 ${i * 28}H400`" />
                    <path d="M92 0V400M300 0V400" stroke-dasharray="4 6" />
                </g>
                <g
                    class="text-[#2dd4bf]"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                >
                    <path d="M120 252l46-58 40 30 70-92" />
                </g>
            </svg>
        </section>

        <!-- Form -->
        <section class="flex items-center justify-center px-5 py-10 sm:px-10">
            <div class="w-full max-w-[400px]">
                <div class="mb-10 lg:hidden"><BrandMark /></div>
                <h1
                    v-if="title"
                    class="text-[26px] font-extrabold tracking-tight"
                >
                    {{ t(title) }}
                </h1>
                <p v-if="description" class="mt-1.5 text-[14px] text-ink-2">
                    {{ t(description) }}
                </p>
                <div class="mt-8">
                    <slot />
                </div>
                <p class="mt-10 flex items-center gap-2 text-[12px] text-ink-3">
                    <svg
                        viewBox="0 0 20 20"
                        class="size-4"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path
                            d="M10 2.5l6 2.5v4.5c0 3.8-2.6 6.6-6 8-3.4-1.4-6-4.2-6-8V5l6-2.5z"
                        />
                    </svg>
                    {{
                        t(
                            'শুধু অনুমতি পাওয়া মানুষ ঢুকতে পারে। প্রতিটা login লেখা থাকে।',
                        )
                    }}
                </p>
            </div>
        </section>
        <KFeedbackHost />
    </div>
</template>
