<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import KButton from '@/components/k/KButton.vue';
import { t } from '@/lib/i18n';

/** The period a client looks at: all of it, today to a year, or any two dates. */
const props = defineProps<{
    url: string;
    range: { key: string; from: string; to: string };
}>();

const ranges = [
    { key: 'all', label: 'শুরু থেকে' }, // i18n
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
                class="inline-flex h-9 shrink-0 items-center rounded-full border px-3.5 text-[13px] font-semibold whitespace-nowrap transition-colors"
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
        <!-- Two dates side by side, the button under them on a phone; one row on a computer. -->
        <div
            v-if="custom"
            class="panel mt-3 grid grid-cols-2 gap-2 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
        >
            <label class="grid min-w-0 gap-1 text-[12px] text-ink-3"
                >{{ t('থেকে') }}
                <input
                    v-model="from"
                    type="date"
                    class="num h-10 w-full min-w-0 rounded-[10px] border border-line-2 bg-surface px-3 text-[14px] text-ink transition-colors focus:border-brand"
            /></label>
            <label class="grid min-w-0 gap-1 text-[12px] text-ink-3"
                >{{ t('পর্যন্ত') }}
                <input
                    v-model="to"
                    type="date"
                    class="num h-10 w-full min-w-0 rounded-[10px] border border-line-2 bg-surface px-3 text-[14px] text-ink transition-colors focus:border-brand"
            /></label>
            <KButton
                variant="primary"
                class="col-span-2 sm:col-span-1"
                @click="apply"
                >{{ t('দেখুন') }}</KButton
            >
        </div>
    </div>
</template>
