<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import KButton from '@/components/k/KButton.vue';
import KCheck from '@/components/k/KCheck.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';

defineOptions({
    layout: { title: 'আবার স্বাগতম', description: 'খাতা খুলতে আপনার email আর password দিন।' },
});

defineProps<{ status?: string; canResetPassword: boolean }>();

const form = useForm({ email: '', password: '', remember: false });

function submit(): void {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />

    <p v-if="status" class="mb-6 rounded-xl border border-wait/30 bg-wait-soft px-4 py-3 text-[13.5px] font-medium text-wait">{{ status }}</p>

    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <KField label="Email" for="email" :error="form.errors.email">
            <KInput id="email" v-model="form.email" type="email" autocomplete="username" placeholder="you@klokads.com" :invalid="!!form.errors.email" autofocus required />
        </KField>

        <KField label="Password" for="password" :error="form.errors.password">
            <KInput id="password" v-model="form.password" type="password" autocomplete="current-password" placeholder="••••••••" :invalid="!!form.errors.password" required />
        </KField>

        <div class="flex items-center justify-between gap-4">
            <KCheck v-model="form.remember" label="এই device মনে রাখো" />
            <Link v-if="canResetPassword" href="/forgot-password" class="text-[13px] font-semibold text-brand-ink hover:underline">Password ভুলে গেছেন?</Link>
        </div>

        <KButton type="submit" variant="ink" size="lg" block :loading="form.processing" class="mt-2">Sign in</KButton>
    </form>
</template>
