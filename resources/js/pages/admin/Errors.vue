<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KTag from '@/components/k/KTag.vue';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';

type Entry = {
    at: string;
    level: string;
    message: string;
    detail: string;
    source: 'server' | 'app';
};

defineProps<{ entries: Entry[] }>();

const opened = ref<number | null>(null);

/** Plain words for the problems that come up most. */
function plain(e: Entry): string | null {
    const m = e.message;
    if (e.source === 'app') return t('Phone app বন্ধ হয়ে গিয়েছিল (crash)');
    if (/Klok Pilot/i.test(m))
        return t(
            'Klok Pilot থেকে উত্তর আসেনি — campaign-এর খরচ বা বন্ধ/চালু তখন হয়নি, পরে নিজে আবার চেষ্টা হয়',
        );
    if (/FCM/i.test(m)) return t('Phone-এ notification পাঠানো যায়নি');
    if (/IMAP|receipt/i.test(m)) return t('Meta receipt-এর mail পড়া যায়নি');
    if (/SMTP|mail/i.test(m)) return t('Email পাঠানো যায়নি');
    return null;
}
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        :title="t('সমস্যা')"
        :lead="
            t(
                'Server-এ যা ভুল হয়েছে (Klok Pilot, notification, mail) আর phone app-এর crash — নতুনগুলো উপরে। চাপলে খুঁটিনাটি দেখা যায়। শুধু Owner দেখেন।',
            )
        "
    />

    <KEmpty v-if="!entries.length" :title="t('কোনো সমস্যা নেই')" />

    <ul v-else class="panel divide-y divide-line">
        <li v-for="(e, i) in entries" :key="i">
            <button
                type="button"
                class="flex w-full items-start gap-3 px-5 py-3.5 text-left hover:bg-sunken sm:px-6"
                :aria-expanded="opened === i"
                @click="opened = opened === i ? null : i"
            >
                <KTag
                    :tone="e.level === 'WARNING' ? 'wait' : 'out'"
                    class="mt-0.5 shrink-0"
                    >{{
                        e.source === 'app'
                            ? 'App'
                            : e.level === 'WARNING'
                              ? t('সতর্ক')
                              : t('ভুল')
                    }}</KTag
                >
                <span class="min-w-0 flex-1">
                    <span class="block text-[13.5px] font-medium">{{
                        plain(e) ?? e.message
                    }}</span>
                    <span
                        v-if="plain(e)"
                        class="block truncate text-[12.5px] text-ink-3"
                        >{{ e.message }}</span
                    >
                    <span class="num block text-[12px] text-ink-3"
                        >{{ e.at }} · {{ ago(e.at.replace(' ', 'T')) }}</span
                    >
                </span>
            </button>
            <pre
                v-if="opened === i && e.detail"
                class="mx-5 mb-4 overflow-x-auto rounded-lg bg-sunken p-3 text-[12px] whitespace-pre-wrap text-ink-2 sm:mx-6"
                >{{ e.detail }}</pre>
        </li>
    </ul>
</template>
