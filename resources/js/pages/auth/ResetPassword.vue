<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import { t } from '@/lib/i18n';

defineOptions({
    layout: { title: 'নতুন password', description: 'শক্ত একটা password দিন।' },
}); // i18n

const props = defineProps<{
    token: string;
    email: string;
    passwordRules?: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <Head :title="t('নতুন password')" />

    <form
        class="flex flex-col gap-5"
        @submit.prevent="
            form.post('/reset-password', {
                onFinish: () => form.reset('password', 'password_confirmation'),
            })
        "
    >
        <KField label="Email" :error="form.errors.email">
            <KInput v-model="form.email" type="email" autocomplete="username" />
        </KField>
        <KField
            :label="t('নতুন password')"
            :error="form.errors.password"
            :hint="t('কমপক্ষে ৮ অক্ষর, অক্ষর আর সংখ্যা মিলিয়ে')"
        >
            <KInput
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                :invalid="!!form.errors.password"
                autofocus
            />
        </KField>
        <KField
            :label="t('আবার লিখুন')"
            :error="form.errors.password_confirmation"
        >
            <KInput
                v-model="form.password_confirmation"
                type="password"
                autocomplete="new-password"
            />
        </KField>
        <KButton
            type="submit"
            variant="primary"
            size="lg"
            block
            :loading="form.processing"
            >{{ t('Password সেট করুন') }}</KButton
        >
    </form>
</template>
