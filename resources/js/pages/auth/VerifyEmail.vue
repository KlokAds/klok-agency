<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import { t } from '@/lib/i18n';

// A portal sign-up waits here until it opens the link sent to its email: only then can
// Klok Ads link the account to their business (team accounts never see this page).
defineOptions({
    layout: {
        title: 'Email confirm করুন',
        description:
            'আপনার email-এ একটা link গেছে — ওটা খুললেই account চালু হবে।',
    }, // i18n
});

defineProps<{ status?: string; email?: string | null }>();

const form = useForm({});

function resend(): void {
    form.post('/email/verification-notification', { preserveScroll: true });
}

function signOut(): void {
    router.post('/logout');
}
</script>

<template>
    <Head :title="t('Email confirm করুন')" />

    <p
        v-if="status === 'verification-link-sent'"
        class="mb-6 rounded-xl bg-in-soft px-4 py-3 text-[14.5px] font-medium text-in"
        role="status"
    >
        {{ t('নতুন link পাঠানো হয়েছে — inbox আর spam দুটোই দেখুন।') }}
    </p>

    <div class="flex flex-col gap-5">
        <div
            class="flex items-start gap-3 rounded-xl border border-line-2 bg-surface-2 px-4 py-4"
        >
            <span
                class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-brand-soft text-brand-ink"
                ><KIcon name="mail" :size="18"
            /></span>
            <div class="min-w-0 text-[14.5px] leading-relaxed text-ink-2">
                <p v-if="email" class="font-semibold break-all text-ink">
                    {{ email }}
                </p>
                <p :class="email ? 'mt-1' : ''">
                    {{
                        t(
                            'এই email-এ পাঠানো link-টা খুলুন। তারপর Klok Ads আপনার account-টা আপনার ব্যবসার সাথে যুক্ত করতে পারবে।',
                        )
                    }}
                </p>
            </div>
        </div>

        <KButton
            variant="primary"
            size="lg"
            icon="mail"
            block
            :loading="form.processing"
            @click="resend"
            >{{ t('Link আবার পাঠান') }}</KButton
        >

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <KButton href="/settings/profile" variant="soft" block>{{
                t('Email বদলান')
            }}</KButton>
            <KButton variant="quiet" icon="out" block @click="signOut">{{
                t('Sign out')
            }}</KButton>
        </div>

        <Link
            href="/portal"
            class="inline-flex min-h-10 items-center justify-center gap-1.5 text-[14.5px] font-semibold text-ink-2 hover:text-ink"
            >{{ t('Confirm করেছি — এগিয়ে যান') }}</Link
        >
    </div>
</template>
