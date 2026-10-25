<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KMoney from '@/components/k/KMoney.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { useFilters } from '@/composables/useFilters';
import { confirmAction } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import { money, niceDate, toInput, today } from '@/lib/format';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    date: string | null;
    description: string;
    category: string;
    category_label: string;
    account: string;
    account_id: number;
    account_kind: string;
    client: string | null;
    client_id: number | null;
    amount: number;
    status: string;
    notes: string | null;
    review_note: string | null;
    card_balance: number | null;
    fifo_cost: number | null;
    fifo_rate: number | null;
    locked: boolean;
    paired: boolean;
};

const props = defineProps<{
    entries: Paginated<Row>;
    filters: Record<string, string | null>;
    totals: { in: number; out: number; count: number };
    summary: { card: number };
    options: {
        categories: Option[];
        accounts: Option[];
        clients: Option[];
        statuses: Option[];
    };
}>();

const can = computed(() => usePage().props.auth.can);

const { filters, clear } = useFilters('/usd', {
    q: props.filters.q ?? null,
    category: props.filters.category ?? null,
    account_id: props.filters.account_id ?? null,
    client_id: props.filters.client_id ?? null,
    status: props.filters.status ?? null,
    from: props.filters.from ?? null,
    to: props.filters.to ?? null,
    order: props.filters.order ?? 'new',
});

const filtered = computed(() =>
    Object.entries(filters).some(([k, v]) => k !== 'order' && v),
);

const orderOptions = computed<Option[]>(() => [
    { value: 'new', label: t('নতুন আগে') },
    { value: 'old', label: t('পুরনো আগে') },
]);

const editableCategories = computed(() =>
    props.options.categories.filter((c) => c.value !== 'deposit'),
);
const statusOptions = computed(() =>
    props.options.statuses.filter((s) => s.value !== 'na'),
);
const directions = computed<Option[]>(() => [
    { value: 'in', label: t('ঢুকেছে (+)') },
    { value: 'out', label: t('বেরিয়েছে (−)') },
]);

const tone = (category: string) =>
    ({
        deposit: 'brand',
        client_spend: 'neutral',
        business_spend: 'out',
        card_charge: 'wait',
        transfer: 'neutral',
        adjustment: 'neutral',
    })[category] as 'brand' | 'neutral' | 'out' | 'wait';

// Add / edit
const editing = ref<Row | null>(null);
const open = ref(false);
const form = useForm({
    entry_date: today() as string | null,
    description: '',
    category: 'client_spend',
    account_id: null as number | null,
    client_id: null as number | null,
    amount: '' as string | null,
    direction: 'out',
    status: 'pending',
    notes: '' as string | null,
});

function startNew(): void {
    editing.value = null;
    form.defaults({
        entry_date: today(),
        description: '',
        category: 'client_spend',
        account_id: null,
        client_id: null,
        amount: '',
        direction: 'out',
        status: 'pending',
        notes: '',
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function startEdit(row: Row): void {
    editing.value = row;
    form.clearErrors();
    Object.assign(form, {
        entry_date: row.date,
        description: row.description,
        category: row.category,
        account_id: row.account_id,
        client_id: row.client_id,
        amount: toInput(row.amount),
        direction: row.amount >= 0 ? 'in' : 'out',
        status: row.status === 'na' ? 'pending' : row.status,
        notes: row.notes ?? '',
    });
    open.value = true;
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        form.put(`/usd/${editing.value.id}`, options);
    } else {
        form.post('/usd', options);
    }
}

async function remove(row: Row): Promise<void> {
    const ok = await confirmAction({
        title: t('Entry মুছবেন?'),
        body: `${t('"{description}" ({amount}) Trash-এ যাবে।', { description: row.description, amount: money(row.amount, 'usd') })}${row.paired ? ` ${t('Transfer-এর দুই দিকই যাবে।')}` : ''} ${t('পরে ফেরত আনা যাবে।')}`,
        confirm: t('Trash-এ পাঠান'),
        danger: true,
    });

    if (ok) {
        router.delete(`/usd/${row.id}`, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });
    }
}

const needsDirection = computed(() =>
    ['transfer', 'adjustment'].includes(form.category),
);
</script>

<template>
    <SectionTabs section="ledgers" />
    <PageHeader
        :eyebrow="t('খাতা')"
        :title="t('USD খাতা')"
        :lead="
            t(
                'প্রতিটা ডলারের লেনদেন — মিলিয়ে দেখা আর ভুল ঠিক করার জন্য। Campaign-এর খরচ Pilot থেকে নিজে আসে; USD কেনা আর card-এর কাটা Dollar ও Card পাতা থেকে।',
            )
        "
    >
    </PageHeader>

    <!-- Filters -->
    <section
        class="panel no-print mb-4 grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_repeat(4,1fr)] lg:p-4"
    >
        <KInput
            v-model="filters.q"
            type="search"
            icon="search"
            :placeholder="t('খুঁজুন — বিবরণ, note, client')"
            size="sm"
        />
        <KSelect
            v-model="filters.category"
            :options="options.categories"
            :placeholder="t('সব ধরন')"
            clearable
            size="sm"
        />
        <KSelect
            v-model="filters.account_id"
            :options="options.accounts"
            :placeholder="t('সব account')"
            clearable
            size="sm"
        />
        <KSelect
            v-model="filters.client_id"
            :options="options.clients"
            :placeholder="t('সব client')"
            clearable
            size="sm"
        />
        <KSelect
            v-model="filters.status"
            :options="options.statuses"
            :placeholder="t('সব status')"
            clearable
            size="sm"
        />
        <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-2">
            <KDate
                v-model="filters.from"
                :placeholder="t('শুরু')"
                clearable
                size="sm"
                class="flex-1"
            />
            <span class="text-ink-3">—</span>
            <KDate
                v-model="filters.to"
                :placeholder="t('শেষ')"
                clearable
                size="sm"
                class="flex-1"
            />
        </div>
        <div
            class="flex items-center gap-3 sm:col-span-2 lg:col-span-3 lg:justify-end"
        >
            <KSegment
                v-model="filters.order"
                :options="orderOptions"
                size="sm"
            />
            <button
                v-if="filtered"
                type="button"
                class="text-[13px] font-semibold text-ink-2 hover:text-out"
                @click="clear"
            >
                {{ t('Filter মুছুন') }}
            </button>
        </div>
    </section>

    <!-- Totals for what is shown -->
    <div class="mb-4 flex flex-wrap gap-x-8 gap-y-2 px-1 text-[13px]">
        <span class="text-ink-3"
            ><b class="num text-ink">{{ totals.count }}</b> row</span
        >
        <span class="text-ink-3"
            >{{ t('ঢুকেছে') }}
            <b class="num text-in">{{ money(totals.in, 'usd') }}</b></span
        >
        <span class="text-ink-3"
            >{{ t('বেরিয়েছে') }}
            <b class="num text-out">{{ money(totals.out, 'usd') }}</b></span
        >
        <span class="ml-auto text-ink-3"
            >Card balance
            <b class="num text-ink">{{ money(summary.card, 'usd') }}</b></span
        >
    </div>

    <section class="panel overflow-hidden">
        <KEmpty
            v-if="!entries.data.length"
            :title="t('কোনো row নেই')"
            :body="
                filtered
                    ? t('এই filter-এ কিছু মেলেনি।')
                    : t('প্রথম entry দিয়ে শুরু করুন।')
            "
        />

        <template v-else>
            <!-- Desktop ledger -->
            <div class="hidden overflow-x-auto lg:block">
                <table v-stack class="stack ledger">
                    <thead>
                        <tr>
                            <th class="w-[92px]">{{ t('তারিখ') }}</th>
                            <th>{{ t('বিবরণ') }}</th>
                            <th>Account</th>
                            <th class="r">{{ t('পরিমাণ') }}</th>
                            <th>Status</th>
                            <th class="r">Card balance</th>
                            <th class="r">FIFO cost</th>
                            <th class="w-[76px]" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in entries.data"
                            :key="row.id"
                            class="group"
                        >
                            <td
                                class="num text-[12.5px] whitespace-nowrap text-ink-2"
                            >
                                {{ row.date ? niceDate(row.date) : '—' }}
                            </td>
                            <td class="min-w-[260px]">
                                <p class="font-medium">{{ row.description }}</p>
                                <p
                                    class="mt-1 flex flex-wrap items-center gap-1.5"
                                >
                                    <KTag :tone="tone(row.category)">{{
                                        row.category_label
                                    }}</KTag>
                                    <KTag v-if="row.client" tone="brand">{{
                                        row.client
                                    }}</KTag>
                                    <KTag v-if="row.review_note" tone="wait">{{
                                        t('মিলিয়ে দেখুন')
                                    }}</KTag>
                                    <span
                                        v-if="row.notes"
                                        class="text-[12px] text-ink-3"
                                        >{{ row.notes }}</span
                                    >
                                </p>
                            </td>
                            <td
                                class="text-[13px] whitespace-nowrap text-ink-2"
                            >
                                {{ row.account }}
                            </td>
                            <td class="r">
                                <KAmount
                                    :value="row.amount"
                                    currency="usd"
                                    sign
                                    class="font-medium"
                                />
                            </td>
                            <td>
                                <KTag
                                    v-if="row.status === 'pending'"
                                    tone="wait"
                                    >Pending</KTag
                                >
                                <KTag
                                    v-else-if="row.status === 'settled'"
                                    tone="in"
                                    >Settled</KTag
                                >
                                <span v-else class="text-ink-3">—</span>
                            </td>
                            <td class="r num text-ink-2">
                                {{
                                    row.card_balance !== null
                                        ? money(row.card_balance, 'usd')
                                        : ''
                                }}
                            </td>
                            <td class="r">
                                <template v-if="row.fifo_cost !== null">
                                    <span class="num">{{
                                        money(row.fifo_cost, 'bdt')
                                    }}</span>
                                    <span
                                        class="num block text-[11.5px] text-ink-3"
                                        >@ {{ row.fifo_rate }}</span
                                    >
                                </template>
                            </td>
                            <td class="r whitespace-nowrap">
                                <span
                                    v-if="row.locked"
                                    class="inline-flex text-ink-3"
                                    :title="t('FX Batch পাতা থেকে বদলান')"
                                    ><KIcon name="lock" :size="16"
                                /></span>
                                <template v-else>
                                    <button
                                        v-if="can['usd.edit']"
                                        type="button"
                                        class="rounded-md p-1.5 text-ink-3 opacity-0 group-hover:opacity-100 hover:bg-sunken hover:text-ink focus:opacity-100"
                                        :aria-label="t('বদলান')"
                                        @click="startEdit(row)"
                                    >
                                        <KIcon name="pen" :size="16" />
                                    </button>
                                    <button
                                        v-if="can['usd.delete']"
                                        type="button"
                                        class="rounded-md p-1.5 text-ink-3 opacity-0 group-hover:opacity-100 hover:bg-out-soft hover:text-out focus:opacity-100"
                                        :aria-label="t('মুছুন')"
                                        @click="remove(row)"
                                    >
                                        <KIcon name="bin" :size="16" />
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Phone list -->
            <ul class="divide-y divide-dashed divide-line lg:hidden">
                <li v-for="row in entries.data" :key="row.id">
                    <button
                        type="button"
                        class="flex w-full items-start gap-3 px-4 py-3.5 text-left"
                        :disabled="row.locked || !can['usd.edit']"
                        @click="startEdit(row)"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{
                                row.description
                            }}</span>
                            <span
                                class="mt-1 flex flex-wrap items-center gap-1.5 text-[12px] text-ink-3"
                            >
                                {{ niceDate(row.date) }} · {{ row.account }}
                                <KTag v-if="row.client" tone="brand">{{
                                    row.client
                                }}</KTag>
                                <KTag
                                    v-if="row.status === 'pending'"
                                    tone="wait"
                                    >Pending</KTag
                                >
                            </span>
                        </span>
                        <span class="text-right">
                            <KAmount
                                :value="row.amount"
                                currency="usd"
                                sign
                                class="block font-semibold"
                            />
                            <span
                                v-if="row.fifo_cost !== null"
                                class="num text-[11.5px] text-ink-3"
                                >{{ money(row.fifo_cost, 'bdt') }}</span
                            >
                        </span>
                    </button>
                </li>
            </ul>

            <KPager :page="entries" :sizes="[10, 25, 50, 100]" />
        </template>
    </section>

    <!-- Add / edit -->
    <KModal
        v-model="open"
        :title="editing ? t('USD row বদলান') : t('নতুন USD row')"
        :subtitle="
            editing?.paired
                ? t('এটা transfer — অন্য দিকের row-ও একসাথে বদলাবে।')
                : t('সাধারণ কাজের জন্য উপরের “নতুন entry” সহজ।')
        "
    >
        <form
            id="usd-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField :label="t('তারিখ')" :error="form.errors.entry_date">
                <KDate
                    v-model="form.entry_date"
                    :invalid="!!form.errors.entry_date"
                />
            </KField>
            <KField :label="t('ধরন')" :error="form.errors.category">
                <KSelect
                    v-model="form.category"
                    :options="editableCategories"
                    :disabled="editing?.paired"
                />
            </KField>
            <KField
                :label="t('বিবরণ')"
                :error="form.errors.description"
                class="sm:col-span-2"
            >
                <KInput
                    v-model="form.description"
                    :invalid="!!form.errors.description"
                />
            </KField>
            <KField label="Account" :error="form.errors.account_id">
                <KSelect
                    v-model="form.account_id"
                    :options="options.accounts"
                    :invalid="!!form.errors.account_id"
                />
            </KField>
            <KField
                v-if="form.category === 'client_spend'"
                label="Client"
                :error="form.errors.client_id"
            >
                <KSelect
                    v-model="form.client_id"
                    :options="options.clients"
                    :invalid="!!form.errors.client_id"
                />
            </KField>
            <KField :label="t('পরিমাণ')" :error="form.errors.amount">
                <KMoney
                    v-model="form.amount"
                    currency="usd"
                    :invalid="!!form.errors.amount"
                />
            </KField>
            <KField v-if="needsDirection" :label="t('দিক')">
                <KSegment v-model="form.direction" :options="directions" />
            </KField>
            <KField v-if="form.category === 'client_spend'" label="Status">
                <KSegment v-model="form.status" :options="statusOptions" />
            </KField>
            <KField label="Note" optional class="sm:col-span-2">
                <KTextarea v-model="form.notes" />
            </KField>
        </form>
        <template #footer>
            <KButton
                v-if="editing && can['usd.delete']"
                variant="danger"
                icon="bin"
                class="mr-auto"
                @click="remove(editing)"
                >{{ t('মুছুন') }}</KButton
            >
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="usd-form"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>
</template>
