<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KSelect from '@/components/k/KSelect.vue';
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
    };
    tick: { url: string | null; last: string | null };
}>();

const can = computed(() => usePage().props.auth.can);
const editable = computed(() => !!can.value['settings.edit']);

const form = useForm({
    summary_time: props.settings.summary_time,
    pilot_url: props.settings.pilot_url,
});
const tokenForm = useForm({ pilot_token: '' });

const times = computed<Option[]>(() =>
    Array.from({ length: 16 }, (_, i) => {
        const value = `${String(8 + i).padStart(2, '0')}:00`;

        return { value, label: value };
    }).concat([
        { value: '21:30', label: '21:30' },
        { value: '22:30', label: '22:30' },
    ]),
);

const shops = ref<{ id: number; name: string }[] | null>(null);
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
            shops?: { id: number; name: string }[];
            error?: string;
        };

        if (result.ok) {
            shops.value = result.shops ?? [];
            toast(
                t('Klok Pilot-এর সাথে যুক্ত — {n}টা shop দেখা যাচ্ছে।', {
                    n: shops.value.length,
                }),
            );
        } else {
            shops.value = null;
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
    <PageHeader
        :eyebrow="t('নিয়ন্ত্রণ')"
        :title="t('Reminder ও Klok Pilot')"
        :lead="
            t(
                'দিনের শেষের সারসংক্ষেপ কখন যাবে, Klok Pilot থেকে ads-এর ফলাফল আনা, আর reminder সময়মতো পাঠানোর ব্যবস্থা।',
            )
        "
    />

    <div class="grid gap-6 lg:grid-cols-2">
        <!-- Daily summary + Pilot -->
        <form
            class="panel flex flex-col gap-5 p-6"
            @submit.prevent="
                form.put('/admin/agency', { preserveScroll: true })
            "
        >
            <h2 class="flex items-center gap-2 text-[17px] font-bold">
                <KIcon name="chart" class="text-brand" />{{
                    t('দিনের শেষের সারসংক্ষেপ')
                }}
            </h2>
            <KField
                :label="t('কখন পাঠাবে')"
                :hint="t('Owner আর client-দের manager-রা পাবেন।')"
            >
                <KSelect
                    v-model="form.summary_time"
                    :options="times"
                    :searchable="false"
                    class="max-w-[160px]"
                />
            </KField>
            <KField label="Klok Pilot URL" :error="form.errors.pilot_url">
                <KInput v-model="form.pilot_url" />
            </KField>
            <div v-if="editable" class="flex flex-wrap justify-end gap-2">
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
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >{{ t('সংরক্ষণ') }}</KButton
                >
            </div>
        </form>

        <section class="panel flex flex-col gap-5 p-6">
            <div class="flex items-start justify-between gap-3">
                <h2 class="flex items-center gap-2 text-[17px] font-bold">
                    <KIcon name="signal" class="text-brand" />Klok Pilot
                </h2>
                <KTag :tone="settings.pilot_connected ? 'in' : 'neutral'">{{
                    settings.pilot_connected ? t('যুক্ত') : t('যুক্ত নয়')
                }}</KTag>
            </div>
            <p class="text-[13px] text-ink-2">
                {{
                    t(
                        'Klok Pilot → Agents থেকে একটা agency token বানিয়ে এখানে দিন (শুধু পড়ার অনুমতি যথেষ্ট)। তারপর প্রতিটা client-এর পাতায় তার Pilot shop বেছে দিন।',
                    )
                }}
            </p>
            <form
                v-if="editable"
                class="flex gap-2"
                @submit.prevent="
                    tokenForm.put('/admin/agency/pilot-token', {
                        preserveScroll: true,
                        onSuccess: () => tokenForm.reset(),
                    })
                "
            >
                <KInput
                    v-model="tokenForm.pilot_token"
                    type="password"
                    autocomplete="off"
                    :placeholder="
                        settings.pilot_connected
                            ? t('নতুন token দিলে আগেরটা বদলাবে')
                            : 'Agency token'
                    "
                    class="flex-1"
                />
                <KButton type="submit" :loading="tokenForm.processing">{{
                    t('রাখুন')
                }}</KButton>
            </form>
            <div class="flex flex-wrap items-center gap-2">
                <KButton
                    size="sm"
                    :loading="testing"
                    :disabled="!settings.pilot_connected"
                    @click="test"
                    >{{ t('Connection দেখুন') }}</KButton
                >
            </div>
            <ul v-if="shops" class="rounded-xl border border-line text-[13px]">
                <li
                    v-for="s in shops"
                    :key="s.id"
                    class="flex justify-between border-b border-dashed border-line px-3 py-2 last:border-0"
                >
                    <span>{{ s.name }}</span
                    ><span class="num text-ink-3">#{{ s.id }}</span>
                </li>
                <li v-if="!shops.length" class="px-3 py-2 text-ink-3">
                    {{ t('এই token কোনো shop দেখতে পায় না।') }}
                </li>
            </ul>
        </section>

        <!-- Reminders on time -->
        <section class="panel flex flex-col gap-4 p-6 lg:col-span-2">
            <h2 class="flex items-center gap-2 text-[17px] font-bold">
                <KIcon name="clock" class="text-brand" />{{
                    t('Reminder সময়মতো পাঠানো')
                }}
            </h2>
            <p class="max-w-3xl text-[13px] text-ink-2">
                {{
                    t(
                        'কেউ app খুললে প্রতি ৫ মিনিটে reminder নিজে যায়। কেউ না খুললেও সময়মতো পেতে cron-job.org-এ নিচের URL-টা প্রতি ৫ মিনিটে চালাতে দিন।',
                    )
                }}
            </p>
            <div v-if="tick.url" class="flex flex-wrap items-center gap-2">
                <code
                    class="num min-w-0 flex-1 truncate rounded-lg bg-sunken px-3 py-2 text-[12.5px]"
                    >{{ tick.url }}</code
                >
                <KButton size="sm" @click="copy(tick.url)">Copy</KButton>
            </div>
            <div
                class="flex flex-wrap items-center gap-3 text-[12.5px] text-ink-3"
            >
                <span
                    >{{ t('শেষ চলেছে') }}:
                    {{ tick.last ? ago(tick.last) : '—' }}</span
                >
                <KButton
                    v-if="editable"
                    size="sm"
                    variant="quiet"
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
            </div>
        </section>
    </div>
</template>
