<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';

type Hit = { title: string; hint?: string | null; url: string };
type Group = { key: string; label: string; items: Hit[] };

// One search box for the whole app: pages, clients, campaigns, BDT and USD
// rows, tasks. Ctrl+K (or /) opens it; arrows and Enter pick a result.
const props = defineProps<{
    pages: { label: string; href: string; icon: string }[];
}>();

const open = ref(false);
const q = ref('');
const groups = ref<Group[]>([]);
const loading = ref(false);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
let timer: number | undefined;
let asked = 0;

const pageHits = computed<Group[]>(() => {
    const term = q.value.trim().toLowerCase();
    const items = props.pages
        .filter((p) => !term || t(p.label).toLowerCase().includes(term))
        .slice(0, term ? 6 : 8)
        .map((p) => ({ title: t(p.label), hint: null, url: p.href }));

    return items.length ? [{ key: 'pages', label: t('পাতা'), items }] : [];
});

const all = computed(() => [...pageHits.value, ...groups.value]);
const flat = computed(() => all.value.flatMap((g) => g.items));

function show(): void {
    open.value = true;
    active.value = 0;
    void nextTick(() => input.value?.focus());
}

function close(): void {
    open.value = false;
    q.value = '';
    groups.value = [];
}

function go(hit: Hit | undefined): void {
    if (!hit) {
        return;
    }

    close();
    router.visit(hit.url);
}

watch(q, (value) => {
    active.value = 0;
    window.clearTimeout(timer);

    if (value.trim().length < 2) {
        groups.value = [];

        return;
    }

    timer = window.setTimeout(async () => {
        const id = ++asked;
        loading.value = true;

        try {
            const result = await getJson<{ groups: Group[] }>(
                '/search?q=' + encodeURIComponent(value.trim()),
            );

            if (id === asked) {
                groups.value = result.groups;
            }
        } finally {
            if (id === asked) {
                loading.value = false;
            }
        }
    }, 220);
});

function onKey(event: KeyboardEvent): void {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(
        (event.target as HTMLElement | null)?.tagName ?? '',
    );

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        if (open.value) {
            close();
        } else {
            show();
        }
    } else if (event.key === '/' && !typing && !open.value) {
        event.preventDefault();
        show();
    }
}

function onListKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(flat.value.length - 1, active.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(flat.value[active.value]);
    } else if (event.key === 'Escape') {
        close();
    }
}

const indexOf = (hit: Hit) => flat.value.indexOf(hit);

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <!-- Desktop: a search field look-alike; phone: an icon -->
    <button
        type="button"
        class="hidden h-10 w-full max-w-[360px] items-center gap-2 rounded-xl border border-line bg-surface px-3 text-left text-[13.5px] text-ink-3 transition-colors hover:border-line-2 md:flex"
        @click="show"
    >
        <KIcon name="search" :size="16" />
        <span class="flex-1 truncate">{{
            t('খুঁজুন — client, campaign, entry…')
        }}</span>
        <kbd
            class="rounded-md border border-line px-1.5 py-0.5 font-mono text-[11px]"
            >Ctrl K</kbd
        >
    </button>
    <button
        type="button"
        class="grid size-10 place-items-center rounded-lg hover:bg-sunken md:hidden"
        :aria-label="t('খুঁজুন')"
        @click="show"
    >
        <KIcon name="search" :size="18" />
    </button>

    <Teleport to="body">
        <div
            v-if="open"
            class="k-fade fixed inset-0 z-[70] flex items-start justify-center bg-black/40 p-3 pt-[8vh] sm:p-6 sm:pt-[12vh]"
            @mousedown.self="close"
        >
            <div
                class="flex max-h-[76vh] w-full max-w-[640px] flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl"
                role="dialog"
                :aria-label="t('খুঁজুন')"
            >
                <div class="flex items-center gap-3 border-b border-line px-4">
                    <KIcon name="search" :size="18" class="text-ink-3" />
                    <input
                        ref="input"
                        v-model="q"
                        type="text"
                        class="h-14 min-w-0 flex-1 bg-transparent text-[15px] outline-none placeholder:text-ink-3"
                        :placeholder="
                            t('Client, campaign, entry, কাজ বা পাতা খুঁজুন…')
                        "
                        @keydown="onListKey"
                    />
                    <span
                        v-if="loading"
                        class="size-4 shrink-0 animate-spin rounded-full border-2 border-line border-t-brand"
                    />
                    <button
                        type="button"
                        class="rounded-md border border-line px-1.5 py-0.5 font-mono text-[11px] text-ink-3"
                        @click="close"
                    >
                        Esc
                    </button>
                </div>

                <div class="overflow-y-auto p-2">
                    <p
                        v-if="
                            q.trim().length >= 2 &&
                            !loading &&
                            !groups.length &&
                            !pageHits.length
                        "
                        class="px-3 py-8 text-center text-[13px] text-ink-3"
                    >
                        {{ t('কিছু পাওয়া যায়নি') }}
                    </p>
                    <div v-for="g in all" :key="g.key" class="mb-2 last:mb-0">
                        <p
                            class="px-3 pt-2 pb-1 text-[11px] font-bold tracking-[0.12em] text-ink-3 uppercase"
                        >
                            {{ g.label }}
                        </p>
                        <button
                            v-for="hit in g.items"
                            :key="g.key + hit.url + hit.title"
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors"
                            :class="
                                indexOf(hit) === active
                                    ? 'bg-brand-soft text-brand-ink'
                                    : 'hover:bg-sunken'
                            "
                            @mouseenter="active = indexOf(hit)"
                            @click="go(hit)"
                        >
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-[14px] font-medium"
                                    >{{ hit.title }}</span
                                >
                                <span
                                    v-if="hit.hint"
                                    class="block truncate text-[12px] text-ink-3"
                                    >{{ hit.hint }}</span
                                >
                            </span>
                            <KIcon
                                name="arrow"
                                :size="14"
                                class="shrink-0 text-ink-3"
                            />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
