<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePopover } from '@/composables/usePopover';
import { isoDate, monthNames, niceDate, parseDate } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        placeholder?: string;
        clearable?: boolean;
        invalid?: boolean;
        max?: string;
        min?: string;
        id?: string;
        size?: 'md' | 'sm';
    }>(),
    { placeholder: 'তারিখ বেছে নিন', clearable: false, size: 'md' }, // i18n
);

const model = defineModel<string | null>();

// Bangladesh week: Saturday first, Friday off.
const WEEK = ['শনি', 'রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহ', 'শুক্র']; // i18n

const anchor = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const { open, style, toggle, close } = usePopover(anchor, panel);

const view = ref(new Date());
const mode = ref<'days' | 'months'>('days');
const focusDay = ref<string | null>(null);

watch(open, (value) => {
    if (value) {
        const current = parseDate(model.value) ?? new Date();
        view.value = new Date(current.getFullYear(), current.getMonth(), 1);
        focusDay.value = isoDate(current);
        mode.value = 'days';
    }
});

const todayIso = isoDate(new Date());

const days = computed(() => {
    const year = view.value.getFullYear();
    const month = view.value.getMonth();
    const first = new Date(year, month, 1);
    const offset = (first.getDay() + 1) % 7; // JS Sunday=0 → Saturday-first grid
    const start = new Date(year, month, 1 - offset);

    return Array.from({ length: 42 }, (_, i) => {
        const date = new Date(
            start.getFullYear(),
            start.getMonth(),
            start.getDate() + i,
        );
        const iso = isoDate(date);

        return {
            iso,
            day: date.getDate(),
            inMonth: date.getMonth() === month,
            friday: date.getDay() === 5,
            disabled:
                (props.max && iso > props.max) ||
                (props.min && iso < props.min),
        };
    });
});

function shift(months: number): void {
    view.value = new Date(
        view.value.getFullYear(),
        view.value.getMonth() + months,
        1,
    );
}

function pick(iso: string): void {
    model.value = iso;
    close();
    anchor.value?.querySelector('button')?.focus();
}

function quick(offset: number): void {
    const date = new Date();
    date.setDate(date.getDate() + offset);
    pick(isoDate(date));
}

function onGridKey(event: KeyboardEvent): void {
    const steps: Record<string, number> = {
        ArrowLeft: -1,
        ArrowRight: 1,
        ArrowUp: -7,
        ArrowDown: 7,
    };

    if (event.key in steps) {
        event.preventDefault();
        const base = parseDate(focusDay.value) ?? new Date();
        base.setDate(base.getDate() + steps[event.key]);
        focusDay.value = isoDate(base);

        if (base.getMonth() !== view.value.getMonth()) {
            view.value = new Date(base.getFullYear(), base.getMonth(), 1);
        }
    } else if (event.key === 'Enter' && focusDay.value) {
        event.preventDefault();
        pick(focusDay.value);
    }
}
</script>

<template>
    <div ref="anchor" class="relative min-w-0">
        <button
            :id="id"
            type="button"
            class="field-box cursor-pointer text-left"
            :class="size === 'sm' ? 'min-h-9! text-[14px]' : 'text-[15.5px]'"
            :data-open="open"
            :data-invalid="invalid"
            aria-haspopup="dialog"
            @click="toggle"
        >
            <svg
                viewBox="0 0 16 16"
                class="size-4 shrink-0 text-ink-3"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <rect x="2.5" y="3.5" width="11" height="10" rx="2" />
                <path d="M2.5 6.5h11M5.5 2v3M10.5 2v3" />
            </svg>
            <span
                class="flex-1 truncate"
                :class="model ? 'num text-ink' : 'text-ink-3'"
                >{{ model ? niceDate(model) : t(placeholder) }}</span
            >
            <span
                v-if="clearable && model"
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
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="panel"
                :style="style"
                class="popover k-rise w-[296px] p-3"
                role="dialog"
                :aria-label="t('তারিখ')"
            >
                <div class="mb-2 flex items-center justify-between">
                    <button
                        type="button"
                        class="grid size-8 place-items-center rounded-lg text-ink-2 hover:bg-sunken"
                        :aria-label="t('আগের মাস')"
                        @click="mode === 'days' ? shift(-1) : shift(-12)"
                    >
                        <svg
                            viewBox="0 0 16 16"
                            class="size-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M10 3.5L5.5 8l4.5 4.5" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-2.5 py-1 font-bn text-[15.5px] font-semibold hover:bg-sunken"
                        @click="mode = mode === 'days' ? 'months' : 'days'"
                    >
                        <template v-if="mode === 'days'"
                            >{{ monthNames()[view.getMonth()] }}
                            <span class="num font-medium">{{
                                view.getFullYear()
                            }}</span></template
                        >
                        <span v-else class="num">{{ view.getFullYear() }}</span>
                    </button>
                    <button
                        type="button"
                        class="grid size-8 place-items-center rounded-lg text-ink-2 hover:bg-sunken"
                        :aria-label="t('পরের মাস')"
                        @click="mode === 'days' ? shift(1) : shift(12)"
                    >
                        <svg
                            viewBox="0 0 16 16"
                            class="size-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M6 3.5L10.5 8 6 12.5" />
                        </svg>
                    </button>
                </div>

                <div
                    v-if="mode === 'days'"
                    tabindex="0"
                    class="rounded-lg"
                    @keydown="onGridKey"
                >
                    <div class="grid grid-cols-7 pb-1">
                        <span
                            v-for="(w, i) in WEEK"
                            :key="w"
                            class="py-1 text-center font-bn text-[13px] font-medium"
                            :class="i === 6 ? 'text-out' : 'text-ink-3'"
                            >{{ t(w) }}</span
                        >
                    </div>
                    <div class="grid grid-cols-7 gap-0.5">
                        <button
                            v-for="d in days"
                            :key="d.iso"
                            type="button"
                            tabindex="-1"
                            :disabled="!!d.disabled"
                            class="num relative grid h-9 place-items-center rounded-lg text-[14px] transition-colors disabled:pointer-events-none disabled:opacity-30"
                            :class="[
                                d.iso === model
                                    ? 'bg-brand font-semibold text-white'
                                    : d.inMonth
                                      ? d.friday
                                          ? 'text-out hover:bg-sunken'
                                          : 'text-ink hover:bg-sunken'
                                      : 'text-ink-3 hover:bg-sunken',
                                d.iso === focusDay && d.iso !== model
                                    ? 'ring-1 ring-brand'
                                    : '',
                            ]"
                            @click="pick(d.iso)"
                        >
                            {{ d.day }}
                            <span
                                v-if="d.iso === todayIso && d.iso !== model"
                                class="absolute bottom-1 size-1 rounded-full bg-brand"
                            />
                        </button>
                    </div>
                </div>

                <div v-else class="grid grid-cols-3 gap-1.5 py-1">
                    <button
                        v-for="(name, i) in monthNames()"
                        :key="name"
                        type="button"
                        class="rounded-lg py-2.5 font-bn text-[14.5px] hover:bg-sunken"
                        :class="
                            i === view.getMonth()
                                ? 'bg-brand-soft font-semibold text-brand-ink'
                                : ''
                        "
                        @click="
                            view = new Date(view.getFullYear(), i, 1);
                            mode = 'days';
                        "
                    >
                        {{ name }}
                    </button>
                </div>

                <div
                    class="mt-2 flex items-center gap-1.5 border-t border-line pt-2.5"
                >
                    <button
                        type="button"
                        class="rounded-md bg-sunken px-2.5 py-1 text-[13.5px] font-medium hover:bg-brand-soft hover:text-brand-ink"
                        @click="quick(0)"
                    >
                        {{ t('আজ') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-sunken px-2.5 py-1 text-[13.5px] font-medium hover:bg-brand-soft hover:text-brand-ink"
                        @click="quick(-1)"
                    >
                        {{ t('গতকাল') }}
                    </button>
                    <span class="flex-1" />
                    <button
                        v-if="clearable && model"
                        type="button"
                        class="rounded-md px-2 py-1 text-[13.5px] text-ink-3 hover:text-out"
                        @click="
                            model = null;
                            close();
                        "
                    >
                        {{ t('মুছুন') }}
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
