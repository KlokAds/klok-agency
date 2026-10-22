<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import { t } from '@/lib/i18n';

defineOptions({ layout: { title: 'Password নিশ্চিত করুন', description: 'নিরাপত্তার জন্য এই পাতায় ঢোকার আগে আবার password দিন।' } }); // i18n

const form = useForm({ password: '' });
</script>

<template>
    <Head :title="t('Password নিশ্চিত করুন')" />

    <form class="flex flex-col gap-5" @submit.prevent="form.post('/user/confirm-password', { onFinish: () => form.reset() })">
        <KField label="Password" :error="form.errors.password">
            <KInput v-model="form.password" type="password" autocomplete="current-password" :invalid="!!form.errors.password" autofocus />
        </KField>
        <KButton type="submit" variant="ink" size="lg" block :loading="form.processing">{{ t('নিশ্চিত করুন') }}</KButton>
    </form>
</template>
