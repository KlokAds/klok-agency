<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import { t } from '@/lib/i18n';

defineOptions({
    layout: {
        title: 'Client account খুলুন',
        description:
            'আপনার campaign, খরচ, result আর invoice এক জায়গায় দেখতে। আমরা আপনাকে যুক্ত করলেই সব দেখা যাবে।',
    }, // i18n
});

const form = useForm({
    name: '',
    company: '',
    phone: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post('/portal/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('Client account খুলুন')" />

    <form class="grid grid-cols-1 gap-4" @submit.prevent="submit">
        <KField :label="t('আপনার নাম')" :error="form.errors.name">
            <KInput
                v-model="form.name"
                :invalid="!!form.errors.name"
                required
            />
        </KField>
        <KField
            :label="t('প্রতিষ্ঠানের নাম')"
            optional
            :error="form.errors.company"
        >
            <KInput v-model="form.company" />
        </KField>
        <KField :label="t('Phone নম্বর')" :error="form.errors.phone">
            <KInput
                v-model="form.phone"
                type="tel"
                inputmode="tel"
                placeholder="01XXXXXXXXX"
                :invalid="!!form.errors.phone"
                required
            />
        </KField>
        <KField label="Email" :error="form.errors.email">
            <KInput
                v-model="form.email"
                type="email"
                :invalid="!!form.errors.email"
                required
            />
        </KField>
        <KField
            label="Password"
            :hint="t('কমপক্ষে ৮ অক্ষর — অক্ষর আর সংখ্যা মিলিয়ে')"
            :error="form.errors.password"
        >
            <KInput
                v-model="form.password"
                type="password"
                :invalid="!!form.errors.password"
                required
            />
        </KField>
        <KField :label="t('আবার password')">
            <KInput
                v-model="form.password_confirmation"
                type="password"
                required
            />
        </KField>
        <KButton
            type="submit"
            variant="primary"
            size="lg"
            block
            :loading="form.processing"
            class="mt-2"
            >{{ t('Account খুলুন') }}</KButton
        >
        <p class="text-center text-[13px] text-ink-3">
            {{ t('আগে থেকে account আছে?') }}
            <Link href="/login" class="font-semibold text-brand hover:underline"
                >Sign in</Link
            >
        </p>
    </form>
</template>
