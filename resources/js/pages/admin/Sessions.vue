<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KIcon from '@/components/k/KIcon.vue';
import KTag from '@/components/k/KTag.vue';
import { confirmAction } from '@/lib/feedback';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';

type Session = {
    id: string;
    user: string;
    email: string;
    device: string;
    app: boolean;
    ip: string | null;
    last: string;
    active: boolean;
    mine: boolean;
};

defineProps<{ sessions: Session[] }>();

const can = computed(() => usePage().props.auth.can);

// The list keeps itself fresh while it is open.
let timer: number | undefined;
onMounted(() => {
    timer = window.setInterval(
        () => router.reload({ only: ['sessions'], showProgress: false }),
        30_000,
    );
});
onBeforeUnmount(() => window.clearInterval(timer));

async function end(s: Session): Promise<void> {
    const ok = await confirmAction({
        title: t('Session শেষ করবেন?'),
        body: t(
            '{name} {device}-এ sign out হয়ে যাবেন। আবার login করলে সেখানে চলবে।',
            { name: s.user, device: s.device },
        ),
        confirm: t('শেষ করুন'),
        danger: true,
    });

    if (ok) {
        router.delete(`/admin/sessions/${s.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        title="Session"
        :lead="
            t(
                'কে কোথায় login করা আছেন। একজন একসাথে এক জায়গাতেই থাকতে পারেন — অন্য কোথাও login করলে আগেরটা নিজে থেকে বন্ধ হয়ে যায়, যাতে একই entry দুই জায়গা থেকে না হয়।',
            )
        "
    />

    <KEmpty v-if="!sessions.length" :title="t('গত ৭ দিনে কেউ login করেননি')" />

    <ul v-else class="panel divide-y divide-line">
        <li
            v-for="s in sessions"
            :key="s.id"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 sm:px-6"
        >
            <span
                class="grid size-10 shrink-0 place-items-center rounded-full border border-line bg-surface-2 text-ink-2"
            >
                <KIcon :name="s.app ? 'bell' : 'desk'" :size="18" />
            </span>
            <div class="min-w-0 flex-1">
                <p
                    class="flex flex-wrap items-center gap-2 text-[14px] font-semibold"
                >
                    {{ s.user }}
                    <KTag v-if="s.mine" tone="brand">{{ t('এটা আপনি') }}</KTag>
                    <KTag v-if="s.active" tone="in">{{ t('এখন চালু') }}</KTag>
                </p>
                <p class="text-[12.5px] text-ink-3">
                    {{ s.device
                    }}<template v-if="s.ip">
                        · <span class="num">{{ s.ip }}</span></template
                    >
                    ·
                    <span class="whitespace-nowrap"
                        >{{ t('শেষ কাজ') }} {{ ago(s.last) }}</span
                    >
                </p>
            </div>
            <KButton
                v-if="!s.mine && can['users.edit']"
                size="sm"
                variant="plain"
                icon="out"
                @click="end(s)"
                >{{ t('Session শেষ করুন') }}</KButton
            >
        </li>
    </ul>
</template>
