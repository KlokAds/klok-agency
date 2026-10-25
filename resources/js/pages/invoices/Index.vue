<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import { useFilters } from '@/composables/useFilters';
import { ago, money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    uid: string;
    number: string;
    client_id: number;
    client: string | null;
    issue_date: string;
    due_date: string | null;
    status: string;
    overdue: boolean;
    total: number;
    paid: number;
    due: number;
};

type Sms = {
    id: number;
    provider: string;
    amount: number | null;
    trx_id: string | null;
    payer: string | null;
    body: string;
    at: string;
};

const props = defineProps<{
    invoices: Paginated<Row>;
    filters: { q: string | null; status: string | null; client: number | null };
    totals: {
        unpaid: number;
        overdue: number;
        overdue_count: number;
        paid_month: number;
        claims: number;
    };
    sms: Sms[] | null;
    options: { clients: Option[]; statuses: Option[]; open: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);

const { filters } = useFilters('/invoices', {
    q: props.filters.q,
    status: props.filters.status ?? '',
    client: props.filters.client,
});

const statuses = computed<Option[]>(() => [
    { value: '', label: t('সব') },
    { value: 'unpaid', label: t('বাকি') },
    { value: 'overdue', label: t('মেয়াদ পার') },
    { value: 'sent', label: 'Sent' },
    { value: 'partial', label: 'Partial' },
    { value: 'paid', label: 'Paid' },
    { value: 'void', label: 'Cancel' },
]);

const TONES: Record<string, 'neutral' | 'brand' | 'in' | 'out' | 'wait'> = {
    sent: 'brand',
    partial: 'wait',
    paid: 'in',
    void: 'neutral',
};

const statusLabel = (status: string) =>
    props.options.statuses.find((s) => s.value === status)?.label ?? status;
const bdt = (poisha: number) => money(poisha, 'bdt');

// The Owner matches a wallet SMS that found no invoice by itself.
const assigning = ref<Sms | null>(null);
const assignOpen = ref(false);
const assignTo = ref<number | string | null>(null);

function openAssign(sms: Sms): void {
    assigning.value = sms;
    assignTo.value = null;
    assignOpen.value = true;
}

function assign(): void {
    if (!assigning.value) {
        return;
    }

    router.post(
        `/invoices/sms/${assigning.value.id}/assign`,
        { invoice_id: assignTo.value },
        { preserveScroll: true, onSuccess: () => (assignOpen.value = false) },
    );
}

function ignore(sms: Sms): void {
    router.post(`/invoices/sms/${sms.id}/ignore`, {}, { preserveScroll: true });
}
</script>

<template>
    <PageHeader
        :eyebrow="t('Client ও Ads')"
        title="Invoice"
        :lead="
            t(
                'Client-কে invoice পাঠান — তাঁরা link খুলে bKash, Nagad, Bangla QR বা ব্যাংকে পরিশোধ করবেন, টাকা এলে নিজে থেকেই খাতায় উঠবে।',
            )
        "
    >
        <KButton
            v-if="can['clients.edit']"
            variant="primary"
            icon="plus"
            href="/invoices/create"
            >{{ t('নতুন invoice') }}</KButton
        >
    </PageHeader>

    <section
        class="mb-6 grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-3"
    >
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('পাওনা') }}</p>
            <p class="num mt-2 text-[26px] leading-none font-medium">
                {{ bdt(totals.unpaid) }}
            </p>
            <p v-if="totals.claims" class="mt-2 text-[13px] text-wait">
                {{ t('{n}টা পেমেন্ট যাচাই বাকি', { n: totals.claims }) }}
            </p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('মেয়াদ পার') }}</p>
            <p
                class="num mt-2 text-[26px] leading-none font-medium"
                :class="totals.overdue ? 'text-out' : ''"
            >
                {{ bdt(totals.overdue) }}
            </p>
            <p class="mt-2 text-[13px] text-ink-3">
                {{ t('{n}টা invoice', { n: totals.overdue_count }) }}
            </p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('এই মাসে পাওয়া') }}</p>
            <p class="num mt-2 text-[26px] leading-none font-medium text-in">
                {{ bdt(totals.paid_month) }}
            </p>
        </div>
    </section>

    <section class="panel mb-4 p-4 sm:p-5">
        <div
            class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,280px)]"
        >
            <KInput
                v-model="filters.q"
                :placeholder="t('নম্বর বা client খুঁজুন…')"
                icon="search"
            />
            <KSelect
                v-model="filters.client"
                :options="options.clients"
                placeholder="Client"
                clearable
            />
        </div>
        <div class="mt-3 overflow-x-auto">
            <KSegment v-model="filters.status" :options="statuses" size="sm" />
        </div>
    </section>

    <section class="panel overflow-hidden">
        <KEmpty
            v-if="!invoices.data.length"
            :title="t('কোনো invoice নেই')"
            :body="t('নতুন invoice বানিয়ে client-কে link পাঠান।')"
        />
        <template v-else>
            <div class="hidden overflow-x-auto md:block">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th>{{ t('নম্বর') }}</th>
                            <th>Client</th>
                            <th>{{ t('তারিখ') }}</th>
                            <th>{{ t('শেষ তারিখ') }}</th>
                            <th class="r">{{ t('মোট') }}</th>
                            <th class="r">{{ t('বাকি') }}</th>
                            <th>{{ t('অবস্থা') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in invoices.data" :key="i.id">
                            <td>
                                <Link
                                    :href="`/invoices/${i.uid}`"
                                    class="num font-semibold text-brand-ink hover:underline"
                                    >{{ i.number }}</Link
                                >
                            </td>
                            <td>{{ i.client }}</td>
                            <td class="num">{{ niceDate(i.issue_date) }}</td>
                            <td
                                class="num"
                                :class="i.overdue ? 'text-out' : ''"
                            >
                                {{ i.due_date ? niceDate(i.due_date) : '—' }}
                            </td>
                            <td class="num r">{{ bdt(i.total) }}</td>
                            <td class="num r font-medium">
                                {{ i.due ? bdt(i.due) : '—' }}
                            </td>
                            <td>
                                <KTag :tone="TONES[i.status] ?? 'neutral'">{{
                                    statusLabel(i.status)
                                }}</KTag>
                                <KTag
                                    v-if="i.overdue"
                                    tone="out"
                                    class="ml-1"
                                    >{{ t('মেয়াদ পার') }}</KTag
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul class="divide-y divide-dashed divide-line md:hidden">
                <li v-for="i in invoices.data" :key="i.id">
                    <Link
                        :href="`/invoices/${i.uid}`"
                        class="flex items-center justify-between gap-3 px-4 py-3.5"
                    >
                        <span class="min-w-0">
                            <span class="num block font-semibold">{{
                                i.number
                            }}</span>
                            <span
                                class="block truncate text-[13.5px] text-ink-3"
                                >{{ i.client }} ·
                                {{ niceDate(i.issue_date, false) }}</span
                            >
                        </span>
                        <span class="shrink-0 text-right">
                            <span class="num block font-medium">{{
                                bdt(i.due || i.total)
                            }}</span>
                            <KTag :tone="TONES[i.status] ?? 'neutral'">{{
                                statusLabel(i.status)
                            }}</KTag>
                        </span>
                    </Link>
                </li>
            </ul>
        </template>
        <KPager :page="invoices" :sizes="[10, 25, 50, 100]" />
    </section>

    <section v-if="sms && sms.length" class="panel mt-6 overflow-hidden">
        <div class="border-b border-line px-5 py-4">
            <h2 class="text-[16px] font-bold">{{ t('মেলেনি এমন SMS') }}</h2>
            <p class="mt-0.5 text-[13.5px] text-ink-3">
                {{
                    t(
                        'Wallet-এ টাকা এসেছে কিন্তু কোনো invoice-এর সাথে নিজে থেকে মেলেনি। কোন invoice-এর টাকা, বেছে দিন।',
                    )
                }}
            </p>
        </div>
        <ul class="divide-y divide-dashed divide-line">
            <li
                v-for="s in sms"
                :key="s.id"
                class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2">
                        <KTag tone="brand">{{ s.provider }}</KTag>
                        <span class="num font-semibold">{{
                            s.amount !== null
                                ? money(s.amount, 'bdt', { round: false })
                                : '—'
                        }}</span>
                        <span class="num text-[13px] text-ink-3"
                            >{{ s.trx_id }} · {{ s.payer ?? '—' }} ·
                            {{ ago(s.at) }}</span
                        >
                    </p>
                    <p
                        class="mt-1 line-clamp-2 text-[13px] break-words text-ink-3"
                    >
                        {{ s.body }}
                    </p>
                </div>
                <div class="grid shrink-0 grid-cols-2 gap-2 sm:flex">
                    <KButton size="sm" @click="ignore(s)">{{
                        t('বাদ দিন')
                    }}</KButton>
                    <KButton
                        size="sm"
                        variant="primary"
                        @click="openAssign(s)"
                        >{{ t('Invoice বাছুন') }}</KButton
                    >
                </div>
            </li>
        </ul>
    </section>

    <KModal v-model="assignOpen" :title="t('এই SMS কোন invoice-এর?')">
        <p v-if="assigning" class="mb-4 text-[14.5px] text-ink-2">
            {{ assigning.provider }} ·
            <span class="num font-semibold">{{
                assigning.amount !== null
                    ? money(assigning.amount, 'bdt', { round: false })
                    : '—'
            }}</span>
            · {{ assigning.trx_id }}
        </p>
        <KField label="Invoice">
            <KSelect
                v-model="assignTo"
                :options="options.open"
                :placeholder="t('Invoice বাছুন')"
            />
        </KField>
        <template #footer>
            <KButton variant="quiet" @click="assignOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton variant="primary" :disabled="!assignTo" @click="assign">{{
                t('জমা করুন')
            }}</KButton>
        </template>
    </KModal>
</template>
