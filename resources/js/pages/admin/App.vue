<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { confirmAction } from '@/lib/feedback';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';

type Device = {
    id: number;
    user: string | null;
    device: string | null;
    version: number;
    sms: boolean;
    failures: number;
    last_seen_at: string | null;
    last_sent_at: string | null;
};

const props = defineProps<{
    firebase: { project: string; email: string } | null;
    cardLow: string;
    releases: {
        flavor: 'plain' | 'sms';
        version: string | null;
        at: string | null;
        url: string | null;
    }[];
    devices: Device[];
    smsLastSeen: string | null;
}>();

const can = computed(() => usePage().props.auth.can);
const editable = computed(() => !!can.value['settings.edit']);

const keyForm = useForm({ key: '' });
const lowForm = useForm({ card_low_usd: props.cardLow });

function saveKey(): void {
    keyForm.put('/admin/app/firebase', {
        preserveScroll: true,
        onSuccess: () => keyForm.reset(),
    });
}

function test(urgent: boolean): void {
    router.post('/admin/app/test', { urgent }, { preserveScroll: true });
}

async function remove(device: Device): Promise<void> {
    const ok = await confirmAction({
        title: t('Phone সরাবেন?'),
        body: device.sms
            ? t(
                  'এই phone আর notification পাবে না, আর payment SMS forward করাও বন্ধ হবে।',
              )
            : t(
                  'এই phone আর notification পাবে না। App খুলে আবার login করলে ফিরে আসবে।',
              ),
        confirm: t('সরান'),
        danger: true,
    });

    if (ok) {
        router.delete(`/admin/app/devices/${device.id}`, {
            preserveScroll: true,
        });
    }
}

/** "Mozilla/5.0 (Linux; Android 14; SM-A155F …)" → "Android 14 · SM-A155F". */
function phone(agent: string | null): string {
    const match = agent?.match(/Android ([\d.]+);\s*([^;)]+)/);

    return match
        ? `Android ${match[1]} · ${match[2].replace(/ Build.*/, '')}`
        : t('Phone');
}
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        :title="t('Phone app')"
        :lead="
            t(
                'Klok Finance-এর Android app: শব্দসহ notification, payment SMS forward, আর app-এর নতুন version।',
            )
        "
    />

    <div class="max-w-5xl">
        <SettingsSection
            :title="t('Notification (Firebase)')"
            :description="
                t(
                    'Firebase → Project settings → Service accounts → Generate new private key। নামানো JSON file-এর পুরোটা এখানে paste করুন। Key এনক্রিপ্ট করে রাখা হয়।',
                )
            "
            icon="bell"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-[13.5px] font-medium">{{ t('অবস্থা') }}</span>
                <KTag :tone="firebase ? 'in' : 'neutral'">{{
                    firebase ? firebase.project : t('দেওয়া নেই')
                }}</KTag>
            </div>
            <form v-if="editable" @submit.prevent="saveKey">
                <KField
                    :label="t('Service account JSON')"
                    :error="keyForm.errors.key"
                >
                    <KTextarea
                        v-model="keyForm.key"
                        :rows="4"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="
                            firebase
                                ? t('নতুন key দিলে আগেরটা বদলাবে')
                                : '{ &quot;type&quot;: &quot;service_account&quot;, … }'
                        "
                    />
                </KField>
            </form>
            <template #footer>
                <KButton :disabled="!firebase" @click="test(false)">{{
                    t('Test: সাধারণ')
                }}</KButton>
                <KButton :disabled="!firebase" @click="test(true)">{{
                    t('Test: জরুরি')
                }}</KButton>
                <KButton
                    v-if="editable"
                    variant="primary"
                    :loading="keyForm.processing"
                    :disabled="!keyForm.key.trim()"
                    @click="saveKey"
                    >{{ t('রাখুন') }}</KButton
                >
            </template>
        </SettingsSection>

        <SettingsSection
            :title="t('যে phone-গুলোতে app আছে')"
            :description="
                t(
                    'যে login করে, phone তার notification পায়। Payment SMS শুধু Owner-এর phone থেকে আসে — app-এর মেনু থেকে চালু করতে হয়।',
                )
            "
            icon="user"
        >
            <ul
                v-if="devices.length"
                class="-my-2 flex flex-col divide-y divide-line"
            >
                <li
                    v-for="d in devices"
                    :key="d.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-[14px] font-medium">
                            {{ d.user ?? '—' }}
                            <KTag v-if="d.sms" tone="in" class="ml-1">{{
                                t('SMS forward করে')
                            }}</KTag>
                            <KTag
                                v-if="d.failures > 2"
                                tone="out"
                                class="ml-1"
                                >{{ t('পৌঁছাচ্ছে না') }}</KTag
                            >
                        </p>
                        <p class="text-[12.5px] text-ink-3">
                            {{ phone(d.device) }} · v{{ d.version }}
                            <template v-if="d.last_seen_at">
                                · {{ t('শেষ খোলা') }}
                                {{ ago(d.last_seen_at) }}</template
                            >
                        </p>
                    </div>
                    <KButton
                        v-if="editable"
                        variant="quiet"
                        size="sm"
                        @click="remove(d)"
                        >{{ t('সরান') }}</KButton
                    >
                </li>
            </ul>
            <p v-else class="text-[13.5px] text-ink-3">
                {{ t('এখনো কোনো phone-এ app নেই।') }}
            </p>
            <p v-if="smsLastSeen" class="text-[12.5px] text-ink-3">
                {{ t('শেষ SMS এসেছে') }} {{ ago(smsLastSeen) }}
            </p>
        </SettingsSection>

        <SettingsSection
            :title="t('Card-এ dollar কম')"
            :description="
                t(
                    'Meta আর Google যা কাটবে তার পরে card-এ এর চেয়ে কম থাকলে দিনে একবার জরুরি notification যাবে।',
                )
            "
            icon="wallet"
        >
            <form
                @submit.prevent="
                    lowForm.put('/admin/app/card-low', { preserveScroll: true })
                "
            >
                <KField
                    :label="t('এর নিচে নামলে জানাবে (USD)')"
                    :error="lowForm.errors.card_low_usd"
                >
                    <KInput
                        v-model="lowForm.card_low_usd"
                        inputmode="decimal"
                        class="max-w-[160px]"
                        :disabled="!editable"
                    />
                </KField>
            </form>
            <template v-if="editable" #footer>
                <KButton
                    variant="primary"
                    :loading="lowForm.processing"
                    @click="
                        lowForm.put('/admin/app/card-low', {
                            preserveScroll: true,
                        })
                    "
                    >{{ t('সংরক্ষণ') }}</KButton
                >
            </template>
        </SettingsSection>

        <SettingsSection
            :title="t('App-এর version')"
            :description="
                t(
                    'App-এর ভেতরের পাতা site থেকে আসে, তাই site বদলালে app-ও সাথে সাথে বদলায়। শুধু phone-এর অংশ বদলালে নতুন version আসে — app নিজেই জানায়।',
                )
            "
            icon="signal"
        >
            <div
                v-for="r in releases"
                :key="r.flavor"
                class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
            >
                <div class="min-w-0">
                    <p class="text-[13.5px] font-medium">
                        {{
                            r.flavor === 'sms'
                                ? t('SMS-সহ app (শুধু Owner-এর phone)')
                                : t('সাধারণ app (সবার জন্য)')
                        }}
                    </p>
                    <p class="num text-[12.5px] text-ink-3">
                        <template v-if="r.version">
                            v{{ r.version }}
                            <template v-if="r.at"> · {{ ago(r.at) }}</template>
                        </template>
                        <template v-else>—</template>
                    </p>
                </div>
                <a
                    v-if="r.url"
                    :href="r.url"
                    class="text-[13.5px] font-medium text-brand underline underline-offset-2"
                    >{{ t('APK নামান') }}</a
                >
            </div>
            <p
                v-if="releases.some((r) => r.flavor === 'sms')"
                class="text-[12.5px] leading-relaxed text-ink-3"
            >
                {{
                    t(
                        'SMS-সহ app-কে Google Play Protect আটকায় (SMS পড়ে বলে) — শুধু wallet SIM-এর phone-এ লাগে। Login page শুধু সাধারণ app দেয়।',
                    )
                }}
            </p>
        </SettingsSection>
    </div>
</template>
