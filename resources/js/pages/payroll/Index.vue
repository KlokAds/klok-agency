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
import KMonth from '@/components/k/KMonth.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { useFilters } from '@/composables/useFilters';
import { confirmAction } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import {
    BN_MONTHS,
    initials,
    money,
    monthLabel,
    niceDate,
    toInput,
    today,
} from '@/lib/format';
import type { Option, Paginated } from '@/types';

type Employee = {
    id: number;
    name: string;
    designation: string | null;
    phone: string | null;
    monthly_salary: number;
    joined_on: string | null;
    left_on: string | null;
    status: string;
    notes: string | null;
    paid: number;
    bonus: number;
    due: number;
    months: Record<string, number>;
};

type Payment = {
    id: number;
    date: string | null;
    employee: string | null;
    employee_id: number | null;
    month: string | null;
    kind: string | null;
    kind_label: string;
    amount: number;
    description: string;
    notes: string | null;
};

const props = defineProps<{
    employees: Employee[];
    months: string[];
    payments: Paginated<Payment>;
    filters: { month: string | null; employee_id: number | null };
    paid_months: string[];
    totals: {
        monthly: number;
        due: number;
        paid_this_month: number;
        unassigned: number;
    };
    options: { employees: Option[]; kinds: Option[]; statuses: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);
const thisMonth = today().slice(0, 7);
const TAKA = '\u{09F3}';

const { filters } = useFilters('/payroll', {
    month: props.filters.month,
    employee_id: props.filters.employee_id,
});
const monthOptions = computed<Option[]>(() =>
    props.paid_months.map((m) => ({ value: m, label: monthLabel(m) })),
);
const employeeOptions = computed<Option[]>(() =>
    props.employees.map((e) => ({ value: e.id, label: e.name })),
);

// Pay
const payOpen = ref(false);
const pay = useForm({
    employee_id: null as number | null,
    entry_date: today(),
    salary_month: thisMonth,
    kind: 'salary',
    amount: '' as string | null,
    notes: '' as string | null,
});

function startPay(employee?: Employee): void {
    pay.reset();
    pay.clearErrors();
    pay.employee_id = employee?.id ?? null;
    pay.amount = employee
        ? toInput(
              Math.max(0, Math.min(employee.monthly_salary, employee.due)) ||
                  employee.monthly_salary,
          )
        : '';
    payOpen.value = true;
}

function savePay(): void {
    pay.post('/payroll/pay', {
        preserveScroll: true,
        onSuccess: () => (payOpen.value = false),
    });
}

// Employee add / edit
const empOpen = ref(false);
const editing = ref<Employee | null>(null);
const emp = useForm({
    name: '',
    designation: '',
    phone: '',
    monthly_salary: '' as string | null,
    joined_on: null as string | null,
    left_on: null as string | null,
    status: 'active',
    notes: '' as string | null,
});

function startEmployee(e?: Employee): void {
    editing.value = e ?? null;
    emp.clearErrors();

    if (e) {
        Object.assign(emp, {
            name: e.name,
            designation: e.designation ?? '',
            phone: e.phone ?? '',
            monthly_salary: toInput(e.monthly_salary),
            joined_on: e.joined_on,
            left_on: e.left_on,
            status: e.status,
            notes: e.notes ?? '',
        });
    } else {
        emp.reset();
    }

    empOpen.value = true;
}

function saveEmployee(): void {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (empOpen.value = false),
    };
    if (editing.value) {
        emp.put(`/payroll/employees/${editing.value.id}`, opts);
    } else {
        emp.post('/payroll/employees', opts);
    }
}

async function removeEmployee(e: Employee): Promise<void> {
    if (
        await confirmAction({
            title: t('{name}-কে মুছবেন?', { name: e.name }),
            body: t(
                'যার বেতন দেওয়া আছে, তাকে মোছা যায় না — "Left" করে রাখুন।',
            ),
            confirm: t('Trash-এ পাঠান'),
            danger: true,
        })
    ) {
        router.delete(`/payroll/employees/${e.id}`, {
            preserveScroll: true,
            onSuccess: () => (empOpen.value = false),
        });
    }
}

// Put an old salary row (no name on it) against an employee
const assignOpen = ref(false);
const assigning = ref<Payment | null>(null);
const assign = useForm({
    employee_id: null as number | null,
    salary_month: thisMonth,
    kind: 'salary',
});

function startAssign(p: Payment): void {
    assigning.value = p;
    assign.reset();
    assignOpen.value = true;
}

function saveAssign(): void {
    assign.put(`/payroll/payments/${assigning.value!.id}`, {
        preserveScroll: true,
        onSuccess: () => (assignOpen.value = false),
    });
}

async function removePayment(p: Payment): Promise<void> {
    if (
        await confirmAction({
            title: t('বেতনের row মুছবেন?'),
            body: t('{what} — {amount} Trash-এ যাবে।', {
                what: p.description,
                amount: money(p.amount, 'bdt'),
            }),
            confirm: t('Trash-এ পাঠান'),
            danger: true,
        })
    ) {
        router.delete(`/payroll/payments/${p.id}`, { preserveScroll: true });
    }
}

const shortMonth = (ym: string) =>
    BN_MONTHS[Number(ym.slice(5, 7)) - 1].slice(0, 3);
</script>

<template>
    <PageHeader
        :eyebrow="t('ব্যবসা')"
        title="Payroll"
        :lead="
            t(
                'কর্মী, মাসিক বেতন, কাকে কত দেওয়া হলো আর কত বাকি। বেতন দিলে BDT খাতায় খরচ হিসেবে নিজে ওঠে।',
            )
        "
    >
        <KButton
            v-if="can['payroll.create']"
            icon="plus"
            @click="startEmployee()"
            >{{ t('নতুন কর্মী') }}</KButton
        >
        <KButton
            v-if="can['payroll.create'] && employees.length"
            variant="ink"
            icon="wallet"
            @click="startPay()"
            >{{ t('বেতন দিন') }}</KButton
        >
    </PageHeader>

    <div class="mb-6 grid grid-cols-3 gap-2 sm:gap-3">
        <div class="panel border-l-[3px] border-l-brand p-3 sm:p-5">
            <p
                class="text-[12.5px] font-medium text-brand-ink sm:text-[13.5px]"
            >
                {{ t('মাসিক বেতন') }}
            </p>
            <p class="num mt-1 text-[18px] font-bold sm:text-[24px]">
                {{ money(totals.monthly, 'bdt') }}
            </p>
        </div>
        <div class="panel border-l-[3px] border-l-in p-3 sm:p-5">
            <p class="text-[12.5px] font-medium text-in sm:text-[13.5px]">
                {{ t('এই মাসে দেওয়া') }}
            </p>
            <p class="num mt-1 text-[18px] font-bold sm:text-[24px]">
                {{ money(totals.paid_this_month, 'bdt') }}
            </p>
        </div>
        <div
            class="panel border-l-[3px] p-3 sm:p-5"
            :class="totals.due > 0 ? 'border-l-out' : 'border-l-line-2'"
        >
            <p
                class="text-[12.5px] font-medium sm:text-[13.5px]"
                :class="totals.due > 0 ? 'text-out' : 'text-ink-3'"
            >
                {{ t('মোট বাকি') }}
            </p>
            <p
                class="num mt-1 text-[18px] font-bold sm:text-[24px]"
                :class="totals.due > 0 && 'text-out'"
            >
                {{ money(totals.due, 'bdt') }}
            </p>
        </div>
    </div>

    <div
        v-if="totals.unassigned"
        class="mb-6 flex items-center gap-3 rounded-xl border border-dashed border-wait/50 bg-wait-soft px-4 py-3 text-[14.5px] text-wait"
    >
        <KIcon name="warn" :size="16" />
        <span
            >{{ t('পুরনো sheet-এর') }} <b class="num">{{ totals.unassigned }}</b
            >{{
                t(
                    'টা বেতনের row-এ কর্মীর নাম নেই — নিচে "কার বেতন?" চেপে বসিয়ে দিন।',
                )
            }}</span
        >
    </div>

    <section class="panel mb-6 overflow-hidden">
        <KEmpty
            v-if="!employees.length"
            :title="t('এখনো কোনো কর্মী নেই')"
            :body="t('কর্মী যোগ করুন — মাসিক বেতন দিলে বাকি নিজে হিসাব হবে।')"
        >
            <KButton
                v-if="can['payroll.create']"
                variant="primary"
                icon="plus"
                @click="startEmployee()"
                >{{ t('নতুন কর্মী') }}</KButton
            >
        </KEmpty>
        <!-- Phone: a card per employee — who, the monthly pay, six months as chips, what is owed -->
        <ul v-else class="divide-y divide-line md:hidden">
            <li
                v-for="e in employees"
                :key="e.id"
                class="p-4"
                :class="e.status === 'left' && 'opacity-60'"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-[15px] font-bold text-brand-ink"
                        >{{ initials(e.name) }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15.5px] font-bold">{{
                            e.name
                        }}</span>
                        <span class="block truncate text-[13px] text-ink-3"
                            >{{ e.designation ?? t('কর্মী')
                            }}<template v-if="e.status === 'left'">
                                · {{ t('ছেড়ে গেছেন') }}</template
                            >
                            · {{ t('মাসিক') }}
                            <b class="num font-semibold text-ink-2">{{
                                money(e.monthly_salary, 'bdt')
                            }}</b></span
                        >
                    </span>
                    <span
                        class="num shrink-0 rounded-lg px-2.5 py-1 text-[13px] font-semibold"
                        :class="
                            e.due > 0
                                ? 'bg-out-soft text-out'
                                : e.due < 0
                                  ? 'bg-wait-soft text-wait'
                                  : 'bg-in-soft text-in'
                        "
                        >{{
                            e.due === 0
                                ? t('✓ সব দেওয়া')
                                : e.due < 0
                                  ? t('{amount} advance', {
                                        amount: money(-e.due, 'bdt'),
                                    })
                                  : t('{amount} বাকি', {
                                        amount: money(e.due, 'bdt'),
                                    })
                        }}</span
                    >
                </div>
                <div
                    class="mt-3 grid gap-1"
                    :style="{
                        gridTemplateColumns: `repeat(${months.length}, minmax(0, 1fr))`,
                    }"
                >
                    <span
                        v-for="m in months"
                        :key="m"
                        class="min-w-0 rounded-lg px-1 py-1.5 text-center"
                        :class="
                            e.months[m]
                                ? 'bg-in-soft text-in'
                                : 'bg-sunken text-ink-3'
                        "
                    >
                        <span class="block font-bn text-[11.5px]">{{
                            shortMonth(m)
                        }}</span>
                        <span
                            class="num block truncate text-[12px] font-semibold"
                            >{{
                                e.months[m]
                                    ? money(e.months[m], 'bdt').replace(
                                          TAKA,
                                          '',
                                      )
                                    : '–'
                            }}</span
                        >
                    </span>
                </div>
                <div
                    v-if="can['payroll.create'] || can['payroll.edit']"
                    class="mt-3 flex justify-end gap-2"
                >
                    <KButton
                        v-if="can['payroll.create'] && e.status === 'active'"
                        size="sm"
                        variant="primary"
                        icon="wallet"
                        @click="startPay(e)"
                        >{{ t('বেতন দিন') }}</KButton
                    >
                    <KButton
                        v-if="can['payroll.edit']"
                        size="sm"
                        variant="soft"
                        icon="pen"
                        @click="startEmployee(e)"
                        >{{ t('বদলান') }}</KButton
                    >
                </div>
            </li>
        </ul>
        <div v-if="employees.length" class="hidden overflow-x-auto md:block">
            <table class="ledger">
                <thead>
                    <tr>
                        <th>{{ t('কর্মী') }}</th>
                        <th class="r">{{ t('মাসিক') }}</th>
                        <th v-for="m in months" :key="m" class="r font-bn">
                            {{ shortMonth(m) }}
                        </th>
                        <th class="r">{{ t('বাকি') }}</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="e in employees"
                        :key="e.id"
                        class="group"
                        :class="e.status === 'left' && 'opacity-60'"
                    >
                        <td class="min-w-[180px]">
                            <span class="font-semibold">{{ e.name }}</span>
                            <p
                                v-if="e.designation || e.status === 'left'"
                                class="text-[13px] text-ink-3"
                            >
                                {{ e.designation ?? ''
                                }}<template v-if="e.status === 'left'">
                                    <template v-if="e.designation">·</template>
                                    {{ t('ছেড়ে গেছেন') }}</template
                                >
                            </p>
                        </td>
                        <td class="r num">
                            {{ money(e.monthly_salary, 'bdt') }}
                        </td>
                        <td
                            v-for="m in months"
                            :key="m"
                            class="r num text-[13.5px]"
                            :class="e.months[m] ? 'text-ink' : 'text-ink-3/50'"
                        >
                            {{ e.months[m] ? money(e.months[m], 'bdt') : '·' }}
                        </td>
                        <td
                            class="r num font-semibold"
                            :class="
                                e.due > 0
                                    ? 'text-out'
                                    : e.due < 0
                                      ? 'text-wait'
                                      : 'text-in'
                            "
                        >
                            {{ e.due === 0 ? '✓' : money(e.due, 'bdt') }}
                            <span
                                v-if="e.due < 0"
                                class="block font-sans text-[13px] font-normal"
                                >advance</span
                            >
                        </td>
                        <td class="r whitespace-nowrap">
                            <div class="ml-auto flex justify-end gap-1">
                                <KButton
                                    v-if="
                                        can['payroll.create'] &&
                                        e.status === 'active'
                                    "
                                    size="sm"
                                    variant="primary"
                                    icon="wallet"
                                    @click="startPay(e)"
                                    >{{ t('দিন') }}</KButton
                                >
                                <KButton
                                    v-if="can['payroll.edit']"
                                    size="sm"
                                    variant="soft"
                                    icon="pen"
                                    @click="startEmployee(e)"
                                    >{{ t('বদলান') }}</KButton
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel overflow-hidden">
        <div
            class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6"
        >
            <h2 class="text-[16px] font-bold">
                {{ t('বেতন দেওয়ার খাতা') }}
            </h2>
            <!-- Phone: the two filters share one row under the title -->
            <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
                <KSelect
                    v-model="filters.month"
                    :options="monthOptions"
                    :placeholder="t('সব মাস')"
                    clearable
                    size="sm"
                    class="min-w-0 sm:w-40"
                />
                <KSelect
                    v-model="filters.employee_id"
                    :options="employeeOptions"
                    :placeholder="t('সব কর্মী')"
                    clearable
                    size="sm"
                    class="min-w-0 sm:w-44"
                />
            </div>
        </div>
        <KEmpty
            v-if="!payments.data.length"
            :title="t('এখনো কোনো বেতন দেওয়া হয়নি')"
        />
        <ul v-else class="mt-2 px-5 pb-3 sm:px-6">
            <li
                v-for="p in payments.data"
                :key="p.id"
                class="border-b border-line py-3 text-[14.5px] last:border-0"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span
                                class="font-semibold"
                                :class="!p.employee && 'text-wait'"
                                >{{
                                    p.employee ?? t('কর্মী ঠিক করা হয়নি')
                                }}</span
                            >
                            <KTag
                                :tone="
                                    p.kind === 'advance'
                                        ? 'wait'
                                        : p.kind === 'bonus'
                                          ? 'brand'
                                          : 'neutral'
                                "
                                >{{ p.kind_label }}</KTag
                            >
                        </p>
                        <p class="num mt-0.5 text-[13.5px] text-ink-3">
                            {{ p.date ? niceDate(p.date) : t('তারিখ নেই') }}
                            <span class="font-bn">
                                · {{ monthLabel(p.month) }}</span
                            >
                        </p>
                    </div>
                    <span class="num shrink-0 text-[16px] font-bold">{{
                        money(p.amount, 'bdt')
                    }}</span>
                </div>
                <div
                    v-if="
                        (!p.employee_id && can['payroll.edit']) ||
                        can['payroll.delete']
                    "
                    class="mt-2 flex items-center gap-2"
                >
                    <KButton
                        v-if="!p.employee_id && can['payroll.edit']"
                        size="sm"
                        variant="primary"
                        @click="startAssign(p)"
                        >{{ t('কার বেতন?') }}</KButton
                    >
                    <KButton
                        v-if="can['payroll.delete']"
                        size="sm"
                        variant="danger"
                        icon="bin"
                        class="ml-auto"
                        @click="removePayment(p)"
                        >{{ t('মুছুন') }}</KButton
                    >
                </div>
            </li>
        </ul>
        <KPager :page="payments" :sizes="[10, 25, 50, 100]" />
    </section>

    <!-- Pay -->
    <KModal
        v-model="payOpen"
        :title="t('বেতন দিন')"
        :subtitle="t('BDT খাতায় “বেতন” খরচ হিসেবে উঠবে।')"
    >
        <form
            id="pay-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="savePay"
        >
            <KField
                :label="t('কর্মী')"
                :error="pay.errors.employee_id"
                class="sm:col-span-2"
            >
                <KSelect
                    v-model="pay.employee_id"
                    :options="options.employees"
                    :invalid="!!pay.errors.employee_id"
                />
            </KField>
            <KField :label="t('কোন মাসের')" :error="pay.errors.salary_month"
                ><KMonth v-model="pay.salary_month"
            /></KField>
            <KField :label="t('দেওয়ার তারিখ')" :error="pay.errors.entry_date"
                ><KDate v-model="pay.entry_date" :max="today()"
            /></KField>
            <KField :label="t('ধরন')" class="sm:col-span-2"
                ><KSegment v-model="pay.kind" :options="options.kinds"
            /></KField>
            <KField :label="t('কত')" :error="pay.errors.amount"
                ><KMoney
                    v-model="pay.amount"
                    currency="bdt"
                    :invalid="!!pay.errors.amount"
            /></KField>
            <KField label="Note" optional class="sm:col-span-2"
                ><KTextarea v-model="pay.notes"
            /></KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="payOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="pay-form"
                variant="primary"
                :loading="pay.processing"
                >{{ t('দেওয়া হলো') }}</KButton
            >
        </template>
    </KModal>

    <!-- Employee -->
    <KModal v-model="empOpen" :title="editing ? editing.name : t('নতুন কর্মী')">
        <form
            id="emp-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="saveEmployee"
        >
            <KField :label="t('নাম')" :error="emp.errors.name"
                ><KInput v-model="emp.name" :invalid="!!emp.errors.name"
            /></KField>
            <KField :label="t('পদ')" optional
                ><KInput
                    v-model="emp.designation"
                    :placeholder="t('যেমন Media Buyer')"
            /></KField>
            <KField :label="t('মাসিক বেতন')" :error="emp.errors.monthly_salary"
                ><KMoney
                    v-model="emp.monthly_salary"
                    currency="bdt"
                    :invalid="!!emp.errors.monthly_salary"
            /></KField>
            <KField label="Phone" optional
                ><KInput v-model="emp.phone" type="tel"
            /></KField>
            <KField
                :label="t('যোগ দিয়েছেন')"
                optional
                :hint="t('এই মাস থেকে বাকি হিসাব শুরু')"
                ><KDate v-model="emp.joined_on" clearable
            /></KField>
            <KField :label="t('অবস্থা')"
                ><KSegment v-model="emp.status" :options="options.statuses"
            /></KField>
            <KField
                v-if="emp.status === 'left'"
                :label="t('ছেড়েছেন')"
                :error="emp.errors.left_on"
                ><KDate v-model="emp.left_on" clearable
            /></KField>
            <KField label="Note" optional class="sm:col-span-2"
                ><KTextarea v-model="emp.notes"
            /></KField>
        </form>
        <template #footer>
            <KButton
                v-if="editing && can['payroll.delete']"
                variant="danger"
                icon="bin"
                class="mr-auto"
                @click="removeEmployee(editing)"
                >{{ t('মুছুন') }}</KButton
            >
            <KButton variant="quiet" @click="empOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="emp-form"
                variant="primary"
                :loading="emp.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <!-- Assign old row -->
    <KModal
        v-model="assignOpen"
        width="sm"
        :title="t('কার বেতন?')"
        :subtitle="
            assigning
                ? `${assigning.description} — ${money(assigning.amount, 'bdt')}`
                : ''
        "
    >
        <form
            id="assign-form"
            class="flex flex-col gap-4"
            @submit.prevent="saveAssign"
        >
            <KField :label="t('কর্মী')" :error="assign.errors.employee_id"
                ><KSelect
                    v-model="assign.employee_id"
                    :options="options.employees"
            /></KField>
            <KField :label="t('কোন মাসের')" :error="assign.errors.salary_month"
                ><KMonth v-model="assign.salary_month"
            /></KField>
            <KField :label="t('ধরন')"
                ><KSegment v-model="assign.kind" :options="options.kinds"
            /></KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="assignOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="assign-form"
                variant="primary"
                :loading="assign.processing"
                >{{ t('বসিয়ে দিন') }}</KButton
            >
        </template>
    </KModal>
</template>
