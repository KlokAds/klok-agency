<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';
import SectionTabs from '@/components/SectionTabs.vue';
import KButton from '@/components/k/KButton.vue';
import KTag from '@/components/k/KTag.vue';
import { t } from '@/lib/i18n';

// One receipt as it came, to read it yourself.
defineProps<{
    receipt: {
        id: number;
        status: string;
        subject: string | null;
        body: string | null;
        problem: string | null;
        entry_id: number | null;
    };
    statuses: Record<string, string>;
}>();
</script>

<template>
    <SectionTabs section="dollars" />
    <PageHeader title="Meta receipt" :lead="receipt.subject ?? ''">
        <KButton href="/dollars/receipts" variant="quiet" icon="back">{{
            t('ফিরে যান')
        }}</KButton>
    </PageHeader>
    <section class="panel max-w-3xl p-5 sm:p-6">
        <div class="flex flex-wrap items-center gap-2">
            <KTag>{{ t(statuses[receipt.status] ?? receipt.status) }}</KTag>
            <span v-if="receipt.problem" class="text-[12.5px] text-ink-3">{{
                receipt.problem
            }}</span>
        </div>
        <pre
            class="mt-4 max-h-[70vh] overflow-auto rounded-lg bg-sunken p-4 text-[12.5px] leading-relaxed whitespace-pre-wrap"
            >{{ receipt.body }}</pre>
    </section>
</template>
