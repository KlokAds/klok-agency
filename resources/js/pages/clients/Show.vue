<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import TaskForm from '@/components/TaskForm.vue';
import KSelect from '@/components/k/KSelect.vue';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KSegment from '@/components/k/KSegment.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { confirmAction } from '@/lib/feedback';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type Client = {
    id: number;
    name: string;
    type: string;
    status: string;
    phone: string | null;
    email: string | null;
    company: string | null;
    notes: string | null;
    manager_id: number | null;
    pilot_shop_id: number | null;
};

type Run = {
    id: number;
    title: string;
    platform: string | null;
    status: string;
    start_on: string;
    end_on: string;
    budget_usd: number;
    result: string | null;
    days_left: number;
};

type OpenTask = {
    id: number;
    title: string;
    kind: string;
    due_at: string | null;
    assignee: string | null;
    overdue: boolean;
};

const props = defineProps<{
    client: Client;
    figures: {
        usd_settled: number;
        cost_settled: number;
        received: number;
        margin: number;
        usd_pending: number;
        cost_pending: number;
    } | null;
    statement: {
        key: string;
        id: number;
        book: 'usd' | 'bdt';
        date: string | null;
        description: string;
        account: string;
        usd: number | null;
        cost: number | null;
        bdt: number | null;
        status: string | null;
    }[];
    runs: Run[];
    tasks: OpenTask[];
    options: { users: Option[]; shops: Option[] };
}>();

const taskOpen = ref(false);
const clientOptions = computed<Option[]>(() => [
    { value: props.client.id, label: props.client.name },
]);

const can = computed(() => usePage().props.auth.can);
const f = computed(
    () =>
        props.figures ?? {
            usd_settled: 0,
            cost_settled: 0,
            received: 0,
            margin: 0,
            usd_pending: 0,
            cost_pending: 0,
        },
);
const rate = computed(() =>
    f.value.usd_settled
        ? (f.value.received / f.value.usd_settled).toFixed(2)
        : null,
);

const open = ref(false);
const form = useForm({
    ...props.client,
    phone: props.client.phone ?? '',
    email: props.client.email ?? '',
    company: props.client.company ?? '',
    notes: props.client.notes ?? '',
});

const types: Option[] = [
    { value: 'client', label: 'Client' },
    { value: 'associate', label: 'Associate' },
];
const statuses: Option[] = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

function save(): void {
    form.put(`/clients/${props.client.id}`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

async function remove(): Promise<void> {
    if (
        await confirmAction({
            title: t('{name} মুছবেন?', { name: props.client.name }),
            body: t('যার কোনো লেনদেন নেই, শুধু তাকেই মোছা যায়।'),
            confirm: t('Trash-এ পাঠান'),
            danger: true,
        })
    ) {
        router.delete(`/clients/${props.client.id}`);
    }
}

function print(): void {
    window.print();
}
</script>

<template>
    <PageHeader
        :eyebrow="client.type === 'associate' ? 'Associate' : 'Client'"
        :title="client.name"
        :lead="client.notes ?? undefined"
    >
        <KButton icon="print" @click="print">Statement print</KButton>
        <KButton
            v-if="can['tasks.create']"
            icon="bell"
            @click="taskOpen = true"
            >{{ t('Reminder যোগ') }}</KButton
        >
        <KButton v-if="can['clients.edit']" icon="pen" @click="open = true">{{
            t('বদলান')
        }}</KButton>
    </PageHeader>

    <div
        class="mb-6 grid gap-px overflow-hidden rounded-[16px] border border-line bg-line sm:grid-cols-2 lg:grid-cols-4"
    >
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('Ad খরচ (settled)') }}</p>
            <p class="num mt-2 text-[22px]">
                {{ money(f.usd_settled, 'usd') }}
            </p>
            <p class="num mt-1 text-[12px] text-ink-3">
                FIFO cost {{ money(f.cost_settled, 'bdt') }}
            </p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('দিয়েছে') }}</p>
            <p class="num mt-2 text-[22px]">{{ money(f.received, 'bdt') }}</p>
            <p v-if="rate" class="num mt-1 text-[12px] text-ink-3">
                {{ t('গড়') }} &#2547;{{ rate }} / USD
            </p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">Margin</p>
            <p class="mt-2 text-[22px]">
                <KAmount :value="f.margin" currency="bdt" />
            </p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('পাওনা') }}</p>
            <p class="num mt-2 text-[22px] text-wait">
                {{ money(f.usd_pending, 'usd') }}
            </p>
            <p class="num mt-1 text-[12px] text-ink-3">
                cost {{ money(f.cost_pending, 'bdt') }}
            </p>
        </div>
    </div>

    <div class="no-print mb-6 grid gap-6 lg:grid-cols-2">
        <section class="panel p-5 sm:p-6">
            <div class="flex items-baseline justify-between">
                <h2 class="text-[16px] font-bold">{{ t('Ad চালানো') }}</h2>
                <Link
                    v-if="can['runs.view']"
                    :href="`/runs?client_id=${client.id}&view=all`"
                    class="text-[13px] font-semibold text-brand-ink hover:underline"
                    >{{ t('সব দেখুন') }} →</Link
                >
            </div>
            <p v-if="!runs.length" class="mt-3 text-[13px] text-ink-3">
                {{ t('এখনো কোনো ad run নেই।') }}
            </p>
            <ul v-else class="mt-2">
                <li
                    v-for="r in runs"
                    :key="r.id"
                    class="flex items-center gap-3 border-b border-dashed border-line py-2.5 text-[13px] last:border-0"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            r.title
                        }}</span>
                        <span class="num text-[12px] text-ink-3"
                            >{{ r.platform }} ·
                            {{ niceDate(r.start_on, false) }} –
                            {{ niceDate(r.end_on, false) }}</span
                        >
                    </span>
                    <span class="num">{{ money(r.budget_usd, 'usd') }}</span>
                    <KTag
                        :tone="
                            r.status === 'running'
                                ? r.days_left <= 1
                                    ? 'wait'
                                    : 'brand'
                                : 'neutral'
                        "
                        >{{
                            r.status === 'running'
                                ? t('{n} দিন বাকি', {
                                      n: Math.max(0, r.days_left),
                                  })
                                : t('শেষ')
                        }}</KTag
                    >
                </li>
            </ul>
        </section>

        <section class="panel p-5 sm:p-6">
            <div class="flex items-baseline justify-between">
                <h2 class="text-[16px] font-bold">
                    {{ t('বাকি কাজ ও reminder') }}
                </h2>
                <Link
                    v-if="can['tasks.view']"
                    :href="`/tasks?client_id=${client.id}`"
                    class="text-[13px] font-semibold text-brand-ink hover:underline"
                    >{{ t('সব দেখুন') }} →</Link
                >
            </div>
            <p v-if="!tasks.length" class="mt-3 text-[13px] text-ink-3">
                {{ t('কোনো কাজ বাকি নেই।') }}
            </p>
            <ul v-else class="mt-2">
                <li
                    v-for="task in tasks"
                    :key="task.id"
                    class="flex items-center gap-3 border-b border-dashed border-line py-2.5 text-[13px] last:border-0"
                >
                    <span class="min-w-0 flex-1 truncate font-medium">{{
                        t(task.title)
                    }}</span>
                    <span
                        class="num shrink-0 text-[12px]"
                        :class="
                            task.overdue
                                ? 'font-semibold text-out'
                                : 'text-ink-3'
                        "
                        >{{
                            task.due_at
                                ? niceDate(task.due_at.slice(0, 10), false) +
                                  ' ' +
                                  task.due_at.slice(11, 16)
                                : '—'
                        }}</span
                    >
                </li>
            </ul>
        </section>
    </div>

    <section class="panel overflow-hidden">
        <div class="flex items-baseline justify-between px-5 pt-5 sm:px-6">
            <h2 class="text-[16px] font-bold">Statement</h2>
            <span class="text-[12.5px] text-ink-3">{{
                t('USD খরচ আর BDT পেমেন্ট — তারিখ অনুযায়ী')
            }}</span>
        </div>
        <KEmpty v-if="!statement.length" :title="t('এখনো কোনো লেনদেন নেই')" />
        <div v-else class="mt-3 overflow-x-auto">
            <table class="ledger">
                <thead>
                    <tr>
                        <th>{{ t('তারিখ') }}</th>
                        <th>{{ t('বিবরণ') }}</th>
                        <th class="r">{{ t('Ad খরচ (USD)') }}</th>
                        <th class="r">FIFO cost</th>
                        <th class="r">{{ t('পেমেন্ট (BDT)') }}</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in statement" :key="r.key">
                        <td
                            class="num text-[12.5px] whitespace-nowrap text-ink-2"
                        >
                            {{ niceDate(r.date) }}
                        </td>
                        <td>
                            <p class="font-medium">{{ r.description }}</p>
                            <p class="text-[12px] text-ink-3">
                                {{ r.account }}
                            </p>
                        </td>
                        <td class="r num">
                            {{ r.usd !== null ? money(r.usd, 'usd') : '' }}
                        </td>
                        <td class="r num text-ink-2">
                            {{ r.cost !== null ? money(r.cost, 'bdt') : '' }}
                        </td>
                        <td class="r num text-in">
                            {{ r.bdt !== null ? money(r.bdt, 'bdt') : '' }}
                        </td>
                        <td>
                            <KTag v-if="r.status === 'pending'" tone="wait"
                                >Pending</KTag
                            >
                            <KTag v-else-if="r.status === 'settled'" tone="in"
                                >Settled</KTag
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <KModal v-model="open" :title="t('{name} — বদলান', { name: client.name })">
        <form
            id="client-edit"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField
                :label="t('নাম')"
                :error="form.errors.name"
                class="sm:col-span-2"
                ><KInput v-model="form.name" :invalid="!!form.errors.name"
            /></KField>
            <KField :label="t('ধরন')"
                ><KSegment v-model="form.type" :options="types"
            /></KField>
            <KField :label="t('অবস্থা')"
                ><KSegment v-model="form.status" :options="statuses"
            /></KField>
            <KField label="Phone" optional
                ><KInput v-model="form.phone" type="tel"
            /></KField>
            <KField label="Email" optional :error="form.errors.email"
                ><KInput v-model="form.email" type="email"
            /></KField>
            <KField label="Company" optional class="sm:col-span-2"
                ><KInput v-model="form.company"
            /></KField>
            <KField
                label="Manager"
                optional
                :hint="t('এই client-এর ad আর কাজের reminder এঁর কাছে যাবে।')"
                ><KSelect
                    v-model="form.manager_id"
                    :options="options.users"
                    :placeholder="t('কেউ না')"
                    clearable
            /></KField>
            <KField
                label="Klok Pilot shop"
                optional
                :hint="
                    options.shops.length
                        ? t('দিনের শেষে এই shop-এর ads-এর ফলাফল আসবে।')
                        : t('Reminder ও Pilot-এ token দিলে shop দেখা যাবে।')
                "
                ><KSelect
                    v-model="form.pilot_shop_id"
                    :options="options.shops"
                    :placeholder="t('যুক্ত নয়')"
                    :disabled="!options.shops.length"
                    clearable
            /></KField>
            <KField label="Note" optional class="sm:col-span-2"
                ><KTextarea v-model="form.notes"
            /></KField>
        </form>
        <template #footer>
            <KButton
                v-if="can['clients.delete'] && !statement.length"
                variant="danger"
                icon="bin"
                class="mr-auto"
                @click="remove"
                >{{ t('মুছুন') }}</KButton
            >
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="client-edit"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <TaskForm
        v-model="taskOpen"
        :task="null"
        :client-id="client.id"
        :options="{ clients: clientOptions, users: options.users }"
    />
</template>
