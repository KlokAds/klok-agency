<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import KButton from '@/components/k/KButton.vue';
import KCheck from '@/components/k/KCheck.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import { t } from '@/lib/i18n';

defineOptions({
    layout: {
        title: 'আবার স্বাগতম',
        description: 'খাতা খুলতে আপনার email আর password দিন।',
    }, // i18n
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    app?: string | null;
}>();

// Inside the app itself there is nothing to download.
const inApp = /KlokFinanceApp/.test(navigator.userAgent);

// The Android app stays signed in (it locks itself with a fingerprint or PIN).
const form = useForm({
    email: '',
    password: '',
    remember: /KlokFinanceApp/.test(navigator.userAgent),
});

function submit(): void {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />

    <p
        v-if="status"
        class="mb-6 rounded-xl border border-wait/30 bg-wait-soft px-4 py-3 text-[13.5px] font-medium text-wait"
    >
        {{ status }}
    </p>

    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <KField label="Email" for="email" :error="form.errors.email">
            <KInput
                id="email"
                v-model="form.email"
                type="email"
                autocomplete="username"
                placeholder="you@klokads.com"
                :invalid="!!form.errors.email"
                autofocus
                required
            />
        </KField>

        <KField label="Password" for="password" :error="form.errors.password">
            <KInput
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="current-password"
                placeholder="••••••••"
                :invalid="!!form.errors.password"
                required
            />
        </KField>

        <div
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2"
        >
            <KCheck v-model="form.remember" :label="t('এই device মনে রাখো')" />
            <Link
                v-if="canResetPassword"
                href="/forgot-password"
                class="-my-2 py-2 text-[13px] font-semibold whitespace-nowrap text-brand-ink hover:underline"
                >{{ t('Password ভুলে গেছেন?') }}</Link
            >
        </div>

        <KButton
            type="submit"
            variant="primary"
            size="lg"
            block
            :loading="form.processing"
            class="mt-2"
            >Sign in</KButton
        >
    </form>

    <p class="mt-5 text-center text-[13.5px] text-ink-3">
        {{ t('আমাদের client? নিজের campaign আর invoice দেখতে') }}
        <Link
            href="/portal/register"
            class="font-semibold text-brand hover:underline"
            >{{ t('account খুলুন') }}</Link
        >
    </p>

    <a
        v-if="app && !inApp"
        href="/app/get"
        class="mt-6 flex items-center gap-3 rounded-xl border border-line px-4 py-3 transition-colors hover:border-brand hover:bg-sunken"
    >
        <svg
            viewBox="0 0 24 24"
            class="size-6 shrink-0 text-brand"
            fill="currentColor"
            aria-hidden="true"
        >
            <path
                d="M17.6 9.48l1.84-3.18a.38.38 0 0 0-.66-.38l-1.86 3.22a11.4 11.4 0 0 0-9.84 0L5.22 5.92a.38.38 0 0 0-.66.38L6.4 9.48A10.8 10.8 0 0 0 1 18h22a10.8 10.8 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5zm10 0a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"
            />
        </svg>
        <span class="min-w-0 flex-1">
            <span class="block text-[14px] font-semibold">{{
                t('Android app নামান')
            }}</span>
            <span class="block text-[12.5px] text-ink-3"
                >{{ t('শব্দসহ notification, আঙুলের ছাপে খোলা') }} · v{{
                    app
                }}</span
            >
        </span>
    </a>
</template>
