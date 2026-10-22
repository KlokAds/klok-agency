<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';

defineOptions({
    layout: { title: 'Password ভুলে গেছেন', description: 'Account-এর email দিন — reset link পাঠানো হবে।' },
});

defineProps<{ status?: string }>();

const form = useForm({ email: '' });
</script>

<template>
    <Head title="Password reset" />

    <p v-if="status" class="mb-6 rounded-xl bg-in-soft px-4 py-3 text-[13.5px] font-medium text-in">{{ status }}</p>

    <form class="flex flex-col gap-5" @submit.prevent="form.post('/forgot-password')">
        <KField label="Email" for="email" :error="form.errors.email">
            <KInput id="email" v-model="form.email" type="email" autocomplete="username" :invalid="!!form.errors.email" autofocus required />
        </KField>
        <KButton type="submit" variant="ink" size="lg" block :loading="form.processing">Reset link পাঠান</KButton>
        <Link href="/login" class="text-center text-[13px] font-semibold text-ink-2 hover:text-ink">← Sign in-এ ফিরে যান</Link>
    </form>
</template>
