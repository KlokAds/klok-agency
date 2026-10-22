<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';

// With `sizes`, the reader can pick how many rows a page shows; the row
// count is then shown even when everything fits on one page.
const props = defineProps<{ page: Paginated<unknown>; sizes?: number[] }>();

function resize(size: number): void {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', String(size));
    url.searchParams.delete('page');
    router.get(
        url.pathname + url.search,
        {},
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <nav
        v-if="page.last_page > 1 || (props.sizes && page.total > 0)"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-[13px]"
        :aria-label="t('পাতা')"
    >
        <span class="flex items-center gap-3 text-ink-3"
            ><span
                ><span class="num">{{ page.from }}–{{ page.to }}</span> /
                <span class="num">{{ page.total }}</span></span
            >
            <span v-if="sizes" class="flex items-center gap-1">
                <button
                    v-for="size in sizes"
                    :key="size"
                    type="button"
                    class="num h-7 rounded-md px-2 text-[12px]"
                    :class="
                        page.per_page === size
                            ? 'bg-sunken font-semibold text-ink'
                            : 'hover:bg-sunken'
                    "
                    @click="resize(size)"
                >
                    {{ size }}
                </button>
                <span class="text-[12px]">{{ t('প্রতি পাতায়') }}</span>
            </span></span
        >
        <div class="flex gap-1">
            <template v-for="(link, i) in page.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="num grid h-8 min-w-8 place-items-center rounded-lg px-2"
                    :class="
                        link.active
                            ? 'bg-ink text-paper'
                            : 'text-ink-2 hover:bg-sunken'
                    "
                >
                    {{
                        i === 0
                            ? '‹'
                            : i === page.links.length - 1
                              ? '›'
                              : link.label
                    }}
                </Link>
                <span
                    v-else
                    class="num grid h-8 min-w-8 place-items-center px-2 text-ink-3/50"
                    >{{
                        i === 0
                            ? '‹'
                            : i === page.links.length - 1
                              ? '›'
                              : link.label
                    }}</span
                >
            </template>
        </div>
    </nav>
</template>
