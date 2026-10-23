<script setup lang="ts">
import SettingsSection from '@/components/SettingsSection.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import { useForm, usePage } from '@inertiajs/vue3';
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
    };
}>();

const can = computed(() => usePage().props.auth.can);
const editable = computed(() => !!can.value['settings.edit']);

const form = useForm({ ...props.settings });
function save(): void {
    form.put('/admin/settings', { preserveScroll: true });
}

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
            <div class="grid gap-5 sm:grid-cols-2">
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
                            ><span class="text-[13px] font-semibold text-ink-3"
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
                            ><span class="text-[13px] font-semibold text-ink-3"
                                >&#2547;</span
                            ></template
                        >
                    </KInput>
                </KField>
            </div>
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
                class="-mt-3 text-[12.5px] font-medium text-out"
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
            v-if="isOwner"
            :title="t('App update')"
            :description="
                t(
                    'GitHub-এ নতুন code push হলে এখান থেকে এক click-এ live server-এ নিয়ে আসুন। Server নিজে pull, migrate আর cache করে নেবে।',
                )
            "
            icon="down"
        >
            <p v-if="!update" class="text-[13px] text-ink-3">
                {{
                    checking
                        ? t('GitHub-এর সাথে মেলানো হচ্ছে…')
                        : t('অবস্থা জানতে “আবার দেখুন” চাপুন।')
                }}
            </p>
            <div v-if="update" class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-line px-4 py-3">
                    <p class="eyebrow">{{ t('এখন চলছে') }}</p>
                    <p v-if="update.current" class="mt-1 text-[13.5px]">
                        <span class="num font-semibold">{{
                            update.current.sha.slice(0, 7)
                        }}</span>
                        <span class="ml-2 text-ink-3">{{
                            niceDate(update.current.date)
                        }}</span>
                    </p>
                    <p
                        v-if="update.current"
                        class="mt-0.5 truncate text-[12.5px] text-ink-2"
                        :title="update.current.subject"
                    >
                        {{ update.current.subject }}
                    </p>
                    <p
                        v-if="!update.available"
                        class="mt-1 text-[13px] text-ink-3"
                    >
                        {{ t('এটা git checkout নয় (local)') }}
                    </p>
                </div>
                <div class="rounded-lg border border-line px-4 py-3">
                    <p class="eyebrow">GitHub · {{ update.branch }}</p>
                    <p class="num mt-1 text-[13.5px] font-semibold">
                        {{ update.latest ? update.latest.slice(0, 7) : '—' }}
                    </p>
                    <p
                        class="mt-0.5 flex items-center gap-1.5 text-[12.5px] font-medium"
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
                    class="flex cursor-pointer items-center gap-2 px-4 py-2.5 text-[13px] font-semibold"
                >
                    <KTag :tone="update.last_run.ok ? 'in' : 'out'">{{
                        update.last_run.ok ? t('সফল') : t('ব্যর্থ')
                    }}</KTag>
                    {{ t('শেষ update') }} ·
                    {{ niceDate(update.last_run.at.slice(0, 10)) }}
                </summary>
                <pre
                    class="max-h-72 overflow-auto border-t border-line bg-sunken px-4 py-3 font-mono text-[11.5px] leading-relaxed whitespace-pre-wrap"
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
