<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import KSelect from '@/components/k/KSelect.vue';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';

// With `sizes`, the reader picks how many rows a page shows from a dropdown; the row count is
// then shown even when everything fits on one page. The arrows are real buttons, not glyphs.
const props = defineProps<{ page: Paginated<unknown>; sizes?: number[] }>();

const sizeOptions = computed(() =>
    (props.sizes ?? []).map((n) => ({
        value: n,
        label: t('{n} প্রতি পাতায়', { n }),
    })),
);
const perPage = computed({
    get: () => props.page.per_page,
    set: (size: number | string | null) => {
        if (size !== null && Number(size) !== props.page.per_page) {
            resize(Number(size));
        }
    },
});

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

const prev = computed(() => props.page.links[0]);
const next = computed(() => props.page.links[props.page.links.length - 1]);

// A short window of pages: the first, the last, the current one and its neighbours; a gap is
// written as "…". The links come from the server, so the query stays as it is.
const numbers = computed(() => {
    const links = props.page.links.slice(1, -1);
    const byLabel = new Map(links.map((l) => [l.label, l]));
    const cur = props.page.current_page;
    const last = props.page.last_page;
    const wanted = [...new Set([1, cur - 1, cur, cur + 1, last])]
        .filter((n) => n >= 1 && n <= last)
        .sort((x, y) => x - y);
    const out: {
        key: string;
        label: string;
        url: string | null;
        active: boolean;
    }[] = [];
    let before = 0;
    for (const n of wanted) {
        if (n - before > 1) {
            out.push({ key: `gap${n}`, label: '…', url: null, active: false });
        }
        const link = byLabel.get(String(n));
        out.push({
            key: String(n),
            label: String(n),
            url: link?.url ?? null,
            active: n === cur,
        });
        before = n;
    }
    return out;
});
</script>

<template>
    <nav
        v-if="page.last_page > 1 || (props.sizes && page.total > 0)"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-[14px]"
        :aria-label="t('পাতা')"
    >
        <span class="flex items-center gap-3 text-ink-3"
            ><span
                ><span class="num">{{ page.from }}–{{ page.to }}</span> /
                <span class="num">{{ page.total }}</span></span
            >
            <span v-if="sizes" class="w-40">
                <KSelect v-model="perPage" :options="sizeOptions" size="sm" />
            </span>
        </span>
        <div v-if="page.last_page > 1" class="flex items-center gap-1">
            <component
                :is="prev?.url ? Link : 'span'"
                :href="prev?.url ?? undefined"
                preserve-scroll
                preserve-state
                class="grid size-9 place-items-center rounded-lg border transition-colors"
                :class="
                    prev?.url
                        ? 'border-line-2 bg-surface text-ink hover:border-brand hover:text-brand'
                        : 'border-line text-ink-3/50'
                "
                :aria-label="t('আগের পাতা')"
            >
                <KIcon name="back" :size="16" />
            </component>
            <template v-for="link in numbers" :key="link.key">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="num grid h-9 min-w-9 place-items-center rounded-lg px-2 font-semibold"
                    :class="
                        link.active
                            ? 'bg-brand text-white'
                            : 'text-ink-2 hover:bg-sunken'
                    "
                    >{{ link.label }}</Link
                >
                <span
                    v-else
                    class="num grid h-9 min-w-9 place-items-center px-2 text-ink-3/50"
                    >{{ link.label }}</span
                >
            </template>
            <component
                :is="next?.url ? Link : 'span'"
                :href="next?.url ?? undefined"
                preserve-scroll
                preserve-state
                class="grid size-9 place-items-center rounded-lg border transition-colors"
                :class="
                    next?.url
                        ? 'border-line-2 bg-surface text-ink hover:border-brand hover:text-brand'
                        : 'border-line text-ink-3/50'
                "
                :aria-label="t('পরের পাতা')"
            >
                <KIcon name="back" :size="16" class="rotate-180" />
            </component>
        </div>
    </nav>
</template>
