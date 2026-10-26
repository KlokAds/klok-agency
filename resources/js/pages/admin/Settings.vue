<script setup lang="ts">
import SettingsSection from '@/components/SettingsSection.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTag from '@/components/k/KTag.vue';
import { niceDate, today } from '@/lib/format';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

const props = defineProps<{
    settings: {
        lock_date: string | null;
        require_2fa: boolean;
        idle_minutes: number;
        mfs_out_percent: string;
        bank_transfer_fee: string;
        business_whatsapp: string;
        meta_cards: string;
    };
    mail: {
        host: string;
        port: number;
        username: string;
        from_name: string;
        has_password: boolean;
        notify_email: string;
        receipts_host: string;
        receipts_username: string;
        receipts_has_password: boolean;
    };
}>();

const can = computed(() => usePage().props.auth.can);
const editable = computed(() => !!can.value['settings.edit']);

const form = useForm({ ...props.settings });
function save(): void {
    form.put('/admin/settings', { preserveScroll: true });
}

// The mailbox the app sends from (Hostinger): the password is never sent back.
const mailForm = useForm({
    host: props.mail.host,
    port: props.mail.port,
    username: props.mail.username,
    password: '',
    from_name: props.mail.from_name,
    notify_email: props.mail.notify_email,
    receipts_host: props.mail.receipts_host,
    receipts_username: props.mail.receipts_username,
    receipts_password: '',
});
function saveMail(): void {
    mailForm.put('/admin/settings/mail', {
        preserveScroll: true,
        onSuccess: () => mailForm.reset('password', 'receipts_password'),
    });
}
const testing = ref(false);
function testMail(): void {
    router.post(
        '/admin/settings/mail/test',
        {},
        {
            preserveScroll: true,
            onStart: () => (testing.value = true),
            onFinish: () => (testing.value = false),
        },
    );
}
const ports = computed<Option[]>(() => [
    { value: 465, label: '465 (SSL)' },
    { value: 587, label: '587 (TLS)' },
]);

const idleOptions = computed<Option[]>(() =>
    [15, 30, 60, 120].map((m) => ({
        value: m,
        label: t('{n} মিনিট', { n: m }),
    })),
);

// App update (Owner only): what is running, what GitHub has, and the last run's log.
type UpdateStatus = {
    available: boolean;
    branch: string;
    current: { sha: string; subject: string; date: string } | null;
    latest: string | null;
    behind: boolean | null;
    last_run: {
        ok: boolean;
        from: string | null;
        to: string | null;
        log: string[];
        at: string;
    } | null;
};

const isOwner = computed(() => usePage().props.auth.user.role === 'Owner');
const update = ref<UpdateStatus | null>(null);
const checking = ref(false);
const updateOpen = ref(false);
const updateForm = useForm({ password: '' });

async function checkUpdate(): Promise<void> {
    checking.value = true;
    try {
        update.value = await getJson<UpdateStatus>('/admin/update/status');
    } finally {
        checking.value = false;
    }
}

function runUpdate(): void {
    updateForm.post('/admin/update', {
        preserveScroll: true,
        onSuccess: () => {
            updateOpen.value = false;
            updateForm.reset();
            void checkUpdate();
        },
        onFinish: () => updateForm.reset('password'),
    });
}

onMounted(() => {
    if (isOwner.value) {
        void checkUpdate();
    }
});
</script>

<template>
    <SettingsNav />
    <PageHeader eyebrow="Settings" title="Settings" />

    <div class="max-w-5xl">
        <SettingsSection
            :title="t('খাতার নিয়ম')"
            :description="
                t(
                    'টাকা সরাতে কত fee কাটে, আর কোন তারিখ পর্যন্ত খাতা বন্ধ — সবার জন্য একই।',
                )
            "
            icon="scale"
        >
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <KField
                    :label="t('MFS থেকে বের করলে fee (%)')"
                    :error="form.errors.mfs_out_percent"
                    :hint="
                        t('bKash থেকে bank বা cash-এ সরালে টাকার এই % কাটে।')
                    "
                >
                    <KInput
                        v-model="form.mfs_out_percent"
                        inputmode="decimal"
                        :disabled="!editable"
                    >
                        <template #trail
                            ><span class="text-[14px] font-semibold text-ink-3"
                                >%</span
                            ></template
                        >
                    </KInput>
                </KField>
                <KField
                    :label="t('Bank transfer fee (প্রতি transfer)')"
                    :error="form.errors.bank_transfer_fee"
                    :hint="
                        t(
                            'Bank থেকে NPSB/অন্য bank-এ পাঠালে উপরে এই টাকা কাটে।',
                        )
                    "
                >
                    <KInput
                        v-model="form.bank_transfer_fee"
                        inputmode="decimal"
                        :disabled="!editable"
                    >
                        <template #trail
                            ><span class="text-[14px] font-semibold text-ink-3"
                                >&#2547;</span
                            ></template
                        >
                    </KInput>
                </KField>
            </div>
            <KField
                :label="t('আমাদের Meta card (শেষ ৪ সংখ্যা)')"
                :error="form.errors.meta_cards"
                :hint="
                    t(
                        'এই card-এ Meta যা কাটে, Klok Pilot থেকে পড়ে card-এর USD balance থেকে নিজে থেকে কমে। কয়েকটা হলে কমা দিয়ে।',
                    )
                "
            >
                <KInput
                    v-model="form.meta_cards"
                    inputmode="numeric"
                    placeholder="6903"
                    :disabled="!editable"
                />
            </KField>
            <KField
                :label="t('Business WhatsApp নম্বর')"
                :error="form.errors.business_whatsapp"
                :hint="
                    t(
                        'Client portal-এর ‘যোগাযোগ’ button এই নম্বরে WhatsApp খোলে।',
                    )
                "
            >
                <KInput
                    v-model="form.business_whatsapp"
                    inputmode="tel"
                    placeholder="01XXXXXXXXX"
                    :disabled="!editable"
                />
            </KField>
            <KField
                :label="t('খাতা বন্ধ (lock) — এই তারিখ পর্যন্ত')"
                :error="form.errors.lock_date"
                :hint="
                    t(
                        'মাস শেষে হিসাব মিলিয়ে lock দিন — ওই সময়ের কোনো entry আর যোগ, বদল বা মোছা যাবে না। তারিখ ছাড়া পুরনো row-ও lock হবে।',
                    )
                "
            >
                <KDate
                    v-model="form.lock_date"
                    :max="today()"
                    clearable
                    :placeholder="t('Lock নেই')"
                />
            </KField>
            <template v-if="editable" #footer>
                <KButton
                    variant="primary"
                    :loading="form.processing"
                    @click="save"
                    >{{ t('সংরক্ষণ') }}</KButton
                >
            </template>
        </SettingsSection>

        <SettingsSection
            :title="t('নিরাপত্তা')"
            :description="t('Login আর sign out-এর নিয়ম — সবার জন্য।')"
            icon="shield"
        >
            <KSwitch
                v-model="form.require_2fa"
                :label="t('সবার জন্য 2FA বাধ্যতামূলক')"
                :hint="
                    t(
                        'চালু করলে 2FA ছাড়া কেউ খাতা খুলতে পারবে না — আগে নিজে চালু করুন।',
                    )
                "
            />
            <p
                v-if="form.errors.require_2fa"
                class="-mt-3 rounded-lg border border-out/30 bg-out-soft px-3 py-2 text-[13.5px] font-medium text-out"
            >
                {{ form.errors.require_2fa }}
            </p>
            <KField
                :label="t('কাজ না করলে নিজে sign out')"
                :hint="t('শেষ ১ মিনিটে সতর্ক করা হবে।')"
            >
                <KSegment
                    v-model="form.idle_minutes"
                    :options="idleOptions"
                    size="sm"
                />
            </KField>
            <template v-if="editable" #footer>
                <KButton
                    variant="primary"
                    :loading="form.processing"
                    @click="save"
                    >{{ t('সংরক্ষণ') }}</KButton
                >
            </template>
        </SettingsSection>

        <SettingsSection
            title="Email"
            :description="
                t(
                    'যে mailbox থেকে app email পাঠায় (যেমন Hostinger-এর billing@klokads.com), আর renewal-এর খবর কার কাছে যাবে।',
                )
            "
            icon="mail"
        >
            <div
                class="grid grid-cols-1 gap-5 sm:grid-cols-[minmax(0,1fr)_160px]"
            >
                <KField label="SMTP host" :error="mailForm.errors.host">
                    <KInput v-model="mailForm.host" :disabled="!editable" />
                </KField>
                <KField label="Port" :error="mailForm.errors.port">
                    <KSegment
                        v-model="mailForm.port"
                        :options="ports"
                        size="sm"
                    />
                </KField>
            </div>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <KField
                    :label="t('Mailbox (email)')"
                    :error="mailForm.errors.username"
                >
                    <KInput
                        v-model="mailForm.username"
                        type="email"
                        placeholder="billing@klokads.com"
                        :disabled="!editable"
                    />
                </KField>
                <KField
                    :label="t('Mailbox-এর password')"
                    :error="mailForm.errors.password"
                    :hint="
                        mail.has_password
                            ? t(
                                  'রাখা আছে (encrypted)। খালি রাখলে আগেরটাই থাকবে।',
                              )
                            : t('Hostinger → Emails-এ এই mailbox-এর password।')
                    "
                >
                    <KInput
                        v-model="mailForm.password"
                        type="password"
                        autocomplete="new-password"
                        :disabled="!editable"
                    />
                </KField>
                <KField
                    :label="t('পাঠানোর নাম')"
                    :error="mailForm.errors.from_name"
                >
                    <KInput
                        v-model="mailForm.from_name"
                        :disabled="!editable"
                    />
                </KField>
                <KField
                    :label="t('খবর যাবে এই email-এ')"
                    :error="mailForm.errors.notify_email"
                    :hint="
                        t('Client contract শেষ করলে বা renewal-এর টাকা দিলে।')
                    "
                >
                    <KInput
                        v-model="mailForm.notify_email"
                        type="email"
                        :disabled="!editable"
                    />
                </KField>
            </div>
            <div class="border-t border-line pt-5">
                <p class="text-[14.5px] font-semibold">
                    {{ t('Meta receipt-এর mailbox') }}
                </p>
                <p class="mt-1 text-[13.5px] text-ink-3">
                    {{
                        t(
                            'Gmail-এ filter দিয়ে Meta-র receipt এই mailbox-এ forward করুন — প্রতি কয়েক মিনিটে পড়ে card charge নিজে খাতায় উঠবে, campaign-এর ভাগসহ।',
                        )
                    }}
                </p>
                <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <KField
                        :label="t('Mailbox (email)')"
                        optional
                        :error="mailForm.errors.receipts_username"
                    >
                        <KInput
                            v-model="mailForm.receipts_username"
                            type="email"
                            placeholder="receipts@klokads.com"
                            :disabled="!editable"
                        />
                    </KField>
                    <KField
                        :label="t('Mailbox-এর password')"
                        optional
                        :error="mailForm.errors.receipts_password"
                        :hint="
                            mail.receipts_has_password
                                ? t(
                                      'রাখা আছে (encrypted)। খালি রাখলে আগেরটাই থাকবে।',
                                  )
                                : undefined
                        "
                    >
                        <KInput
                            v-model="mailForm.receipts_password"
                            type="password"
                            autocomplete="new-password"
                            :disabled="!editable"
                        />
                    </KField>
                    <KField
                        label="IMAP host"
                        :error="mailForm.errors.receipts_host"
                    >
                        <KInput
                            v-model="mailForm.receipts_host"
                            :disabled="!editable"
                        />
                    </KField>
                </div>
            </div>
            <template v-if="editable" #footer>
                <KButton
                    icon="mail"
                    :loading="testing"
                    :disabled="!mail.has_password"
                    @click="testMail"
                    >{{ t('পরীক্ষার email পাঠান') }}</KButton
                >
                <KButton
                    variant="primary"
                    :loading="mailForm.processing"
                    @click="saveMail"
                    >{{ t('সংরক্ষণ') }}</KButton
                >
            </template>
        </SettingsSection>

        <SettingsSection
            v-if="isOwner"
            :title="t('App update')"
            :description="
                t(
                    'GitHub-এ নতুন code push হলে এখান থেকে এক click-এ live server-এ নিয়ে আসুন। Server নিজে pull, migrate আর cache করে নেবে।',
                )
            "
            icon="down"
        >
            <p v-if="!update" class="text-[14px] text-ink-3">
                {{
                    checking
                        ? t('GitHub-এর সাথে মেলানো হচ্ছে…')
                        : t('অবস্থা জানতে “আবার দেখুন” চাপুন।')
                }}
            </p>
            <div v-if="update" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-line px-4 py-3">
                    <p class="eyebrow">{{ t('এখন চলছে') }}</p>
                    <p v-if="update.current" class="mt-1 text-[14.5px]">
                        <span class="num font-semibold">{{
                            update.current.sha.slice(0, 7)
                        }}</span>
                        <span class="ml-2 text-ink-3">{{
                            niceDate(update.current.date)
                        }}</span>
                    </p>
                    <p
                        v-if="update.current"
                        class="mt-0.5 truncate text-[13.5px] text-ink-2"
                        :title="update.current.subject"
                    >
                        {{ update.current.subject }}
                    </p>
                    <p
                        v-if="!update.available"
                        class="mt-1 text-[14px] text-ink-3"
                    >
                        {{ t('এটা git checkout নয় (local)') }}
                    </p>
                </div>
                <div class="rounded-lg border border-line px-4 py-3">
                    <p class="eyebrow">GitHub · {{ update.branch }}</p>
                    <p class="mt-1 text-[14.5px] font-semibold">
                        <span v-if="update.latest" class="num">{{
                            update.latest.slice(0, 7)
                        }}</span>
                        <span v-else class="font-normal text-ink-3">{{
                            t('জানা যায়নি')
                        }}</span>
                    </p>
                    <p
                        class="mt-0.5 flex items-center gap-1.5 text-[13.5px] font-medium"
                        :class="update.behind ? 'text-wait' : 'text-in'"
                    >
                        <KIcon
                            :name="update.behind ? 'warn' : 'check'"
                            :size="14"
                        />
                        {{
                            update.behind
                                ? t('নতুন version আছে')
                                : update.behind === false
                                  ? t('সর্বশেষ version চলছে')
                                  : t('GitHub-এর সাথে মেলানো যায়নি')
                        }}
                    </p>
                </div>
            </div>

            <details
                v-if="update?.last_run"
                class="rounded-lg border border-line"
            >
                <summary
                    class="flex cursor-pointer items-center gap-2 px-4 py-2.5 text-[14px] font-semibold"
                >
                    <KTag :tone="update.last_run.ok ? 'in' : 'out'">{{
                        update.last_run.ok ? t('সফল') : t('ব্যর্থ')
                    }}</KTag>
                    {{ t('শেষ update') }} ·
                    {{ niceDate(update.last_run.at.slice(0, 10)) }}
                </summary>
                <pre
                    class="max-h-72 overflow-auto border-t border-line bg-sunken px-4 py-3 font-mono text-[13px] leading-relaxed whitespace-pre-wrap"
                    >{{ update.last_run.log.join('\n\n') }}</pre>
            </details>

            <template #footer>
                <KButton icon="undo" :loading="checking" @click="checkUpdate">{{
                    t('আবার দেখুন')
                }}</KButton>
                <KButton
                    variant="primary"
                    icon="down"
                    :disabled="!update?.available || !update?.behind"
                    @click="updateOpen = true"
                    >{{ t('এখনই update করুন') }}</KButton
                >
            </template>
        </SettingsSection>
    </div>

    <KModal
        v-model="updateOpen"
        width="sm"
        :title="t('App update করবেন?')"
        :subtitle="
            t('কয়েক মিনিট সময় লাগতে পারে; এর মধ্যে app কিছুক্ষণ বন্ধ থাকবে।')
        "
    >
        <form id="update-form" @submit.prevent="runUpdate">
            <KField
                :label="t('নিশ্চিত করতে আপনার password দিন')"
                :error="updateForm.errors.password"
            >
                <KInput
                    v-model="updateForm.password"
                    type="password"
                    autocomplete="current-password"
                    :invalid="!!updateForm.errors.password"
                />
            </KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="updateOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="update-form"
                variant="primary"
                :loading="updateForm.processing"
                >{{ t('Update শুরু করুন') }}</KButton
            >
        </template>
    </KModal>
</template>
