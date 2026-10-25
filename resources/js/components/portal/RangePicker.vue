<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { t } from '@/lib/i18n';

/** The period a client looks at: today to a year, or any two dates. */
const props = defineProps<{
    url: string;
    range: { key: string; from: string; to: string };
}>();

const ranges = [
    { key: 'today', label: 'আজ' }, // i18n
    { key: 'yesterday', label: 'গতকাল' }, // i18n
    { key: '7d', label: '৭ দিন' }, // i18n
    { key: '15d', label: '১৫ দিন' }, // i18n
    { key: '30d', label: '১ মাস' }, // i18n
    { key: '90d', label: '৩ মাস' }, // i18n
    { key: '180d', label: '৬ মাস' }, // i18n
    { key: '365d', label: '১ বছর' }, // i18n
    { key: 'custom', label: 'নিজে বাছুন' }, // i18n
];

const custom = ref(props.range.key === 'custom');
const from = ref(props.range.from);
const to = ref(props.range.to);

function go(key: string): void {
    if (key === 'custom') {
        custom.value = true;
        return;
    }
    custom.value = false;
    router.get(props.url, { range: key }, { preserveScroll: true });
}

function apply(): void {
    router.get(
        props.url,
        { range: 'custom', from: from.value, to: to.value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div>
        <div
            class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0"
        >
            <button
                v-for="r in ranges"
                :key="r.key"
                type="button"
                class="shrink-0 rounded-full border px-3.5 py-1.5 text-[13px] font-semibold transition-colors"
                :class="
                    (custom ? 'custom' : range.key) === r.key
                        ? 'border-brand bg-brand text-white'
                        : 'border-line-2 text-ink-2 hover:border-ink-3'
                "
                @click="go(r.key)"
            >
                {{ t(r.label) }}
            </button>
        </div>
        <div
            v-if="custom"
            class="mt-3 flex flex-wrap items-end gap-2 rounded-xl border border-line p-3"
        >
            <label class="grid gap-1 text-[12px] text-ink-3"
                >{{ t('থেকে') }}
                <input
                    v-model="from"
                    type="date"
                    class="h-10 rounded-lg border border-line-2 bg-transparent px-2 text-[14px] text-ink"
            /></label>
            <label class="grid gap-1 text-[12px] text-ink-3"
                >{{ t('পর্যন্ত') }}
                <input
                    v-model="to"
                    type="date"
                    class="h-10 rounded-lg border border-line-2 bg-transparent px-2 text-[14px] text-ink"
            /></label>
            <button
                type="button"
                class="h-10 rounded-lg bg-brand px-4 text-[13.5px] font-semibold text-white"
                @click="apply"
            >
                {{ t('দেখুন') }}
            </button>
        </div>
    </div>
</template>
