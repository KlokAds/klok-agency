<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { money, niceDate, toInput } from '@/lib/format';
import { t } from '@/lib/i18n';
import { openQuickEntry } from '@/lib/quick-entry';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    date: string | null;
    description: string;
    category: string;
    category_label: string;
    client_id: number | null;
    client: string | null;
    expense_head_id: number | null;
    head: string | null;
    pay_via: string | null;
    pay_via_label: string | null;
    wallet: string | null;
    wallet_label: string | null;
    amount: number;
    usd_credit: number | null;
    notes: string | null;
    locked: boolean;
};

type Head = { id: number; name: string; is_active: boolean };

// One page for money in (client payments) and one for money out (expenses),
// cut from the BDT book with their own filters and totals.
const props = defineProps<{
    mode: 'in' | 'out';
    rows: Paginated<Row>;
    totals: { amount: number; count: number; credit_usd?: number };
    by_client?: { client: string; total: number; count: number }[];
    by_head?: { head: string; total: number; count: number }[];
    heads?: Head[];
    filters: Record<string, string | number | null>;
    options: {
        clients?: Option[];
        heads?: Option[];
        wallets: Option[];
        pay_via?: Option[];
        kinds?: Option[];
        categories?: Option[];
    };
}>();

const can = computed(() => usePage().props.auth.can);
const isIn = computed(() => props.mode === 'in');

const { filters, clear } = useFilters(
    isIn.value ? '/payments' : '/expenses',
    isIn.value
        ? {
              q: (props.filters.q as string) ?? null,
              client_id: props.filters.client_id ?? null,
              pay_via: props.filters.pay_via ?? null,
              wallet: props.filters.wallet ?? null,
              from: (props.filters.from as string) ?? null,
              to: (props.filters.to as string) ?? null,
          }
        : {
              q: (props.filters.q as string) ?? null,
              expense_head_id: props.filters.expense_head_id ?? null,
              kind: props.filters.kind ?? null,
              wallet: props.filters.wallet ?? null,
              from: (props.filters.from as string) ?? null,
              to: (props.filters.to as string) ?? null,
          },
);
const filtered = computed(() => Object.values(filters).some((v) => v));

const breakdown = computed(() =>
    isIn.value
        ? (props.by_client ?? []).map((r) => ({ name: r.client, ...r }))
        : (props.by_head ?? []).map((r) => ({ name: r.head, ...r })),
);
const breakdownMax = computed(() =>
    Math.max(1, ...breakdown.value.map((r) => Math.abs(r.total))),
);

// Edit one row with the BDT book's own rules.
const editing = ref<Row | null>(null);
const open = ref(false);
const form = useForm({
    entry_date: null as string | null,
    description: '',
    category: '',
    client_id: null as number | null,
    expense_head_id: null as number | null,
    pay_via: null as string | null,
    wallet: null as string | null,
    amount: '' as string | null,
    notes: '' as string | null,
});

function startEdit(row: Row): void {
    editing.value = row;
    form.clearErrors();
    Object.assign(form, {
        entry_date: row.date,
        description: row.description,
        category: row.category,
        client_id: row.client_id,
        expense_head_id: row.expense_head_id,
        pay_via: row.pay_via ?? 'mfs',
        wallet: row.wallet,
        amount: toInput(row.amount),
        notes: row.notes ?? '',
    });
    open.value = true;
}

function save(): void {
    if (!editing.value) {
        return;
    }

    form.put(`/bdt/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

async function remove(row: Row): Promise<void> {
    const ok = await confirmAction({
        title: t('Entry Trash-এ পাঠাবেন?'),
        body: `"${row.description}" (${money(row.amount, 'bdt', { round: false })})`,
        confirm: t('Trash-এ পাঠান'),
        danger: true,
    });

    if (ok) {
        router.delete(`/bdt/${row.id}`, { preserveScroll: true });
    }
}

// Expense heads, kept with the expenses they sort.
const headForm = useForm({ name: '' });
const renaming = ref<Head | null>(null);
const renameTo = ref('');

function addHead(): void {
    headForm.post('/expenses/heads', {
        preserveScroll: true,
        onSuccess: () => headForm.reset(),
    });
}

function toggleHead(h: Head): void {
    router.put(
        `/expenses/heads/${h.id}`,
        { name: h.name, is_active: !h.is_active },
        { preserveScroll: true },
    );
}

function saveRename(): void {
    if (!renaming.value) {
        return;
    }

    router.put(
        `/expenses/heads/${renaming.value.id}`,
        { name: renameTo.value, is_active: renaming.value.is_active },
        { preserveScroll: true, onSuccess: () => (renaming.value = null) },
    );
}
</script>

<template>
    <PageHeader
        :eyebrow="t('টাকা')"
        :title="isIn ? t('Payment (আয়)') : t('খরচ')"
        :lead="
            isIn
                ? t(
                      'Client-দের কাছ থেকে যত টাকা এসেছে — কে, কবে, কীভাবে, কোথায়। পুরো BDT খাতা BDT খাতা পাতায়।',
                  )
                : t(
                      'ব্যবসা চালাতে যত টাকা গেছে — খাত ধরে, জায়গা ধরে। পুরো BDT খাতা BDT খাতা পাতায়।',
                  )
        "
    >
        <KButton
            v-if="can['bdt.create']"
            variant="ink"
            icon="plus"
            @click="openQuickEntry(isIn ? 'client-payment' : 'expense')"
            >{{ isIn ? t('নতুন payment') : t('নতুন খরচ') }}</KButton
        >
    </PageHeader>

    <!-- Totals -->
    <section
        class="mb-4 grid gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-3"
    >
        <div class="bg-surface p-5">
            <p class="eyebrow">
                {{ filtered ? t('এই filter-এ মোট') : t('মোট') }}
            </p>
            <p
                class="num mt-2 text-[28px] leading-none font-medium"
                :class="isIn ? 'text-in' : 'text-out'"
            >
                {{ money(Math.abs(totals.amount), 'bdt', { round: false }) }}
            </p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('কয়টা entry') }}</p>
            <p class="num mt-2 text-[28px] leading-none font-medium">
                {{ totals.count }}
            </p>
        </div>
        <div v-if="isIn" class="bg-surface p-5">
            <p class="eyebrow">{{ t('কেনা ad credit') }}</p>
            <p class="num mt-2 text-[28px] leading-none font-medium">
                {{ money(totals.credit_usd ?? 0, 'usd') }}
            </p>
        </div>
        <div v-else class="bg-surface p-5">
            <p class="eyebrow">{{ t('সবচেয়ে বেশি') }}</p>
            <p class="mt-2 truncate text-[18px] font-semibold">
                {{ breakdown[0]?.name ?? '—' }}
            </p>
        </div>
    </section>

    <!-- Filters -->
    <section
        class="panel no-print mb-4 grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_repeat(3,1fr)] lg:p-4"
    >
        <KInput
            v-model="filters.q"
            type="search"
            icon="search"
            :placeholder="t('খুঁজুন — বিবরণ, note')"
            size="sm"
        />
        <template v-if="isIn">
            <KSelect
                v-model="filters.client_id"
                :options="options.clients ?? []"
                :placeholder="t('সব client')"
                clearable
                size="sm"
            />
            <KSelect
                v-model="filters.pay_via"
                :options="options.pay_via ?? []"
                :placeholder="t('সব পদ্ধতি')"
                clearable
                :searchable="false"
                size="sm"
            />
        </template>
        <template v-else>
            <KSelect
                v-model="filters.expense_head_id"
                :options="options.heads ?? []"
                :placeholder="t('সব খরচের খাত')"
                clearable
                size="sm"
            />
            <KSelect
                v-model="filters.kind"
                :options="options.kinds ?? []"
                :placeholder="t('খরচ ও বেতন')"
                clearable
                :searchable="false"
                size="sm"
            />
        </template>
        <KSelect
            v-model="filters.wallet"
            :options="options.wallets"
            :placeholder="t('সব জায়গা')"
            clearable
            :searchable="false"
            size="sm"
        />
        <div class="flex items-center gap-2 sm:col-span-2">
            <KDate
                v-model="filters.from"
                :placeholder="t('শুরু')"
                clearable
                size="sm"
                class="min-w-0 flex-1"
            />
            <span class="text-ink-3">—</span>
            <KDate
                v-model="filters.to"
                :placeholder="t('শেষ')"
                clearable
                size="sm"
                class="min-w-0 flex-1"
            />
        </div>
        <div class="flex items-center sm:col-span-2 lg:justify-end">
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

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <!-- Rows -->
        <section class="panel min-w-0 overflow-hidden">
            <KEmpty
                v-if="!rows.data.length"
                :title="t('কিছু পাওয়া যায়নি')"
                :body="t('অন্য filter দেখুন।')"
            />
            <ul v-else>
                <li
                    v-for="r in rows.data"
                    :key="r.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-line px-4 py-3 text-[13.5px] last:border-0 sm:flex-nowrap sm:px-5"
                >
                    <span
                        class="num w-full shrink-0 text-[12px] text-ink-3 sm:w-20"
                        >{{
                            r.date ? niceDate(r.date, false) : t('তারিখ নেই')
                        }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            r.description
                        }}</span>
                        <span
                            class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[12px] text-ink-3"
                        >
                            <span>{{
                                isIn
                                    ? (r.client ?? r.category_label)
                                    : (r.head ?? r.category_label)
                            }}</span>
                            <KTag v-if="r.pay_via_label" tone="neutral">{{
                                r.pay_via_label
                            }}</KTag>
                            <KTag v-if="r.wallet_label" tone="neutral">{{
                                r.wallet_label
                            }}</KTag>
                            <span v-if="r.usd_credit" class="num"
                                >· {{ money(r.usd_credit, 'usd') }} credit</span
                            >
                        </span>
                    </span>
                    <span
                        class="num shrink-0 font-semibold"
                        :class="isIn ? 'text-in' : 'text-out'"
                        >{{
                            money(Math.abs(r.amount), 'bdt', { round: false })
                        }}</span
                    >
                    <span
                        v-if="
                            !r.locked && (can['bdt.edit'] || can['bdt.delete'])
                        "
                        class="flex shrink-0 gap-1"
                    >
                        <button
                            v-if="can['bdt.edit']"
                            type="button"
                            class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-sunken hover:text-ink"
                            :aria-label="t('বদলান')"
                            @click="startEdit(r)"
                        >
                            <KIcon name="pen" :size="15" />
                        </button>
                        <button
                            v-if="can['bdt.delete']"
                            type="button"
                            class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-out-soft hover:text-out"
                            :aria-label="t('মুছুন')"
                            @click="remove(r)"
                        >
                            <KIcon name="bin" :size="15" />
                        </button>
                    </span>
                </li>
            </ul>
            <KPager :page="rows" :sizes="[10, 25, 50, 100]" />
        </section>

        <aside class="flex min-w-0 flex-col gap-6">
            <!-- Breakdown -->
            <section class="panel p-5">
                <h2 class="text-[15px] font-bold">
                    {{ isIn ? t('Client ধরে') : t('খাত ধরে') }}
                </h2>
                <p v-if="!breakdown.length" class="mt-3 text-[13px] text-ink-3">
                    —
                </p>
                <ul v-else class="mt-3 flex flex-col gap-3">
                    <li v-for="b in breakdown" :key="b.name">
                        <div class="flex justify-between gap-3 text-[13px]">
                            <span class="truncate">{{ b.name }}</span>
                            <span class="num shrink-0 font-semibold">{{
                                money(Math.abs(b.total), 'bdt')
                            }}</span>
                        </div>
                        <div class="mt-1 h-1.5 rounded-full bg-sunken">
                            <div
                                class="h-1.5 rounded-full"
                                :class="isIn ? 'bg-in' : 'bg-out'"
                                :style="{
                                    width:
                                        (Math.abs(b.total) / breakdownMax) *
                                            100 +
                                        '%',
                                }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Expense heads -->
            <section v-if="!isIn && heads" class="panel p-5">
                <h2 class="text-[15px] font-bold">{{ t('খরচের খাত') }}</h2>
                <p class="mt-1 text-[12.5px] text-ink-3">
                    {{
                        t(
                            'খরচ লেখার সময় এগুলো থেকে বাছা যায়। বন্ধ করলে নতুন entry-তে দেখাবে না।',
                        )
                    }}
                </p>
                <ul class="mt-3">
                    <li
                        v-for="h in heads"
                        :key="h.id"
                        class="flex items-center gap-2 border-b border-line py-2 text-[13px] last:border-0"
                    >
                        <template v-if="renaming?.id === h.id">
                            <KInput
                                v-model="renameTo"
                                size="sm"
                                class="flex-1"
                            />
                            <KButton size="sm" @click="saveRename">{{
                                t('সংরক্ষণ')
                            }}</KButton>
                        </template>
                        <template v-else>
                            <span
                                class="min-w-0 flex-1 truncate"
                                :class="
                                    h.is_active ? '' : 'text-ink-3 line-through'
                                "
                                >{{ h.name }}</span
                            >
                            <template v-if="can['bdt.edit']">
                                <button
                                    type="button"
                                    class="text-[12px] font-semibold text-ink-3 hover:text-ink"
                                    @click="
                                        renaming = h;
                                        renameTo = h.name;
                                    "
                                >
                                    {{ t('নাম বদলান') }}
                                </button>
                                <button
                                    type="button"
                                    class="text-[12px] font-semibold"
                                    :class="
                                        h.is_active
                                            ? 'text-ink-3 hover:text-out'
                                            : 'text-brand-ink'
                                    "
                                    @click="toggleHead(h)"
                                >
                                    {{ h.is_active ? t('বন্ধ') : t('চালু') }}
                                </button>
                            </template>
                        </template>
                    </li>
                </ul>
                <form
                    v-if="can['bdt.edit']"
                    class="mt-3 flex gap-2"
                    @submit.prevent="addHead"
                >
                    <KInput
                        v-model="headForm.name"
                        :placeholder="t('নতুন খাত, যেমন Office rent')"
                        size="sm"
                        class="min-w-0 flex-1"
                        :invalid="!!headForm.errors.name"
                    />
                    <KButton
                        type="submit"
                        size="sm"
                        icon="plus"
                        :loading="headForm.processing"
                        >{{ t('যোগ') }}</KButton
                    >
                </form>
                <p
                    v-if="headForm.errors.name"
                    class="mt-1.5 text-[12.5px] text-out"
                >
                    {{ headForm.errors.name }}
                </p>
            </section>
        </aside>
    </div>

    <KModal v-model="open" :title="t('Entry বদলান')">
        <form
            id="money-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField :label="t('তারিখ')" :error="form.errors.entry_date">
                <KDate v-model="form.entry_date" />
            </KField>
            <KField :label="t('পরিমাণ (BDT)')" :error="form.errors.amount">
                <KMoney v-model="form.amount" currency="bdt" />
            </KField>
            <KField
                :label="t('বিবরণ')"
                :error="form.errors.description"
                class="sm:col-span-2"
            >
                <KInput v-model="form.description" />
            </KField>
            <template v-if="isIn">
                <KField label="Client" :error="form.errors.client_id">
                    <KSelect
                        v-model="form.client_id"
                        :options="options.clients ?? []"
                    />
                </KField>
                <KField :label="t('কীভাবে এলো')" :error="form.errors.pay_via">
                    <KSelect
                        v-model="form.pay_via"
                        :options="options.pay_via ?? []"
                        :searchable="false"
                    />
                </KField>
            </template>
            <KField
                v-else-if="form.category === 'expense'"
                :label="t('খরচের খাত')"
                :error="form.errors.expense_head_id"
            >
                <KSelect
                    v-model="form.expense_head_id"
                    :options="options.heads ?? []"
                />
            </KField>
            <KField :label="t('কোন জায়গায়')" :error="form.errors.wallet">
                <KSegment v-model="form.wallet" :options="options.wallets" />
            </KField>
            <KField label="Note" optional class="sm:col-span-2">
                <KTextarea v-model="form.notes" />
            </KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="money-form"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>
</template>
