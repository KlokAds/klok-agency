<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import NotificationItem from '@/components/NotificationItem.vue';
import type { Notice } from '@/components/NotificationItem.vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KPager from '@/components/k/KPager.vue';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';

defineProps<{ notifications: Paginated<Notice> }>();

function readAll(): void {
    router.post('/notifications/read-all', {}, { preserveScroll: true });
}

function opened(item: Notice): void {
    if (!item.read) {
        router.post(
            `/notifications/${item.id}/read`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }
}
</script>

<template>
    <PageHeader
        :eyebrow="t('আমার account')"
        title="Notification"
        :lead="
            t(
                'আপনার কাজের reminder, ad শেষ হওয়ার খবর আর দিনের শেষের সারসংক্ষেপ।',
            )
        "
    >
        <KButton icon="check" @click="readAll">{{
            t('সব পড়া হয়েছে')
        }}</KButton>
    </PageHeader>

    <section class="panel overflow-hidden p-2">
        <KEmpty
            v-if="!notifications.data.length"
            :title="t('কোনো notification নেই')"
        />
        <NotificationItem
            v-for="n in notifications.data"
            :key="n.id"
            :item="n"
            full
            @open="opened"
        />
        <KPager :page="notifications" />
    </section>
</template>
