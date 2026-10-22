<script setup lang="ts">
import { Head, setLayoutProps, useForm } from '@inertiajs/vue3';
import { ref, watch, watchEffect } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KCode from '@/components/k/KCode.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import { t } from '@/lib/i18n';

const recovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

watchEffect(() =>
    setLayoutProps(
        recovery.value
            ? { title: 'Recovery code', description: t('2FA চালুর সময় যে recovery code রেখেছিলেন, তার একটা দিন।') }
            : { title: t('দুই ধাপের যাচাই'), description: t('Authenticator app-এ যে ৬ সংখ্যার কোড দেখাচ্ছে, সেটা দিন।') },
    ),
);

// Submit on its own once all six digits are in.
watch(
    () => form.code,
    (code) => {
        if (!recovery.value && code.length === 6 && !form.processing) {
            submit();
        }
    },
);

function submit(): void {
    form.transform((d) => (recovery.value ? { recovery_code: d.recovery_code } : { code: d.code })).post('/two-factor-challenge', {
        onError: () => form.reset('code'),
    });
}

function toggle(): void {
    recovery.value = !recovery.value;
    form.reset();
    form.clearErrors();
}
</script>

<template>
    <Head title="2FA" />

    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <KField v-if="!recovery" :error="form.errors.code">
            <KCode v-model="form.code" :invalid="!!form.errors.code" />
        </KField>
        <KField v-else label="Recovery code" :error="form.errors.recovery_code">
            <KInput v-model="form.recovery_code" autocomplete="one-time-code" placeholder="xxxxx-xxxxx" autofocus />
        </KField>

        <KButton type="submit" variant="ink" size="lg" block :loading="form.processing">{{ t('যাচাই করুন') }}</KButton>
        <button type="button" class="text-[13px] font-semibold text-ink-2 hover:text-ink" @click="toggle">
            {{ recovery ? t('Authenticator কোড দিয়ে ঢুকব') : t('Phone কাছে নেই? Recovery code দিন') }}
        </button>
    </form>
</template>
