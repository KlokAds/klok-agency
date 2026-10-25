<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type Campaign = {
    name: string;
    status: string | null;
    spend: number;
    results: number;
    cost_per_result: number | null;
    clicks: number;
};

type AdAccount = {
    id: number;
    name: string;
    external_id: string | null;
    platform: string | null;
    shop: string | null;
    client_id: number | null;
    client: string | null;
    error: string | null;
    currency: string;
    synced_at: string | null;
    totals: {
        spend?: number;
        results?: number;
        cost_per_result?: number | null;
        clicks?: number;
    };
    campaigns: Campaign[];
};

// Every ad account connected in Klok Pilot, its campaigns, and which
// client it is run for.
const props = defineProps<{
    connected: boolean;
    error: string | null;
    days: number;
    accounts: AdAccount[];
    options: { clients: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);
const days = ref(String(props.days));
const loading = ref(false);
const refreshing = ref(false);

const dayOptions = computed<Option[]>(() => [
    { value: '1', label: t('আজ') },
    { value: '7', label: t('৭ দিন') },
    { value: '30', label: t('৩০ দিন') },
]);

function load(value: string | number | null | undefined): void {
    router.reload({
        data: { days: value },
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

function refresh(): void {
    router.post(
        '/ad-accounts/refresh',
        {},
        {
            preserveScroll: true,
            onStart: () => (refreshing.value = true),
            onFinish: () => (refreshing.value = false),
        },
    );
}

function link(account: AdAccount, clientId: string | number | null): void {
    router.put(
        `/ad-accounts/${account.id}/client`,
        { client_id: clientId },
        { preserveScroll: true },
    );
}

function adMoney(value: number | null | undefined, currency: string): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return (
        (currency === 'BDT' ? String.fromCharCode(0x09f3) : '$') +
        value.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    );
}

function live(status: string | null): boolean {
    return ['ACTIVE', 'ENABLED', 'ENABLE'].includes(
        (status ?? '').toUpperCase(),
    );
}
</script>

<template>
    <PageHeader
        :eyebrow="t('ব্যবসা')"
        title="Ad account"
        :lead="
            t(
                'Klok Pilot-এ যুক্ত প্রতিটা ad account, তার campaign আর খরচ — কোন account কোন client-এর, সেটাও এখানে ঠিক করুন।',
            )
        "
    >
        <div class="flex flex-wrap items-center gap-2">
            <KSegment
                v-model="days"
                :options="dayOptions"
                size="sm"
                @update:model-value="load"
            />
            <KButton
                v-if="connected"
                icon="undo"
                :loading="refreshing"
                @click="refresh"
                >{{ t('নতুন করে আনুন') }}</KButton
            >
        </div>
    </PageHeader>

    <p
        v-if="error"
        class="panel mb-6 border-out/30 bg-out-soft px-5 py-3 text-[13px] text-out"
    >
        Klok Pilot: {{ error }}
    </p>

    <KEmpty
        v-if="!connected"
        class="panel"
        :title="t('Klok Pilot যুক্ত নয়')"
        :body="
            t(
                'Reminder ও Pilot পাতায় Klok Pilot যুক্ত করলে ad account এখানে আসবে।',
            )
        "
    />
    <KEmpty
        v-else-if="!accounts.length"
        class="panel"
        :title="t('কোনো ad account নেই')"
        :body="t('Klok Pilot-এ ad account যুক্ত করলে এখানে দেখা যাবে।')"
    />

    <div
        v-else
        class="grid grid-cols-1 gap-6 transition-opacity xl:grid-cols-2"
        :class="loading ? 'opacity-50' : ''"
    >
        <section
            v-for="a in accounts"
            :key="a.id"
            class="panel flex min-w-0 flex-col p-5 sm:p-6"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="truncate text-[16px] font-bold">{{ a.name }}</h2>
                    <p class="num mt-0.5 truncate text-[12.5px] text-ink-3">
                        {{ a.platform === 'meta' ? 'Meta' : a.platform }}
                        <template v-if="a.external_id">
                            · {{ a.external_id }}</template
                        >
                        <template v-if="a.shop"> · {{ a.shop }}</template>
                    </p>
                </div>
                <div class="w-full sm:w-56">
                    <KSelect
                        v-if="can['clients.edit']"
                        :model-value="a.client_id"
                        :options="options.clients"
                        :placeholder="t('কোন client-এর?')"
                        size="sm"
                        clearable
                        @update:model-value="(v) => link(a, v ?? null)"
                    />
                    <KButton
                        v-else-if="a.client_id"
                        size="sm"
                        icon="user"
                        :href="`/clients/${a.client_id}`"
                        >{{ a.client }}</KButton
                    >
                </div>
            </div>

            <p v-if="a.error" class="mt-4 text-[13px] text-out">
                {{ a.error }}
            </p>
            <template v-else>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                        <p class="text-[12px] text-ink-3">{{ t('খরচ') }}</p>
                        <p class="num mt-0.5 text-[16px] font-bold">
                            {{ adMoney(a.totals.spend ?? 0, a.currency) }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                        <p class="text-[12px] text-ink-3">Result</p>
                        <p class="num mt-0.5 text-[16px] font-bold">
                            {{ a.totals.results ?? 0 }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                        <p class="text-[12px] text-ink-3">
                            {{ t('প্রতি result খরচ') }}
                        </p>
                        <p class="num mt-0.5 text-[16px] font-bold">
                            {{ adMoney(a.totals.cost_per_result, a.currency) }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                        <p class="text-[12px] text-ink-3">Click</p>
                        <p class="num mt-0.5 text-[16px] font-bold">
                            {{ (a.totals.clicks ?? 0).toLocaleString('en-US') }}
                        </p>
                    </div>
                </div>

                <p
                    v-if="!a.campaigns.length"
                    class="mt-4 rounded-lg bg-sunken px-3 py-2.5 text-[13px] text-ink-3"
                >
                    {{ t('এই সময়ে কোনো campaign-এ খরচ হয়নি।') }}
                </p>
                <ul v-else class="mt-3">
                    <li
                        v-for="c in a.campaigns"
                        :key="c.name"
                        class="flex items-center gap-3 border-b border-line py-2.5 text-[13px] last:border-0"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :class="live(c.status) ? 'bg-in' : 'bg-line-2'"
                            :title="c.status ?? ''"
                        />
                        <span class="min-w-0 flex-1 truncate font-medium">{{
                            c.name
                        }}</span>
                        <span class="num shrink-0">{{
                            adMoney(c.spend, a.currency)
                        }}</span>
                        <span class="num w-20 shrink-0 text-right"
                            >{{ c.results }} result</span
                        >
                        <span
                            class="num hidden w-28 shrink-0 text-right text-ink-3 sm:block"
                            >{{
                                c.cost_per_result !== null
                                    ? 'CPR ' +
                                      adMoney(c.cost_per_result, a.currency)
                                    : '—'
                            }}</span
                        >
                    </li>
                </ul>
            </template>

            <div
                class="mt-auto flex items-center justify-between gap-3 pt-4 text-[12px] text-ink-3"
            >
                <span v-if="a.synced_at"
                    >{{ t('Pilot sync') }} {{ ago(a.synced_at) }}</span
                >
                <KTag v-if="!a.client_id" tone="wait">{{
                    t('Client বাছা হয়নি')
                }}</KTag>
            </div>
        </section>
    </div>
</template>
