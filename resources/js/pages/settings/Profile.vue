<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';

const user = usePage().props.auth.user;
const form = useForm({ name: user.name });
</script>

<template>
    <PageHeader eyebrow="আমার account" title="Profile" />

    <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <form class="panel flex flex-col gap-5 p-6" @submit.prevent="form.patch('/settings/profile', { preserveScroll: true })">
            <KField label="নাম" :error="form.errors.name">
                <KInput v-model="form.name" autocomplete="name" :invalid="!!form.errors.name" />
            </KField>
            <KField label="Email" hint="Email বদলাতে Owner-কে বলুন — এটাই sign-in নাম।">
                <div class="field-box bg-sunken! text-[14.5px] text-ink-2">{{ user.email }}</div>
            </KField>
            <div class="flex justify-end">
                <KButton type="submit" variant="primary" :loading="form.processing">সংরক্ষণ</KButton>
            </div>
        </form>

        <aside class="panel p-6">
            <p class="eyebrow">Role</p>
            <p class="mt-1 text-[18px] font-bold">{{ user.role ?? '—' }}</p>
            <p class="mt-3 text-[13px] text-ink-3">কোন পাতায় কী করতে পারবেন, তা Role ঠিক করে। বদলাতে Owner-এর সাথে কথা বলুন।</p>
        </aside>
    </div>
</template>
