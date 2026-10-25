<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import KIcon from '@/components/k/KIcon.vue';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';

type Notice = {
    id: string;
    kind: string;
    title: string;
    body: string | null;
    params: Record<string, string | number | null>;
    url: string | null;
    read: boolean;
    at: string;
};

defineProps<{ notifications: Notice[] }>();

/** The text in this reader's language, with its {values} filled in. */
function fill(text: string, params: Notice['params']): string {
    return t(text).replace(/\{(\w+)\}/g, (_, key: string) =>
        String(params[key] ?? ''),
    );
}

function look(kind: string): { icon: string; tone: string } {
    if (['client_paid', 'client_part_paid', 'client_extended'].includes(kind)) {
        return { icon: 'check', tone: 'bg-in-soft text-in' };
    }
    if (
        [
            'client_overdue',
            'client_paused',
            'client_credit_low',
            'client_ending',
        ].includes(kind)
    ) {
        return { icon: 'warn', tone: 'bg-wait-soft text-wait' };
    }
    if (kind === 'client_summary') {
        return { icon: 'chart', tone: 'bg-brand-soft text-brand' };
    }
    return { icon: 'doc', tone: 'bg-sunken text-ink-2' };
}
</script>

<template>
    <Head :title="t('Notification')" />

    <h1 class="text-[24px] font-bold">{{ t('Notification') }}</h1>

    <p
        v-if="!notifications.length"
        class="panel mt-5 p-5 text-[14px] text-ink-3"
    >
        {{ t('এখনো কিছু নেই। Invoice, পেমেন্ট আর বিজ্ঞাপনের খবর এখানে আসবে।') }}
    </p>

    <ul class="mt-5 grid grid-cols-1 gap-2">
        <li v-for="n in notifications" :key="n.id">
            <component
                :is="n.url ? 'a' : 'div'"
                :href="n.url ?? undefined"
                class="panel flex items-start gap-3 p-4 transition-colors"
                :class="[
                    n.url ? 'hover:border-brand' : '',
                    n.read ? '' : 'border-brand/50',
                ]"
            >
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-full"
                    :class="look(n.kind).tone"
                    ><KIcon :name="look(n.kind).icon" :size="18"
                /></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[14.5px] font-semibold">{{
                        fill(n.title, n.params)
                    }}</span>
                    <span
                        v-if="n.body"
                        class="mt-0.5 block text-[13px] text-ink-2"
                        >{{ fill(n.body, n.params) }}</span
                    >
                    <span class="mt-1 block text-[12px] text-ink-3">{{
                        ago(n.at)
                    }}</span>
                </span>
                <span
                    v-if="!n.read"
                    class="mt-1.5 size-2 shrink-0 rounded-full bg-brand"
                />
            </component>
        </li>
    </ul>
</template>
