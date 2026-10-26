<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KSelect from '@/components/k/KSelect.vue';
import { niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The period a page looks at, the same everywhere: the usual spans (today to a year) in one
 * dropdown, "from the start" and "pick dates" as bordered buttons beside it, and the dates it
 * covers written under. Other query params ride along so the page's filters combine.
 */
const props = defineProps<{
    url: string;
    range: { key: string; from: string; to: string };
    params?: Record<string, string | number | null | undefined>;
    /** No dates line under (the page writes them itself). */
    dense?: boolean;
}>();

const spans = [
    { value: 'today', label: 'আজ' }, // i18n
    { value: 'yesterday', label: 'গতকাল' }, // i18n
    { value: '7d', label: '৭ দিন' }, // i18n
    { value: '15d', label: '১৫ দিন' }, // i18n
    { value: '30d', label: '১ মাস' }, // i18n
    { value: '90d', label: '৩ মাস' }, // i18n
    { value: '180d', label: '৬ মাস' }, // i18n
    { value: '365d', label: '১ বছর' }, // i18n
];
const options = computed(() =>
    spans.map((s) => ({ value: s.value, label: t(s.label) })),
);

const custom = ref(props.range.key === 'custom');
const from = ref<string | null>(props.range.from);
const to = ref<string | null>(props.range.to);
const span = ref<string | null>(
    spans.some((s) => s.value === props.range.key) ? props.range.key : null,
);
const chosen = computed(() => (custom.value ? 'custom' : props.range.key));

// The dates the chosen period covers: one date for a day, else from – to.
const covers = computed(() =>
    props.range.from === props.range.to
        ? niceDate(props.range.from)
        : `${niceDate(props.range.from, false)} – ${niceDate(props.range.to)}`,
);

watch(span, (key) => {
    if (key && key !== props.range.key) {
        go(key);
    }
});

function go(key: string): void {
    if (key === 'custom') {
        custom.value = !custom.value;
        return;
    }
    custom.value = false;
    if (!spans.some((s) => s.value === key)) {
        span.value = null;
    }
    router.get(
        props.url,
        { ...props.params, range: key },
        { preserveScroll: true },
    );
}

function apply(): void {
    router.get(
        props.url,
        {
            ...props.params,
            range: 'custom',
            from: from.value ?? props.range.from,
            to: to.value ?? props.range.to,
        },
        { preserveScroll: true },
    );
}

const pick =
    'h-9 rounded-[10px] border px-2.5 text-[14px] font-semibold whitespace-nowrap transition-colors sm:px-4';
const on = 'k-accent';
const off = 'k-chip';
</script>

<template>
    <div>
        <div
            class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-2 sm:flex"
        >
            <div class="min-w-0 sm:basis-40">
                <KSelect
                    v-model="span"
                    :options="options"
                    :placeholder="t('সময় বাছুন')"
                    size="sm"
                />
            </div>
            <button
                type="button"
                :class="[pick, chosen === 'all' ? on : off]"
                @click="go('all')"
            >
                {{ t('শুরু থেকে') }}
            </button>
            <button
                type="button"
                :class="[pick, chosen === 'custom' ? on : off]"
                @click="go('custom')"
            >
                {{ t('নিজে বাছুন') }}
            </button>
        </div>
        <p v-if="!custom && !dense" class="num mt-2 text-[13.5px] text-ink-3">
            {{ covers }}
        </p>
        <!-- Two dates side by side, the button under them on a phone; one row on a computer. -->
        <div
            v-if="custom"
            class="panel mt-3 grid grid-cols-2 gap-2 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
        >
            <label class="grid min-w-0 gap-1 text-[14px] text-ink-3"
                >{{ t('থেকে') }}
                <KDate v-model="from" :max="to ?? undefined" />
            </label>
            <label class="grid min-w-0 gap-1 text-[14px] text-ink-3"
                >{{ t('পর্যন্ত') }}
                <KDate v-model="to" :min="from ?? undefined" />
            </label>
            <KButton
                variant="primary"
                class="col-span-2 sm:col-span-1"
                @click="apply"
                >{{ t('দেখুন') }}</KButton
            >
        </div>
    </div>
</template>
