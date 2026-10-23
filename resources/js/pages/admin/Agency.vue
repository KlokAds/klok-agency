<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KSelect from '@/components/k/KSelect.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTag from '@/components/k/KTag.vue';
import { toast } from '@/lib/feedback';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

const props = defineProps<{
    settings: {
        summary_time: string;
        pilot_url: string;
        pilot_connected: boolean;
        credit_guard: boolean;
        credit_guard_error: string | null;
    };
    tick: { url: string | null; last: string | null };
}>();

const can = computed(() => usePage().props.auth.can);
const editable = computed(() => !!can.value['settings.edit']);

const form = useForm({
    summary_time: props.settings.summary_time,
    pilot_url: props.settings.pilot_url,
    credit_guard: props.settings.credit_guard,
});
const tokenForm = useForm({ pilot_token: '' });

function save(): void {
    form.put('/admin/agency', { preserveScroll: true });
}

const times = computed<Option[]>(() =>
    Array.from({ length: 16 }, (_, i) => {
        const value = `${String(8 + i).padStart(2, '0')}:00`;

        return { value, label: value };
    }).concat([
        { value: '21:30', label: '21:30' },
        { value: '22:30', label: '22:30' },
    ]),
);

type PilotAccount = {
    id: number;
    name: string;
    external_id: string | null;
    shop: string | null;
};

const accounts = ref<PilotAccount[] | null>(null);
const testing = ref(false);

async function test(): Promise<void> {
    testing.value = true;
    try {
        const xsrf = decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
        );
        const response = await fetch('/admin/agency/pilot-test', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
            credentials: 'same-origin',
        });
        const result = (await response.json()) as {
            ok: boolean;
            accounts?: PilotAccount[];
            error?: string;
        };

        if (result.ok) {
            accounts.value = result.accounts ?? [];
            toast(
                t('Klok Pilot-এর সাথে যুক্ত — {n}টা ad account দেখা যাচ্ছে।', {
                    n: accounts.value.length,
                }),
            );
        } else {
            accounts.value = null;
            toast(result.error ?? 'Klok Pilot', 'error');
        }
    } finally {
        testing.value = false;
    }
}

function copy(text: string): void {
    void navigator.clipboard?.writeText(text);
    toast(t('Copy হয়েছে।'), 'info');
}
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        :title="t('Reminder ও Klok Pilot')"
        :lead="
            t(
                'দিনের শেষের সারসংক্ষেপ কখন যাবে, Klok Pilot থেকে ads-এর ফলাফল আনা, আর reminder সময়মতো পাঠানোর ব্যবস্থা।',
            )
        "
    />

    <div class="max-w-5xl">
        <SettingsSection
            :title="t('দিনের শেষের সারসংক্ষেপ')"
            :description="t('Owner আর client-দের manager-রা পাবেন।')"
            icon="chart"
        >
            <form @submit.prevent="save">
                <KField :label="t('কখন পাঠাবে')">
                    <KSelect
                        v-model="form.summary_time"
                        :options="times"
                        :searchable="false"
                        class="max-w-[160px]"
                    />
                </KField>
            </form>
            <template v-if="editable" #footer>
                <KButton
                    icon="bell"
                    @click="
                        router.post(
                            '/admin/agency/summary-now',
                            {},
                            { preserveScroll: true },
                        )
                    "
                    >{{ t('এখনই একবার পাঠান') }}</KButton
                >
                <KButton
                    variant="primary"
                    :loading="form.processing"
                    @click="save"
                    >{{ t('সংরক্ষণ') }}</KButton
                >
            </template>
        </SettingsSection>

        <SettingsSection
            title="Klok Pilot"
            :description="
                t(
                    'Klok Pilot → Agents থেকে একটা agency token বানিয়ে এখানে দিন। Campaign নিজে বন্ধ আর extend করতে token-এ campaign বদলানোর (write:campaigns) অনুমতি লাগবে।',
                )
            "
            icon="signal"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-[13.5px] font-medium">{{ t('অবস্থা') }}</span>
                <KTag :tone="settings.pilot_connected ? 'in' : 'neutral'">{{
                    settings.pilot_connected ? t('যুক্ত') : t('যুক্ত নয়')
                }}</KTag>
            </div>
            <form @submit.prevent="save">
                <KField label="Klok Pilot URL" :error="form.errors.pilot_url">
                    <KInput v-model="form.pilot_url" />
                </KField>
            </form>
            <form
                v-if="editable"
                @submit.prevent="
                    tokenForm.put('/admin/agency/pilot-token', {
                        preserveScroll: true,
                        onSuccess: () => tokenForm.reset(),
                    })
                "
            >
                <KField label="Agency token">
                    <div class="flex gap-2">
                        <KInput
                            v-model="tokenForm.pilot_token"
                            type="password"
                            autocomplete="off"
                            :placeholder="
                                settings.pilot_connected
                                    ? t('নতুন token দিলে আগেরটা বদলাবে')
                                    : 'Agency token'
                            "
                            class="min-w-0 flex-1"
                        />
                        <KButton
                            type="submit"
                            :loading="tokenForm.processing"
                            >{{ t('রাখুন') }}</KButton
                        >
                    </div>
                </KField>
            </form>
            <ul
                v-if="accounts"
                class="rounded-lg border border-line text-[13px]"
            >
                <li
                    v-for="a in accounts"
                    :key="a.id"
                    class="flex justify-between gap-3 border-b border-line px-3 py-2 last:border-0"
                >
                    <span class="min-w-0"
                        >{{ a.name
                        }}<span v-if="a.shop" class="text-ink-3">
                            · {{ a.shop }}</span
                        ></span
                    ><span class="num shrink-0 text-ink-3">{{
                        a.external_id ?? '#' + a.id
                    }}</span>
                </li>
                <li v-if="!accounts.length" class="px-3 py-2 text-ink-3">
                    {{ t('এই token কোনো ad account দেখতে পায় না।') }}
                </li>
            </ul>
            <template #footer>
                <KButton
                    :loading="testing"
                    :disabled="!settings.pilot_connected"
                    @click="test"
                    >{{ t('Connection দেখুন') }}</KButton
                >
                <KButton
                    v-if="editable"
                    variant="primary"
                    :loading="form.processing"
                    @click="save"
                    >{{ t('সংরক্ষণ') }}</KButton
                >
            </template>
        </SettingsSection>

        <SettingsSection
            :title="t('Client-এর টাকা শেষ হলে')"
            :description="
                t(
                    'আগে টাকা দেওয়া client-এর campaign-এ Meta-তে spend cap বসে — client যত dollar-এর টাকা দিয়েছে তার বেশি এক cent-ও খরচ হয় না। টাকা শেষ হলে campaign বন্ধ হয়; extend করলে আবার চলে।',
                )
            "
            icon="shield"
        >
            <KSwitch
                v-model="form.credit_guard"
                :disabled="!editable"
                :label="t('টাকা শেষ হলে campaign নিজে বন্ধ করুন')"
                :hint="
                    t(
                        'শুধু Meta-র USD ad account। $100-এর কম cap Meta নেয় না — তখন টাকা শেষ হলে pause করা হয়।',
                    )
                "
            />
            <p
                v-if="settings.credit_guard_error"
                class="rounded-lg border border-out/30 bg-out-soft px-3 py-2 text-[12.5px] text-out"
            >
                {{ settings.credit_guard_error }}
            </p>
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
            :title="t('Reminder সময়মতো পাঠানো')"
            :description="
                t(
                    'কেউ app খুললে প্রতি ৫ মিনিটে reminder নিজে যায়। কেউ না খুললেও সময়মতো পেতে cron-job.org-এ নিচের URL-টা প্রতি ৫ মিনিটে চালাতে দিন।',
                )
            "
            icon="clock"
        >
            <div v-if="tick.url" class="flex flex-wrap items-center gap-2">
                <code
                    class="num min-w-0 flex-1 truncate rounded-lg bg-sunken px-3 py-2 text-[12.5px]"
                    >{{ tick.url }}</code
                >
                <KButton size="sm" @click="copy(tick.url)">Copy</KButton>
            </div>
            <p class="text-[13px] text-ink-3">
                {{ t('শেষ চলেছে') }}:
                {{ tick.last ? ago(tick.last) : '—' }}
            </p>
            <template v-if="editable" #footer>
                <KButton
                    @click="
                        router.post(
                            '/admin/agency/tick-token',
                            {},
                            { preserveScroll: true },
                        )
                    "
                    >{{
                        tick.url ? t('নতুন URL বানান') : t('URL বানান')
                    }}</KButton
                >
            </template>
        </SettingsSection>
    </div>
</template>
