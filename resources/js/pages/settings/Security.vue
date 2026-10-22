<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KCode from '@/components/k/KCode.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KTag from '@/components/k/KTag.vue';
import { confirmAction, toast } from '@/lib/feedback';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';

const props = defineProps<{ twoFactorEnabled?: boolean; requiresConfirmation?: boolean; passwordRules?: string }>();

const page = usePage();
const notice = computed(() => page.props.notice as string | null);
const mustChange = computed(() => page.props.auth.user.must_change_password);

const password = useForm({ current_password: '', password: '', password_confirmation: '' });

function savePassword(): void {
    password.put('/settings/password', {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => password.reset('password', 'password_confirmation'),
    });
}

// 2FA setup: enable → scan QR → confirm with a code → keep recovery codes.
const step = ref<'idle' | 'scan' | 'codes'>('idle');
const qr = ref<string | null>(null);
const secret = ref<string | null>(null);
const codes = ref<string[]>([]);
const confirm = useForm({ code: '' });
const busy = ref(false);

function enable(): void {
    busy.value = true;
    router.post('/user/two-factor-authentication', {}, {
        preserveScroll: true,
        onSuccess: async () => {
            const [{ svg }, { secretKey }] = await Promise.all([
                getJson<{ svg: string }>('/user/two-factor-qr-code'),
                getJson<{ secretKey: string }>('/user/two-factor-secret-key'),
            ]);
            qr.value = svg;
            secret.value = secretKey;
            step.value = 'scan';
        },
        onFinish: () => (busy.value = false),
    });
}

function confirmCode(): void {
    confirm.post('/user/confirmed-two-factor-authentication', {
        preserveScroll: true,
        errorBag: 'confirmTwoFactorAuthentication',
        onSuccess: async () => {
            codes.value = await getJson<string[]>('/user/two-factor-recovery-codes');
            step.value = 'codes';
            toast(t('2FA চালু হয়েছে।'));
        },
        onError: () => confirm.reset(),
    });
}

async function showCodes(): Promise<void> {
    codes.value = await getJson<string[]>('/user/two-factor-recovery-codes');
    step.value = 'codes';
}

function regenerate(): void {
    router.post('/user/two-factor-recovery-codes', {}, { preserveScroll: true, onSuccess: showCodes });
}

async function disable(): Promise<void> {
    const ok = await confirmAction({
        title: t('2FA বন্ধ করবেন?'),
        body: t('বন্ধ করলে শুধু password দিয়েই account-এ ঢোকা যাবে। Owner সবার জন্য 2FA বাধ্যতামূলক করলে আবার চালু করতে হবে।'),
        confirm: t('হ্যাঁ, বন্ধ করুন'),
        danger: true,
    });

    if (ok) {
        router.delete('/user/two-factor-authentication', { preserveScroll: true, onSuccess: () => (step.value = 'idle') });
    }
}

function copyCodes(): void {
    void navigator.clipboard?.writeText(codes.value.join('\n'));
    toast(t('Recovery code copy হয়েছে।'), 'info');
}

const codeError = computed(() => (page.props.errors as Record<string, Record<string, string>>)?.confirmTwoFactorAuthentication?.code ?? confirm.errors.code);
void props;
</script>

<template>
    <PageHeader :eyebrow="t('আমার account')" :title="t('Password ও 2FA')" :lead="t('আপনার account-এর তালা। এই খাতায় টাকার হিসাব থাকে, তাই দুটোই শক্ত রাখুন।')" />

    <div v-if="notice === 'password' || mustChange" class="mb-6 flex items-start gap-3 rounded-xl border border-wait/30 bg-wait-soft px-4 py-3.5 text-wait">
        <KIcon name="warn" class="mt-0.5" />
        <p class="text-[14px] font-medium">{{ t('প্রথমবার ঢুকেছেন বা password reset হয়েছে — কাজ শুরুর আগে নিজের একটা নতুন password দিন।') }}</p>
    </div>
    <div v-else-if="notice === 'two_factor'" class="mb-6 flex items-start gap-3 rounded-xl border border-wait/30 bg-wait-soft px-4 py-3.5 text-wait">
        <KIcon name="shield" class="mt-0.5" />
        <p class="text-[14px] font-medium">{{ t('Owner সবার জন্য 2FA বাধ্যতামূলক করেছেন। নিচে চালু করলেই খাতা খুলবে।') }}</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <!-- Password -->
        <form class="panel flex flex-col gap-5 p-6" @submit.prevent="savePassword">
            <div>
                <h2 class="text-[17px] font-bold">{{ t('Password বদলান') }}</h2>
                <p class="mt-1 text-[13px] text-ink-3">{{ t('বদলালে অন্য সব device থেকে নিজে থেকে sign out হবে।') }}</p>
            </div>
            <KField :label="t('এখনকার password')" :error="password.errors.current_password">
                <KInput v-model="password.current_password" type="password" autocomplete="current-password" :invalid="!!password.errors.current_password" />
            </KField>
            <KField :label="t('নতুন password')" :error="password.errors.password" :hint="t('কমপক্ষে ১০ অক্ষর — অক্ষর আর সংখ্যা মিলিয়ে')">
                <KInput v-model="password.password" type="password" autocomplete="new-password" :invalid="!!password.errors.password" />
            </KField>
            <KField :label="t('নতুন password আবার')" :error="password.errors.password_confirmation">
                <KInput v-model="password.password_confirmation" type="password" autocomplete="new-password" />
            </KField>
            <div class="flex justify-end">
                <KButton type="submit" variant="primary" :loading="password.processing">{{ t('Password বদলান') }}</KButton>
            </div>
        </form>

        <!-- 2FA -->
        <section class="panel flex flex-col gap-5 p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-[17px] font-bold">{{ t('দুই ধাপের যাচাই (2FA)') }}</h2>
                    <p class="mt-1 text-[13px] text-ink-3">{{ t('Password-এর পরে phone-এর authenticator app-এর কোড লাগবে।') }}</p>
                </div>
                <KTag :tone="twoFactorEnabled ? 'in' : 'wait'">{{ twoFactorEnabled ? t('চালু') : t('বন্ধ') }}</KTag>
            </div>

            <template v-if="step === 'scan'">
                <ol class="flex flex-col gap-4 text-[13.5px]">
                    <li><span class="num mr-2 font-semibold text-brand-ink">1</span>{{ t('Google Authenticator / Microsoft Authenticator খুলে এই QR scan করুন।') }}</li>
                </ol>
                <div class="flex flex-wrap items-center gap-5">
                    <!-- Fortify returns a trusted SVG for this user's secret -->
                    <div class="rounded-xl border border-line bg-white p-3 [&_svg]:size-40" v-html="qr" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[12px] text-ink-3">{{ t('Scan না হলে এই key হাতে লিখুন') }}</p>
                        <p class="num mt-1 rounded-lg bg-sunken px-3 py-2 text-[13px] break-all select-all">{{ secret }}</p>
                    </div>
                </div>
                <KField :label="t('2. App-এ যে কোড দেখাচ্ছে')" :error="codeError">
                    <KCode v-model="confirm.code" :invalid="!!codeError" />
                </KField>
                <div class="flex justify-end gap-2">
                    <KButton variant="primary" :loading="confirm.processing" :disabled="confirm.code.length < 6" @click="confirmCode">{{ t('চালু করুন') }}</KButton>
                </div>
            </template>

            <template v-else-if="step === 'codes'">
                <div class="rounded-xl border border-dashed border-line-2 bg-surface-2 p-4">
                    <p class="text-[13px] font-semibold">{{ t('Recovery code — phone হারালে এগুলো দিয়ে ঢুকবেন') }}</p>
                    <p class="mt-0.5 text-[12px] text-ink-3">{{ t('নিরাপদ জায়গায় লিখে রাখুন। প্রতিটা একবারই কাজ করে।') }}</p>
                    <div class="num mt-3 grid grid-cols-2 gap-1.5 text-[13px]">
                        <span v-for="c in codes" :key="c" class="rounded-md bg-surface px-2 py-1.5">{{ c }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <KButton variant="plain" size="sm" @click="copyCodes">Copy</KButton>
                    <KButton variant="plain" size="sm" @click="regenerate">{{ t('নতুন code বানান') }}</KButton>
                    <KButton variant="primary" size="sm" @click="step = 'idle'">{{ t('রেখে দিয়েছি') }}</KButton>
                </div>
            </template>

            <template v-else>
                <ul class="flex flex-col gap-2 text-[13.5px] text-ink-2">
                    <li class="flex gap-2"><KIcon name="check" :size="16" class="mt-0.5 text-in" /> {{ t('Password চুরি হলেও কেউ ঢুকতে পারবে না') }}</li>
                    <li class="flex gap-2"><KIcon name="check" :size="16" class="mt-0.5 text-in" /> {{ t('প্রতি ৩০ সেকেন্ডে নতুন কোড') }}</li>
                </ul>
                <div class="mt-auto flex flex-wrap justify-end gap-2">
                    <template v-if="twoFactorEnabled">
                        <KButton variant="plain" size="sm" @click="showCodes">{{ t('Recovery code দেখুন') }}</KButton>
                        <KButton variant="danger" size="sm" @click="disable">{{ t('বন্ধ করুন') }}</KButton>
                    </template>
                    <KButton v-else variant="primary" icon="shield" :loading="busy" @click="enable">{{ t('2FA চালু করুন') }}</KButton>
                </div>
            </template>
        </section>
    </div>
</template>
