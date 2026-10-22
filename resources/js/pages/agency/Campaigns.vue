<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import KField from '@/components/k/KField.vue';
import { useFilters } from '@/composables/useFilters';
import { ago, money, niceDate, today } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    status: string | null;
    live: boolean;
    objective: string | null;
    daily_budget: number | null;
    start_at: string | null;
    stop_at: string | null;
    spend_total: number;
    spend_7d: number;
    last_spend_day: string | null;
    client_id: number | null;
    client: string | null;
    bill_from: string | null;
    suggested: { id: number; name: string } | null;
};

// Billing by campaign: pick an ad account, link each campaign to the
// client it runs for, and from which day its spend is billed to them.
const props = defineProps<{
    connected: boolean;
    error: string | null;
    accounts: Option[];
    account: {
        id: number;
        name: string;
        external_id: string | null;
        currency: string | null;
        shop: string | null;
    } | null;
    campaigns: Paginated<Row>;
    counts: { total: number; linked: number };
    filters: { q: string | null; status: string };
    synced_at: string | null;
    options: { clients: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);
const usdAccount = computed(() => (props.account?.currency ?? 'USD') === 'USD');

const { filters } = useFilters('/campaigns', {
    account: props.account?.id ?? null,
    q: props.filters.q,
    status: props.filters.status,
});

const statuses = computed<Option[]>(() => [
    { value: 'all', label: t('সব') },
    { value: 'live', label: t('চালু') },
    { value: 'off', label: t('বন্ধ') },
    { value: 'unlinked', label: t('Client বাছা হয়নি') },
    { value: 'linked', label: t('যুক্ত') },
]);

const spend = (cents: number | null) =>
    cents === null ? '—' : money(cents, 'usd');

// Linking asks for the first day to bill, so old spend is not billed twice.
const editing = ref<Row | null>(null);
const linkOpen = ref(false);
const linkClient = ref<number | string | null>(null);
const linkFrom = ref<string | null>(today());
const saving = ref(false);

function startLink(row: Row, clientId: number | string | null = null): void {
    editing.value = row;
    linkClient.value = clientId ?? row.client_id ?? row.suggested?.id ?? null;
    linkFrom.value = row.bill_from ?? today();
    linkOpen.value = true;
}

function saveLink(): void {
    if (!editing.value) {
        return;
    }

    router.put(
        `/campaigns/${editing.value.id}/client`,
        {
            client_id: linkClient.value,
            bill_from: linkClient.value ? linkFrom.value : null,
        },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => (linkOpen.value = false),
        },
    );
}

function unlink(): void {
    linkClient.value = null;
    saveLink();
}

const suggestedCount = computed(
    () => props.campaigns.data.filter((c) => c.suggested).length,
);
const bulkOpen = ref(false);
const bulkFrom = ref<string | null>(today());

function linkSuggested(): void {
    router.post(
        '/campaigns/link-suggested',
        { account: props.account?.id, bill_from: bulkFrom.value },
        { preserveScroll: true, onSuccess: () => (bulkOpen.value = false) },
    );
}

const syncing = ref(false);
function sync(): void {
    router.post(
        '/campaigns/sync',
        { account: props.account?.id },
        {
            preserveScroll: true,
            onStart: () => (syncing.value = true),
            onFinish: () => (syncing.value = false),
        },
    );
}
</script>

<template>
    <PageHeader
        :eyebrow="t('Client ও Ads')"
        title="Campaign"
        :lead="
            t(
                'Ad account বেছে তার campaign-গুলো দেখুন, প্রতিটা campaign কোন client-এর তা বেছে দিন — সেই campaign-এর খরচ ওই client-এর হিসাবে যাবে।',
            )
        "
    >
        <KButton
            v-if="connected"
            icon="undo"
            :loading="syncing"
            @click="sync"
            >{{ t('Pilot থেকে আনুন') }}</KButton
        >
    </PageHeader>

    <p v-if="error" class="panel mb-6 px-5 py-3 text-[13px] text-out">
        Klok Pilot: {{ error }}
    </p>

    <KEmpty
        v-if="!connected || !accounts.length"
        class="panel"
        :title="t('কোনো ad account নেই')"
        :body="t('Klok Pilot-এ ad account যুক্ত করলে এখানে দেখা যাবে।')"
    />

    <template v-else>
        <section class="panel mb-4 p-4 sm:p-5">
            <div
                class="grid gap-3 md:grid-cols-[minmax(0,320px)_minmax(0,1fr)]"
            >
                <KSelect
                    v-model="filters.account"
                    :options="accounts"
                    :placeholder="t('Ad account বাছুন')"
                />
                <KInput
                    v-model="filters.q"
                    :placeholder="t('Campaign খুঁজুন…')"
                    icon="search"
                />
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <KSegment
                    v-model="filters.status"
                    :options="statuses"
                    size="sm"
                />
                <p class="text-[12.5px] text-ink-3">
                    {{
                        t('{linked}/{total}টা campaign client-এর সাথে যুক্ত', {
                            linked: counts.linked,
                            total: counts.total,
                        })
                    }}
                    <template v-if="synced_at">
                        · {{ t('খরচ মেলানো') }} {{ ago(synced_at) }}</template
                    >
                </p>
            </div>
            <p
                v-if="account && !usdAccount"
                class="mt-3 rounded-lg bg-wait-soft px-3 py-2 text-[12.5px] text-wait"
            >
                {{
                    t(
                        'এই account-এর টাকা {currency}-এ — card-এর USD হিসাবে শুধু USD account-এর campaign যোগ হয়।',
                        { currency: account.currency ?? '' },
                    )
                }}
            </p>
            <div
                v-if="suggestedCount && can['clients.edit']"
                class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-soft px-3 py-2 text-[13px] text-brand-ink"
            >
                <span>{{
                    t('{n}টা campaign-এর নামে client-এর নাম আছে।', {
                        n: suggestedCount,
                    })
                }}</span>
                <KButton size="sm" @click="bulkOpen = true">{{
                    t('নাম দেখে যুক্ত করুন')
                }}</KButton>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <KEmpty
                v-if="!campaigns.data.length"
                :title="t('কোনো campaign পাওয়া যায়নি')"
                :body="t('অন্য account বা filter দেখুন, বা Pilot থেকে আনুন।')"
            />
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-[13px]">
                    <thead>
                        <tr
                            class="border-b border-line text-left text-[11.5px] font-semibold tracking-wide text-ink-3 uppercase"
                        >
                            <th class="px-5 py-3">Campaign</th>
                            <th class="px-3 py-3 text-right">
                                {{ t('৭ দিনে') }}
                            </th>
                            <th class="px-3 py-3 text-right">
                                {{ t('মোট খরচ') }}
                            </th>
                            <th class="px-3 py-3">{{ t('শেষ খরচ') }}</th>
                            <th class="px-5 py-3">Client</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in campaigns.data"
                            :key="c.id"
                            class="border-b border-dashed border-line last:border-0"
                        >
                            <td class="max-w-[340px] px-5 py-3">
                                <p class="flex items-center gap-2">
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        :class="c.live ? 'bg-in' : 'bg-line-2'"
                                        :title="c.status ?? ''"
                                    />
                                    <span class="truncate font-medium">{{
                                        c.name
                                    }}</span>
                                </p>
                                <p class="mt-0.5 pl-4 text-[12px] text-ink-3">
                                    {{ c.live ? t('চালু') : t('বন্ধ') }}
                                    <template v-if="c.daily_budget">
                                        · {{ t('দিনে') }}
                                        {{ spend(c.daily_budget) }}</template
                                    >
                                    <template v-if="c.objective">
                                        · {{ c.objective }}</template
                                    >
                                </p>
                            </td>
                            <td class="num px-3 py-3 text-right">
                                {{ spend(c.spend_7d) }}
                            </td>
                            <td class="num px-3 py-3 text-right">
                                {{ spend(c.spend_total) }}
                            </td>
                            <td class="num px-3 py-3 text-ink-3">
                                {{
                                    c.last_spend_day
                                        ? niceDate(c.last_spend_day, false)
                                        : '—'
                                }}
                            </td>
                            <td class="px-5 py-3">
                                <div
                                    v-if="c.client_id"
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span>
                                        <span class="font-semibold">{{
                                            c.client
                                        }}</span>
                                        <span
                                            class="block text-[11.5px] text-ink-3"
                                            >{{
                                                t('{date} থেকে', {
                                                    date: niceDate(
                                                        c.bill_from ?? '',
                                                        false,
                                                    ),
                                                })
                                            }}</span
                                        >
                                    </span>
                                    <KButton
                                        v-if="can['clients.edit']"
                                        size="sm"
                                        variant="quiet"
                                        @click="startLink(c)"
                                        >{{ t('বদলান') }}</KButton
                                    >
                                </div>
                                <div
                                    v-else-if="can['clients.edit']"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <KButton
                                        v-if="c.suggested"
                                        size="sm"
                                        @click="startLink(c, c.suggested.id)"
                                        >{{ c.suggested.name }} ?</KButton
                                    >
                                    <KButton
                                        size="sm"
                                        variant="quiet"
                                        @click="startLink(c)"
                                        >{{ t('Client বাছুন') }}</KButton
                                    >
                                </div>
                                <KTag v-else tone="wait">{{
                                    t('Client বাছা হয়নি')
                                }}</KTag>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <KPager :page="campaigns" />
        </section>
    </template>

    <KModal v-model="linkOpen" :title="editing?.name ?? 'Campaign'">
        <div class="grid gap-4">
            <KField label="Client">
                <KSelect
                    v-model="linkClient"
                    :options="options.clients"
                    :placeholder="t('কোন client-এর?')"
                />
            </KField>
            <KField
                :label="t('কোন দিন থেকে খরচ এই client-এর হিসাবে যাবে')"
                :hint="
                    t(
                        'এর আগের খরচ যদি হাতে লেখা থাকে, তার পরের দিন দিন — তাহলে দুইবার যোগ হবে না।',
                    )
                "
            >
                <KDate v-model="linkFrom" :max="today()" />
            </KField>
        </div>
        <template #footer>
            <KButton
                v-if="editing?.client_id"
                variant="quiet"
                class="mr-auto"
                @click="unlink"
                >{{ t('যুক্ত সরান') }}</KButton
            >
            <KButton variant="quiet" @click="linkOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                :loading="saving"
                :disabled="!linkClient || !linkFrom"
                @click="saveLink"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <KModal v-model="bulkOpen" :title="t('নাম দেখে যুক্ত করুন')">
        <p class="text-[13.5px] text-ink-2">
            {{
                t(
                    'যে campaign-এর নামে ঠিক একজন client-এর নাম আছে, সেগুলো সেই client-এর সাথে যুক্ত হবে।',
                )
            }}
        </p>
        <KField :label="t('কোন দিন থেকে খরচ হিসাবে যাবে')" class="mt-4">
            <KDate v-model="bulkFrom" :max="today()" />
        </KField>
        <template #footer>
            <KButton variant="quiet" @click="bulkOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton variant="primary" @click="linkSuggested">{{
                t('যুক্ত করুন')
            }}</KButton>
        </template>
    </KModal>
</template>
