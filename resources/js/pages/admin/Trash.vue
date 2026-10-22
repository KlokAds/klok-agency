<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KTag from '@/components/k/KTag.vue';
import { ago, money } from '@/lib/format';

defineProps<{ items: { type: string; type_label: string; id: number; title: string; amount: number | null; currency: 'usd' | 'bdt'; deleted_at: string }[] }>();

const can = computed(() => usePage().props.auth.can);

function restore(type: string, id: number): void {
    router.post(`/trash/${type}/${id}`, {}, { preserveScroll: true });
}
</script>

<template>
    <PageHeader eyebrow="নিয়ন্ত্রণ" title="Trash" lead="খাতা থেকে কিছুই চিরতরে মোছা হয় না। ভুল করে মুছলে এখান থেকে ফেরত আনুন।" />

    <section class="panel overflow-hidden">
        <KEmpty v-if="!items.length" title="Trash খালি" body="কিছু মুছলে এখানে দেখা যাবে।" />
        <ul v-else class="divide-y divide-dashed divide-line">
            <li v-for="i in items" :key="i.type + i.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6">
                <KTag>{{ i.type_label }}</KTag>
                <span class="min-w-0 flex-1 font-medium">{{ i.title }}</span>
                <span v-if="i.amount !== null" class="num text-[13.5px]">{{ money(i.amount, i.currency) }}</span>
                <span class="w-28 text-right text-[12.5px] text-ink-3">{{ ago(i.deleted_at) }}</span>
                <KButton v-if="can['trash.restore']" size="sm" icon="undo" @click="restore(i.type, i.id)">ফেরত আনুন</KButton>
            </li>
        </ul>
    </section>
</template>
