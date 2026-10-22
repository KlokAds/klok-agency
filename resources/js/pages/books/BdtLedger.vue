<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KCheck from '@/components/k/KCheck.vue';
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
import { money, niceDate, toInput, today } from '@/lib/format';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    date: string | null;
    description: string;
    category: string;
    category_label: string;
    client: string | null;
    client_id: number | null;
    head: string | null;
    expense_head_id: number | null;
    employee: string | null;
    amount: number;
    notes: string | null;
    review_note: string | null;
    balance: number | null;
    locked: boolean;
};

const props = defineProps<{
    entries: Paginated<Row>;
    filters: Record<string, string | null>;
    totals: { in: number; out: number; count: number };
    summary: { balance: number };
    options: { categories: Option[]; clients: Option[]; heads: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);

const { filters, clear } = useFilters('/bdt', {
    q: props.filters.q ?? null,
    category: props.filters.category ?? null,
    client_id: props.filters.client_id ?? null,
    expense_head_id: props.filters.expense_head_id ?? null,
    from: props.filters.from ?? null,
    to: props.filters.to ?? null,
    order: props.filters.order ?? 'new',
});

const filtered = computed(() => Object.entries(filters).some(([k, v]) => k !== 'order' && v));
const orderOptions: Option[] = [
    { value: 'new', label: 'নতুন আগে' },
    { value: 'old', label: 'পুরনো আগে' },
];

const editableCategories = computed(() => props.options.categories.filter((c) => c.value !== 'usd_purchase'));

const tone = (category: string) =>
    ({ capital: 'brand', owner_drawing: 'wait', usd_purchase: 'neutral', client_payment: 'in', service_income: 'in', other_income: 'in', expense: 'out', salary: 'out' })[category] as
        | 'brand'
        | 'neutral'
        | 'in'
        | 'out'
        | 'wait';

const editing = ref<Row | null>(null);
const open = ref(false);
const form = useForm({
    entry_date: today() as string | null,
    description: '',
    category: 'expense',
    client_id: null as number | null,
    expense_head_id: null as number | null,
    amount: '' as string | null,
    notes: '' as string | null,
    clear_review: false,
});

function startNew(): void {
    editing.value = null;
    form.defaults({ entry_date: today(), description: '', category: 'expense', client_id: null, expense_head_id: null, amount: '', notes: '', clear_review: false });
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
        client_id: row.client_id,
        expense_head_id: row.expense_head_id,
        amount: toInput(row.amount),
        notes: row.notes ?? '',
        clear_review: false,
    });
    open.value = true;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.put(`/bdt/${editing.value.id}`, options) : form.post('/bdt', options);
}

async function remove(row: Row): Promise<void> {
    const ok = await confirmAction({
        title: 'Entry মুছবেন?',
        body: `"${row.description}" (${money(row.amount, 'bdt')}) Trash-এ যাবে। এই টাকায় যে খরচগুলো Settled হয়েছিল, সেগুলো আবার Pending হবে।`,
        confirm: 'Trash-এ পাঠান',
        danger: true,
    });

    if (ok) {
        router.delete(`/bdt/${row.id}`, { preserveScroll: true, onSuccess: () => (open.value = false) });
    }
}

const needsClient = computed(() => ['client_payment', 'service_income'].includes(form.category));
</script>

<template>
    <PageHeader eyebrow="খাতা" title="BDT খাতা" lead="Capital, USD কেনা, client-এর টাকা, খরচ আর বেতন — হাতে আর bank-এ কত টাকা।">
        <KButton v-if="can['reports.export']" href="/reports/export/bdt" external icon="down">CSV</KButton>
        <KButton v-if="can['bdt.create']" variant="ink" icon="plus" @click="startNew">নতুন row</KButton>
    </PageHeader>

    <section class="panel no-print mb-4 grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_repeat(3,1fr)] lg:p-4">
        <KInput v-model="filters.q" type="search" icon="search" placeholder="খুঁজুন — বিবরণ, note, client" size="sm" />
        <KSelect v-model="filters.category" :options="options.categories" placeholder="সব ধরন" clearable size="sm" />
        <KSelect v-model="filters.client_id" :options="options.clients" placeholder="সব client" clearable size="sm" />
        <KSelect v-model="filters.expense_head_id" :options="options.heads" placeholder="সব খরচের খাত" clearable size="sm" />
        <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-2">
            <KDate v-model="filters.from" placeholder="শুরু" clearable size="sm" class="flex-1" />
            <span class="text-ink-3">—</span>
            <KDate v-model="filters.to" placeholder="শেষ" clearable size="sm" class="flex-1" />
        </div>
        <div class="flex items-center gap-3 sm:col-span-2 lg:justify-end">
            <KSegment v-model="filters.order" :options="orderOptions" size="sm" />
            <button v-if="filtered" type="button" class="text-[13px] font-semibold text-ink-2 hover:text-out" @click="clear">Filter মুছুন</button>
        </div>
    </section>

    <div class="mb-4 flex flex-wrap gap-x-8 gap-y-2 px-1 text-[13px]">
        <span class="text-ink-3"><b class="num text-ink">{{ totals.count }}</b> row</span>
        <span class="text-ink-3">ঢুকেছে <b class="num text-in">{{ money(totals.in, 'bdt') }}</b></span>
        <span class="text-ink-3">বেরিয়েছে <b class="num text-out">{{ money(totals.out, 'bdt') }}</b></span>
        <span class="ml-auto text-ink-3">Balance <b class="num text-ink">{{ money(summary.balance, 'bdt') }}</b></span>
    </div>

    <section class="panel overflow-hidden">
        <KEmpty v-if="!entries.data.length" title="কোনো row নেই" :body="filtered ? 'এই filter-এ কিছু মেলেনি।' : 'প্রথম entry দিয়ে শুরু করুন।'" />

        <template v-else>
            <div class="hidden overflow-x-auto lg:block">
                <table class="ledger">
                    <thead>
                        <tr>
                            <th class="w-[92px]">তারিখ</th>
                            <th>বিবরণ</th>
                            <th>কার / কীসের</th>
                            <th class="r">পরিমাণ</th>
                            <th class="r">Balance</th>
                            <th class="w-[76px]" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in entries.data" :key="row.id" class="group">
                            <td class="num text-[12.5px] whitespace-nowrap text-ink-2">{{ row.date ? niceDate(row.date) : '—' }}</td>
                            <td class="min-w-[280px]">
                                <p class="font-medium">{{ row.description }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-1.5">
                                    <KTag :tone="tone(row.category)">{{ row.category_label }}</KTag>
                                    <KTag v-if="row.review_note" tone="wait" :title="row.review_note">মিলিয়ে দেখুন — {{ row.review_note }}</KTag>
                                    <span v-if="row.notes" class="text-[12px] text-ink-3">{{ row.notes }}</span>
                                </p>
                            </td>
                            <td class="text-[13px] whitespace-nowrap text-ink-2">{{ row.client ?? row.employee ?? row.head ?? '—' }}</td>
                            <td class="r"><KAmount :value="row.amount" currency="bdt" sign class="font-medium" /></td>
                            <td class="r num text-ink-2">{{ money(row.balance, 'bdt') }}</td>
                            <td class="r whitespace-nowrap">
                                <span v-if="row.locked" class="inline-flex text-ink-3" :title="row.category === 'salary' ? 'Payroll থেকে বদলান' : 'FX Batch থেকে বদলান'"><KIcon name="lock" :size="16" /></span>
                                <template v-else>
                                    <button v-if="can['bdt.edit']" type="button" class="rounded-md p-1.5 text-ink-3 opacity-0 group-hover:opacity-100 hover:bg-sunken hover:text-ink focus:opacity-100" aria-label="বদলান" @click="startEdit(row)"><KIcon name="pen" :size="16" /></button>
                                    <button v-if="can['bdt.delete']" type="button" class="rounded-md p-1.5 text-ink-3 opacity-0 group-hover:opacity-100 hover:bg-out-soft hover:text-out focus:opacity-100" aria-label="মুছুন" @click="remove(row)"><KIcon name="bin" :size="16" /></button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul class="divide-y divide-dashed divide-line lg:hidden">
                <li v-for="row in entries.data" :key="row.id">
                    <button type="button" class="flex w-full items-start gap-3 px-4 py-3.5 text-left" :disabled="row.locked || !can['bdt.edit']" @click="startEdit(row)">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ row.description }}</span>
                            <span class="mt-1 flex flex-wrap items-center gap-1.5 text-[12px] text-ink-3">
                                {{ niceDate(row.date) }}
                                <KTag :tone="tone(row.category)">{{ row.category_label }}</KTag>
                            </span>
                        </span>
                        <span class="text-right">
                            <KAmount :value="row.amount" currency="bdt" sign class="block font-semibold" />
                            <span class="num text-[11.5px] text-ink-3">{{ money(row.balance, 'bdt') }}</span>
                        </span>
                    </button>
                </li>
            </ul>

            <KPager :page="entries" />
        </template>
    </section>

    <KModal v-model="open" :title="editing ? 'BDT row বদলান' : 'নতুন BDT row'" subtitle="USD কেনা FX Batch থেকে, বেতন Payroll থেকে দিন।">
        <form id="bdt-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="save">
            <KField label="তারিখ" :error="form.errors.entry_date">
                <KDate v-model="form.entry_date" :invalid="!!form.errors.entry_date" />
            </KField>
            <KField label="ধরন" :error="form.errors.category">
                <KSelect v-model="form.category" :options="editableCategories" />
            </KField>
            <KField label="বিবরণ" :error="form.errors.description" class="sm:col-span-2">
                <KInput v-model="form.description" :invalid="!!form.errors.description" />
            </KField>
            <KField v-if="needsClient" label="Client" :error="form.errors.client_id">
                <KSelect v-model="form.client_id" :options="options.clients" :invalid="!!form.errors.client_id" />
            </KField>
            <KField v-if="form.category === 'expense'" label="খরচের খাত" :error="form.errors.expense_head_id">
                <KSelect v-model="form.expense_head_id" :options="options.heads" clearable />
            </KField>
            <KField label="পরিমাণ" :error="form.errors.amount" hint="শুধু সংখ্যা — যোগ না বিয়োগ, তা ধরন থেকে বোঝা যায়">
                <KMoney v-model="form.amount" currency="bdt" :invalid="!!form.errors.amount" />
            </KField>
            <KField label="Note" optional class="sm:col-span-2">
                <KTextarea v-model="form.notes" />
            </KField>
            <div v-if="editing?.review_note" class="rounded-xl bg-wait-soft px-4 py-3 sm:col-span-2">
                <p class="text-[13px] font-medium text-wait">পুরনো sheet থেকে: {{ editing.review_note }}</p>
                <KCheck v-model="form.clear_review" label="দেখেছি, ঠিক আছে — চিহ্ন সরিয়ে দাও" class="mt-2" />
            </div>
        </form>
        <template #footer>
            <KButton v-if="editing && can['bdt.delete']" variant="danger" icon="bin" class="mr-auto" @click="remove(editing)">মুছুন</KButton>
            <KButton variant="quiet" @click="open = false">বাতিল</KButton>
            <KButton type="submit" form="bdt-form" variant="primary" :loading="form.processing">সংরক্ষণ</KButton>
        </template>
    </KModal>
</template>
