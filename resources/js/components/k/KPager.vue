<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';

defineProps<{ page: Paginated<unknown> }>();
</script>

<template>
    <nav v-if="page.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-[13px]" :aria-label="t('পাতা')">
        <span class="text-ink-3"><span class="num">{{ page.from }}–{{ page.to }}</span> / <span class="num">{{ page.total }}</span></span>
        <div class="flex gap-1">
            <template v-for="(link, i) in page.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="num grid h-8 min-w-8 place-items-center rounded-lg px-2"
                    :class="link.active ? 'bg-ink text-paper' : 'text-ink-2 hover:bg-sunken'"
                >
                    {{ i === 0 ? '‹' : i === page.links.length - 1 ? '›' : link.label }}
                </Link>
                <span v-else class="num grid h-8 min-w-8 place-items-center px-2 text-ink-3/50">{{ i === 0 ? '‹' : i === page.links.length - 1 ? '›' : link.label }}</span>
            </template>
        </div>
    </nav>
</template>
