<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { confirmAction, toast } from '@/lib/feedback';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type Settings = Record<string, string | boolean>;

// Settings → Payment: who the invoices are from, where clients send money,
// and the phone that forwards the wallet's SMS so payments confirm themselves.
const props = defineProps<{
    settings: Settings;
    images: { logo: string | null; qr: string | null };
    forwarder: {
        connected: boolean;
        last_seen: string | null;
        url: string | null;
        unmatched: number;
    };
    options: { types: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);
const editable = computed(() => !!can.value['settings.edit']);

// Text fields and switches, kept apart so each input gets the right type.
const values = reactive<Record<string, string>>({});
const flags = reactive<Record<string, boolean>>({});

for (const [key, value] of Object.entries(props.settings)) {
    if (typeof value === 'boolean') {
        flags[key] = value;
    } else {
        values[key] = value;
    }
}

const files: { logo: File | null; qr: File | null } = { logo: null, qr: null };
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const err = (key: string) => errors.value[key];

function pick(field: 'logo' | 'qr', event: Event): void {
    files[field] = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function save(): void {
    const data: Record<string, string | File> = { ...values };

    for (const [key, value] of Object.entries(flags)) {
        data[key] = value ? '1' : '0';
    }

    for (const [key, file] of Object.entries(files)) {
        if (file) {
            data[key] = file;
        }
    }

    router.post('/admin/payment', data, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onError: (e) => (errors.value = e),
        onSuccess: () => {
            errors.value = {};
            files.logo = null;
            files.qr = null;
        },
    });
}

function removeImage(kind: 'logo' | 'qr'): void {
    router.delete(`/admin/payment/image/${kind}`, { preserveScroll: true });
}

async function newToken(): Promise<void> {
    if (
        props.forwarder.connected &&
        !(await confirmAction({
            title: t('নতুন URL বানাবেন?'),
            body: t(
                'আগের URL সঙ্গে সঙ্গে বন্ধ হবে — phone-এ নতুনটা বসাতে হবে।',
            ),
        }))
    ) {
        return;
    }

    router.post('/admin/payment/token', {}, { preserveScroll: true });
}

async function disconnect(): Promise<void> {
    if (
        await confirmAction({
            title: t('SMS forwarder বন্ধ করবেন?'),
            body: t('Wallet-এর পেমেন্ট আর নিজে থেকে মিলবে না।'),
            danger: true,
        })
    ) {
        router.post('/admin/payment/disconnect', {}, { preserveScroll: true });
    }
}

function copy(value: string): void {
    void navigator.clipboard?.writeText(value);
    toast(t('Copy হয়েছে।'), 'info');
}

const wallets = [
    { key: 'bkash', label: 'bKash', typed: true },
    { key: 'nagad', label: 'Nagad', typed: true },
    { key: 'rocket', label: 'Rocket', typed: false },
];
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        :title="t('Payment ও invoice')"
        :lead="
            t(
                'Invoice-এ আপনার কোন তথ্য যাবে, client কোথায় টাকা পাঠাবেন, আর wallet-এর SMS দেখে পেমেন্ট নিজে থেকে মেলানোর ব্যবস্থা।',
            )
        "
    />

    <form class="max-w-5xl" @submit.prevent="save">
        <SettingsSection
            :title="t('Invoice-এ আপনার তথ্য')"
            :description="
                t('Invoice-এর মাথায় এই নাম, ঠিকানা আর logo ছাপা হয়।')
            "
            icon="desk"
        >
            <KField :label="t('নাম')" :error="err('pay_business_name')">
                <KInput v-model="values['pay_business_name']" />
            </KField>
            <KField
                :label="t('ঠিকানা')"
                optional
                :error="err('pay_business_address')"
            >
                <KTextarea v-model="values['pay_business_address']" :rows="2" />
            </KField>
            <div class="grid gap-5 sm:grid-cols-2">
                <KField
                    :label="t('ফোন')"
                    optional
                    :error="err('pay_business_phone')"
                >
                    <KInput v-model="values['pay_business_phone']" type="tel" />
                </KField>
                <KField
                    label="Email"
                    optional
                    :error="err('pay_business_email')"
                >
                    <KInput
                        v-model="values['pay_business_email']"
                        type="email"
                    />
                </KField>
            </div>
            <KField
                label="Logo"
                optional
                :hint="t('PNG/JPG, ১ MB পর্যন্ত')"
                :error="err('logo')"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <img
                        v-if="images.logo"
                        :src="images.logo"
                        alt="Logo"
                        class="h-12 w-auto rounded border border-line bg-white p-1"
                    />
                    <input
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="max-w-full min-w-0 text-[12.5px]"
                        @change="pick('logo', $event)"
                    />
                    <KButton
                        v-if="images.logo && editable"
                        size="sm"
                        variant="quiet"
                        @click="removeImage('logo')"
                        >{{ t('সরান') }}</KButton
                    >
                </div>
            </KField>
            <template v-if="editable" #footer>
                <KButton variant="primary" :loading="saving" @click="save">{{
                    t('সংরক্ষণ')
                }}</KButton>
            </template>
        </SettingsSection>

        <SettingsSection
            :title="t('Mobile wallet')"
            :description="
                t(
                    'Client যে নম্বরে টাকা পাঠাবেন — চালু থাকলে invoice-এ দেখায়।',
                )
            "
            icon="wallet"
        >
            <div
                v-for="w in wallets"
                :key="w.key"
                class="border-t border-line pt-5 first:border-0 first:pt-0"
            >
                <KSwitch v-model="flags[`pay_${w.key}_on`]" :label="w.label" />
                <div
                    v-if="flags[`pay_${w.key}_on`]"
                    class="mt-4 grid grid-cols-1 gap-3"
                >
                    <KField
                        :label="t('নম্বর')"
                        :error="err(`pay_${w.key}_number`)"
                    >
                        <KInput
                            v-model="values[`pay_${w.key}_number`]"
                            type="tel"
                            inputmode="tel"
                            placeholder="01XXXXXXXXX"
                        />
                    </KField>
                    <KSegment
                        v-if="w.typed"
                        v-model="values[`pay_${w.key}_type`]"
                        :options="options.types"
                        size="sm"
                    />
                </div>
            </div>
            <template v-if="editable" #footer>
                <KButton variant="primary" :loading="saving" @click="save">{{
                    t('সংরক্ষণ')
                }}</KButton>
            </template>
        </SettingsSection>

        <SettingsSection
            title="Bangla QR"
            :description="
                t('QR-এর ছবি দিলে invoice-এ দেখায় — client scan করে টাকা দেন।')
            "
            icon="scale"
        >
            <KSwitch v-model="flags['pay_qr_on']" label="Bangla QR" />
            <template v-if="flags['pay_qr_on']">
                <div class="flex flex-wrap items-center gap-3">
                    <img
                        v-if="images.qr"
                        :src="images.qr"
                        alt="Bangla QR"
                        class="size-24 rounded-lg border border-line bg-white p-1"
                    />
                    <input
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="max-w-full min-w-0 text-[12.5px]"
                        @change="pick('qr', $event)"
                    />
                    <KButton
                        v-if="images.qr && editable"
                        size="sm"
                        variant="quiet"
                        @click="removeImage('qr')"
                        >{{ t('সরান') }}</KButton
                    >
                </div>
                <p v-if="err('qr')" class="-mt-3 text-[12px] text-out">
                    {{ err('qr') }}
                </p>
                <KField :label="t('Merchant-এর নাম')" optional>
                    <KInput v-model="values['pay_qr_merchant']" />
                </KField>
            </template>
            <template v-if="editable" #footer>
                <KButton variant="primary" :loading="saving" @click="save">{{
                    t('সংরক্ষণ')
                }}</KButton>
            </template>
        </SettingsSection>

        <SettingsSection
            title="Bank / NPSB"
            :description="
                t('যে account-এ client bank transfer বা NPSB-তে টাকা পাঠাবেন।')
            "
            icon="dollar"
        >
            <KSwitch v-model="flags['pay_bank_on']" label="Bank / NPSB" />
            <div v-if="flags['pay_bank_on']" class="grid gap-5 sm:grid-cols-2">
                <KField :label="t('ব্যাংক')" :error="err('pay_bank_name')">
                    <KInput v-model="values['pay_bank_name']" />
                </KField>
                <KField :label="t('শাখা')" optional>
                    <KInput v-model="values['pay_bank_branch']" />
                </KField>
                <KField
                    :label="t('Account-এর নাম')"
                    :error="err('pay_bank_account_name')"
                >
                    <KInput v-model="values['pay_bank_account_name']" />
                </KField>
                <KField
                    :label="t('Account নম্বর')"
                    :error="err('pay_bank_account_number')"
                >
                    <KInput v-model="values['pay_bank_account_number']" />
                </KField>
                <KField label="Routing" optional>
                    <KInput v-model="values['pay_bank_routing']" />
                </KField>
                <KField
                    :label="t('ব্যাংকের SMS sender')"
                    optional
                    :hint="
                        t(
                            'যেমন DBBL, BRAC BANK — কমা দিয়ে। শুধু এই নাম থেকে আসা credit SMS বিশ্বাস করা হবে।',
                        )
                    "
                >
                    <KInput v-model="values['pay_bank_sms_senders']" />
                </KField>
            </div>
            <template v-if="editable" #footer>
                <KButton variant="primary" :loading="saving" @click="save">{{
                    t('সংরক্ষণ')
                }}</KButton>
            </template>
        </SettingsSection>

        <SettingsSection
            :title="t('Rate')"
            :description="
                t('Client-এর dollar rate-এর উপর commission, আর QR-এর fee।')
            "
            icon="chart"
        >
            <div class="grid gap-5 sm:grid-cols-2">
                <KField
                    :label="t('Commission (%)')"
                    :hint="
                        t(
                            'Client-এর rate = চলতি FX batch-এর rate + এই %। প্রতি invoice-এ বদলানো যায়।',
                        )
                    "
                    :error="err('commission_percent')"
                >
                    <KInput
                        v-model="values['commission_percent']"
                        inputmode="decimal"
                    >
                        <template #trail
                            ><span class="text-[13px] font-semibold text-ink-3"
                                >%</span
                            ></template
                        >
                    </KInput>
                </KField>
                <KField
                    :label="t('Bangla QR fee (%)')"
                    :hint="
                        t(
                            'QR-এ টাকা এলে এই fee ব্যাংক থেকে Bank charge খরচ হিসেবে লেখা হয় — client-এর কাছ থেকে নেওয়া হয় না।',
                        )
                    "
                    :error="err('qr_fee_percent')"
                >
                    <KInput
                        v-model="values['qr_fee_percent']"
                        inputmode="decimal"
                    >
                        <template #trail
                            ><span class="text-[13px] font-semibold text-ink-3"
                                >%</span
                            ></template
                        >
                    </KInput>
                </KField>
            </div>
            <template v-if="editable" #footer>
                <KButton variant="primary" :loading="saving" @click="save">{{
                    t('সংরক্ষণ')
                }}</KButton>
            </template>
        </SettingsSection>

        <SettingsSection
            :title="t('SMS forwarder (নিজে থেকে মেলানো)')"
            :description="
                t(
                    'Wallet-এ টাকা এলে তার SMS দেখে invoice-এর পেমেন্ট নিজে থেকে মিলে যায়।',
                )
            "
            icon="signal"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-[13.5px] font-medium">{{ t('অবস্থা') }}</span>
                <KTag :tone="forwarder.connected ? 'in' : 'neutral'">{{
                    forwarder.connected ? t('চালু') : t('বন্ধ')
                }}</KTag>
            </div>
            <p class="text-[13px] leading-relaxed text-ink-2">
                {{
                    t(
                        'যে Android phone-এ bKash/Nagad-এর SIM আছে, তাতে একটা SMS forwarder app বসিয়ে নিচের URL-এ POST করতে দিন (from আর text field)। Klok Ads website-এ যে phone চলে, সেটাই এই URL-এও পাঠাতে পারে।',
                    )
                }}
            </p>
            <div
                v-if="forwarder.url"
                class="rounded-lg border border-wait/40 bg-wait-soft p-4"
            >
                <p class="text-[12.5px] font-semibold text-wait">
                    {{
                        t(
                            'এই URL শুধু এখন একবার দেখানো হচ্ছে — এখনই copy করে phone-এ বসান।',
                        )
                    }}
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <code
                        class="num min-w-0 flex-1 rounded-lg bg-surface px-3 py-2 text-[12px] break-all"
                        >{{ forwarder.url }}</code
                    >
                    <KButton size="sm" @click="copy(forwarder.url)"
                        >Copy</KButton
                    >
                </div>
            </div>
            <div
                class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-line pt-4 text-[12.5px] text-ink-3"
            >
                <span
                    >{{ t('শেষ সাড়া') }}:
                    {{
                        forwarder.last_seen ? ago(forwarder.last_seen) : '—'
                    }}</span
                >
                <span v-if="forwarder.unmatched"
                    >·
                    {{
                        t('{n}টা SMS মেলেনি', { n: forwarder.unmatched })
                    }}</span
                >
            </div>
            <template v-if="editable" #footer>
                <KButton
                    v-if="forwarder.connected"
                    variant="quiet"
                    @click="disconnect"
                    >{{ t('বন্ধ করুন') }}</KButton
                >
                <KButton @click="newToken">{{
                    forwarder.connected ? t('নতুন URL বানান') : t('URL বানান')
                }}</KButton>
            </template>
        </SettingsSection>
    </form>
</template>
