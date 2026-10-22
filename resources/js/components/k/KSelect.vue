<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { usePopover } from '@/composables/usePopover';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';
import KIcon from './KIcon.vue';

const props = withDefaults(
    defineProps<{
        options: Option[];
        placeholder?: string;
        clearable?: boolean;
        searchable?: boolean | 'auto';
        invalid?: boolean;
        disabled?: boolean;
        id?: string;
        size?: 'md' | 'sm';
    }>(),
    {
        placeholder: 'বেছে নিন',
        clearable: false,
        searchable: 'auto',
        size: 'md',
    }, // i18n
);

const model = defineModel<string | number | null>();

const anchor = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const search = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);
const query = ref('');
const active = ref(0);

const { open, style, toggle, close, show } = usePopover(anchor, panel, {
    matchWidth: true,
    minWidth: 220,
});

const selected = computed(() =>
    props.options.find((o) => String(o.value) === String(model.value ?? '')),
);
const withSearch = computed(() =>
    props.searchable === 'auto' ? props.options.length > 7 : props.searchable,
);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();

    if (!q) {
        return props.options;
    }

    return props.options.filter((o) =>
        `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(q),
    );
});

watch(open, async (value) => {
    if (!value) {
        query.value = '';

        return;
    }

    const index = filtered.value.findIndex(
        (o) => String(o.value) === String(model.value ?? ''),
    );
    active.value = Math.max(0, index);
    await nextTick();
    (withSearch.value ? search.value : list.value)?.focus();
    scrollActive();
});

watch(query, () => (active.value = 0));

function choose(option: Option): void {
    model.value = option.value;
    close();
    anchor.value?.querySelector('button')?.focus();
}

function scrollActive(): void {
    nextTick(() => {
        list.value
            ?.querySelector(`[data-index="${active.value}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    });
}

function onKey(event: KeyboardEvent): void {
    if (!open.value) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            show();
        }

        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(filtered.value.length - 1, active.value + 1);
        scrollActive();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
        scrollActive();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const option = filtered.value[active.value];
        if (option) {
            choose(option);
        }
    } else if (event.key === 'Tab') {
        close();
    }
}
</script>

<template>
    <div ref="anchor" class="relative min-w-0">
        <button
            :id="id"
            type="button"
            class="field-box text-left"
            :class="[
                size === 'sm' ? 'min-h-9! text-[13px]' : 'text-[14.5px]',
                disabled ? 'pointer-events-none opacity-55' : 'cursor-pointer',
            ]"
            :data-open="open"
            :data-invalid="invalid"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :disabled="disabled"
            @click="toggle"
            @keydown="onKey"
        >
            <KIcon
                v-if="selected?.icon"
                :name="String(selected.icon)"
                :size="18"
                class="text-brand-ink"
            />
            <span
                class="min-w-0 flex-1 truncate"
                :class="selected ? 'text-ink' : 'text-ink-3'"
            >
                <span :class="selected?.note && 'font-semibold'">{{
                    selected?.label ?? t(placeholder)
                }}</span>
                <span
                    v-if="selected?.note"
                    class="block truncate text-[12px] font-normal text-ink-3"
                    >{{ selected.note }}</span
                >
            </span>
            <span
                v-if="clearable && selected"
                role="button"
                tabindex="-1"
                :aria-label="t('মুছুন')"
                class="-mr-1 grid size-6 place-items-center rounded-md text-ink-3 hover:bg-sunken hover:text-ink"
                @click.stop="model = null"
            >
                <svg
                    viewBox="0 0 16 16"
                    class="size-3.5"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                >
                    <path d="M4 4l8 8M12 4l-8 8" />
                </svg>
            </span>
            <svg
                viewBox="0 0 16 16"
                class="size-4 shrink-0 text-ink-3 transition-transform"
                :class="open && 'rotate-180'"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M4 6.5l4 4 4-4" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="panel"
                :style="style"
                class="popover k-rise overflow-hidden"
                @keydown="onKey"
            >
                <div v-if="withSearch" class="border-b border-line p-2">
                    <div
                        class="flex items-center gap-2 rounded-lg bg-sunken px-2.5"
                    >
                        <svg
                            viewBox="0 0 16 16"
                            class="size-3.5 text-ink-3"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                        >
                            <circle cx="7" cy="7" r="4.5" />
                            <path d="M10.5 10.5L14 14" />
                        </svg>
                        <input
                            ref="search"
                            v-model="query"
                            type="text"
                            class="h-9 w-full text-[13.5px]"
                            :placeholder="t('খুঁজুন…')"
                        />
                    </div>
                </div>

                <ul
                    ref="list"
                    role="listbox"
                    tabindex="-1"
                    class="max-h-64 overflow-y-auto p-1.5"
                >
                    <li
                        v-for="(option, index) in filtered"
                        :key="String(option.value)"
                        role="option"
                        :data-index="index"
                        :aria-selected="
                            String(option.value) === String(model ?? '')
                        "
                        class="flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-[14px]"
                        :class="
                            index === active
                                ? 'bg-brand-soft text-brand-ink'
                                : 'text-ink'
                        "
                        @mouseenter="active = index"
                        @click="choose(option)"
                    >
                        <KIcon
                            v-if="option.icon"
                            :name="String(option.icon)"
                            :size="18"
                            :class="
                                index === active
                                    ? 'text-brand-ink'
                                    : 'text-ink-3'
                            "
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate"
                                :class="option.note && 'font-semibold'"
                                >{{ option.label }}</span
                            >
                            <span
                                v-if="option.note"
                                class="block truncate text-[12px] text-ink-3"
                                >{{ option.note }}</span
                            >
                        </span>
                        <span
                            v-if="option.hint"
                            class="shrink-0 text-[11.5px] text-ink-3"
                            >{{ option.hint }}</span
                        >
                        <svg
                            v-if="String(option.value) === String(model ?? '')"
                            viewBox="0 0 16 16"
                            class="size-4 shrink-0 text-brand"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M3.5 8.5l3 3 6-7" />
                        </svg>
                    </li>
                    <li
                        v-if="!filtered.length"
                        class="px-3 py-6 text-center text-[13px] text-ink-3"
                    >
                        {{ t('কিছু পাওয়া যায়নি') }}
                    </li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>
