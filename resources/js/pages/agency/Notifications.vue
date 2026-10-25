<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import NotificationItem from '@/components/NotificationItem.vue';
import type { Notice } from '@/components/NotificationItem.vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import { confirmAction } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';

const props = defineProps<{
    notifications: Paginated<Notice>;
    filters: { show: string; q: string };
    counts: { all: number; unread: number };
}>();

// Every notification: all, unread, urgent (money and ads that need you) or read; and a search.
const show = ref(props.filters.show);
const q = ref(props.filters.q);

function apply(): void {
    router.get(
        '/notifications',
        {
            show: show.value === 'all' ? undefined : show.value,
            q: q.value.trim() || undefined,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

watch(show, apply);
let typing: number | undefined;
watch(q, () => {
    window.clearTimeout(typing);
    typing = window.setTimeout(apply, 350);
});

function changed(): void {
    window.dispatchEvent(new Event('notifications:changed'));
}

function readAll(): void {
    router.post(
        '/notifications/read-all',
        {},
        { preserveScroll: true, onSuccess: changed },
    );
}

async function clearRead(): Promise<void> {
    const ok = await confirmAction({
        title: t('পড়া notification মুছবেন?'),
        body: t('যেগুলো পড়া হয়ে গেছে সেগুলো মুছে যাবে। না-পড়াগুলো থাকবে।'),
        confirm: t('মুছে ফেলুন'),
        danger: true,
    });

    if (ok) {
        router.post(
            '/notifications/clear',
            {},
            { preserveScroll: true, onSuccess: changed },
        );
    }
}

function remove(item: Notice): void {
    router.delete(`/notifications/${item.id}`, {
        preserveScroll: true,
        onSuccess: changed,
    });
}

function opened(item: Notice): void {
    if (!item.read) {
        router.post(
            `/notifications/${item.id}/read`,
            {},
            { preserveScroll: true, preserveState: true, onSuccess: changed },
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
                'সব notification এক জায়গায় — payment, reminder, ad শেষ হওয়ার খবর আর দিনের সারসংক্ষেপ। Phone-এ পড়লে এখানেও পড়া হয়ে যায়।',
            )
        "
    >
        <KButton
            variant="primary"
            icon="check"
            :disabled="!counts.unread"
            @click="readAll"
            >{{ t('সব পড়া হয়েছে') }}</KButton
        >
        <KButton icon="bin" @click="clearRead">{{
            t('পড়াগুলো মুছুন')
        }}</KButton>
    </PageHeader>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <KSegment
            v-model="show"
            size="sm"
            :options="[
                { value: 'all', label: t('সব') + ' · ' + counts.all },
                {
                    value: 'unread',
                    label: t('না-পড়া') + ' · ' + counts.unread,
                },
                { value: 'urgent', label: t('জরুরি') },
                { value: 'read', label: t('পড়া') },
            ]"
        />
        <KInput
            v-model="q"
            size="sm"
            :placeholder="t('খুঁজুন — client, invoice, অঙ্ক…')"
            class="sm:ml-auto sm:max-w-[280px]"
        />
    </div>

    <section class="panel overflow-hidden p-2">
        <KEmpty
            v-if="!notifications.data.length"
            :title="
                filters.q || filters.show !== 'all'
                    ? t('এই খোঁজে কিছু পাওয়া যায়নি')
                    : t('কোনো notification নেই')
            "
        />
        <div
            v-for="n in notifications.data"
            :key="n.id"
            class="group flex items-start gap-1"
        >
            <NotificationItem
                :item="n"
                full
                class="min-w-0 flex-1"
                @open="opened"
            />
            <button
                type="button"
                class="mt-2 mr-1 grid size-9 shrink-0 place-items-center rounded-lg text-ink-3 hover:bg-sunken hover:text-out"
                :aria-label="t('মুছুন')"
                :title="t('মুছুন')"
                @click="remove(n)"
            >
                <KIcon name="close" :size="15" />
            </button>
        </div>
        <KPager :page="notifications" :sizes="[10, 25, 50, 100]" />
    </section>
</template>
