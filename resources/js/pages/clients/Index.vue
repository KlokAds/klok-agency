<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import StatTiles from '@/components/StatTiles.vue';
import type { StatTile } from '@/components/StatTiles.vue';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { useFilters } from '@/composables/useFilters';
import { initials, money } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Figures = {
    usd_settled: number;
    cost_settled: number;
    received: number;
    margin: number;
    usd_pending: number;
    cost_pending: number;
    pending_count: number;
};
type Client = {
    id: number;
    name: string;
    status: string;
    phone: string | null;
    email: string | null;
    company: string | null;
    notes: string | null;
    figures: Figures | null;
};

const props = defineProps<{
    clients: Paginated<Client>;
    filters: { q?: string | null; status: string };
    totals: { margin: number; spent: number; pending: number };
    options: { statuses: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);

const { filters } = useFilters('/clients', {
    q: props.filters.q ?? null,
    status: props.filters.status,
});

const open = ref(false);
const form = useForm({
    name: '',
    status: 'active',
    phone: '',
    email: '',
    company: '',
    notes: '',
    renew_days: '',
});

function save(): void {
    form.post('/clients', {
        preserveScroll: true,
        onSuccess: () => ((open.value = false), form.reset()),
    });
}

const showOptions = computed<Option[]>(() => [
    ...props.options.statuses,
    { value: 'all', label: t('সব') },
]);

// Margin as a share of what the client paid; nothing paid, nothing to show.
function marginPct(c: Client): number | null {
    const received = c.figures?.received ?? 0;

    return received > 0
        ? Math.round(((c.figures?.margin ?? 0) / received) * 100)
        : null;
}

// The totals over every client, each tile in the colour its figure means.
const tiles = computed<StatTile[]>(() => [
    {
        key: 'margin',
        label: t('মোট margin'),
        value: money(props.totals.margin, 'bdt', { sign: true }),
        icon: 'taka',
        badge: 'bg-in-soft text-in',
        text: props.totals.margin < 0 ? 'text-out' : 'text-in',
    },
    {
        key: 'spent',
        label: t('Client-দের ad খরচ (settled)'),
        value: money(props.totals.spent, 'usd'),
        icon: 'dollar',
        badge: 'bg-brand-soft text-brand-ink',
        text: '',
    },
    {
        key: 'pending',
        label: t('এখনো পাওনা'),
        value: money(props.totals.pending, 'usd'),
        icon: 'clock',
        badge: 'bg-wait-soft text-wait',
        text: props.totals.pending > 0 ? 'text-wait' : '',
    },
]);
</script>

<template>
    <PageHeader
        :eyebrow="t('ব্যবসা')"
        title="Client"
        :lead="
            t(
                'কে কত USD খরচ করিয়েছে, কত BDT দিয়েছে, আর তাতে আপনার margin কত।',
            )
        "
    >
        <KButton
            v-if="can['clients.create']"
            variant="ink"
            icon="plus"
            @click="open = true"
            >{{ t('নতুন client') }}</KButton
        >
    </PageHeader>

    <StatTiles :tiles="tiles" class="mb-6" />

    <!-- Search and status on one row, the status a dropdown where the row is narrow -->
    <div class="mb-4 flex items-center gap-2 sm:gap-3">
        <KInput
            v-model="filters.q"
            type="search"
            icon="search"
            :placeholder="t('Client খুঁজুন')"
            size="sm"
            class="min-w-0 flex-1 sm:max-w-xs"
        />
        <div class="shrink-0">
            <KSegment
                v-model="filters.status"
                :options="showOptions"
                size="sm"
                select
            />
        </div>
    </div>

    <KEmpty
        v-if="!clients.data.length"
        class="panel"
        :title="t('কোনো client পাওয়া যায়নি।')"
        :body="
            filters.q || filters.status !== 'active'
                ? t('অন্য filter দেখুন।')
                : t('নতুন client যোগ করলে এখানে দেখা যাবে।')
        "
    />

    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Link
            v-for="c in clients.data"
            :key="c.id"
            :href="`/clients/${c.id}`"
            class="panel group flex flex-col overflow-hidden transition-[border-color,box-shadow] hover:border-brand/40 hover:shadow-[0_10px_30px_-18px_var(--brand)]"
        >
            <div class="flex items-center gap-3 p-4 sm:p-5">
                <span
                    class="grid size-11 shrink-0 place-items-center rounded-xl text-[17px] font-bold"
                    :class="
                        c.status === 'active'
                            ? 'bg-brand-soft text-brand-ink'
                            : 'bg-sunken text-ink-3'
                    "
                    >{{ initials(c.name) }}</span
                >
                <span class="min-w-0 flex-1">
                    <span
                        class="block truncate text-[16.5px] font-bold group-hover:text-brand-ink"
                        >{{ c.name }}</span
                    >
                    <span class="block truncate text-[13px] text-ink-3">{{
                        c.company ?? c.notes ?? c.phone ?? t('বিস্তারিত নেই')
                    }}</span>
                </span>
                <KTag
                    v-if="c.status === 'inactive'"
                    tone="neutral"
                    class="shrink-0"
                    >Inactive</KTag
                >
                <KIcon
                    v-else
                    name="arrow"
                    :size="16"
                    class="shrink-0 text-ink-3 transition-transform group-hover:translate-x-0.5 group-hover:text-brand-ink"
                />
            </div>

            <!-- The three figures that matter, the margin the loudest -->
            <dl
                class="grid grid-cols-3 divide-x divide-line border-t border-line bg-sunken/60 text-[13px]"
            >
                <div class="min-w-0 px-4 py-3 sm:px-5">
                    <dt class="truncate text-ink-3">{{ t('ad খরচ') }}</dt>
                    <dd class="num mt-0.5 truncate text-[15px] font-semibold">
                        {{ money(c.figures?.usd_settled ?? 0, 'usd') }}
                    </dd>
                </div>
                <div class="min-w-0 px-4 py-3 sm:px-5">
                    <dt class="truncate text-ink-3">{{ t('দিয়েছে') }}</dt>
                    <dd class="num mt-0.5 truncate text-[15px] font-semibold">
                        {{ money(c.figures?.received ?? 0, 'bdt') }}
                    </dd>
                </div>
                <div class="min-w-0 px-4 py-3 sm:px-5">
                    <dt class="truncate text-ink-3">
                        Margin<template v-if="marginPct(c) !== null">
                            · {{ marginPct(c) }}%</template
                        >
                    </dt>
                    <dd class="mt-0.5 truncate text-[15px] font-bold">
                        <KAmount
                            :value="c.figures?.margin ?? 0"
                            currency="bdt"
                        />
                    </dd>
                </div>
            </dl>

            <!-- Owed: a band at the foot; nothing owed: a quiet line -->
            <p
                v-if="c.figures?.pending_count"
                class="flex items-center gap-2 border-t border-wait/30 bg-wait-soft px-4 py-2 text-[13px] font-semibold text-wait sm:px-5"
            >
                <KIcon name="clock" :size="14" />
                {{
                    t('{amount} পাওনা', {
                        amount: money(c.figures.usd_pending, 'usd'),
                    })
                }}
                <span class="font-normal opacity-80"
                    >·
                    {{
                        t('{n}টা pending', { n: c.figures.pending_count })
                    }}</span
                >
            </p>
            <p
                v-else
                class="flex items-center gap-2 border-t border-line px-4 py-2 text-[13px] text-in sm:px-5"
            >
                <KIcon name="check" :size="14" />{{ t('কিছু পাওনা নেই') }}
            </p>
        </Link>
    </div>
    <KPager :page="clients" :sizes="[10, 25, 50, 100]" class="mt-4" />

    <KModal
        v-model="open"
        :title="t('নতুন client')"
        :subtitle="t('নাম দিলেই সব খাতায় এই client বেছে নেওয়া যাবে।')"
    >
        <form
            id="client-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField
                :label="t('নাম')"
                :error="form.errors.name"
                class="sm:col-span-2"
            >
                <KInput v-model="form.name" :invalid="!!form.errors.name" />
            </KField>
            <KField :label="t('অবস্থা')"
                ><KSegment v-model="form.status" :options="options.statuses"
            /></KField>
            <KField label="Phone" optional :error="form.errors.phone"
                ><KInput v-model="form.phone" type="tel"
            /></KField>
            <KField label="Email" optional :error="form.errors.email"
                ><KInput v-model="form.email" type="email"
            /></KField>
            <KField label="Company" optional
                ><KInput v-model="form.company"
            /></KField>
            <KField
                :label="t('Auto renewal invoice (দিন)')"
                optional
                :error="form.errors.renew_days"
                :hint="
                    t(
                        'Campaign শেষের ২ দিন আগে এত দিনের invoice নিজে তৈরি হবে; টাকা এলে campaign নিজে বাড়বে। খালি রাখলে client link থেকে দিন বাছবে।',
                    )
                "
                ><KInput
                    v-model="form.renew_days"
                    inputmode="numeric"
                    :placeholder="t('যেমন 7')"
                    :invalid="!!form.errors.renew_days"
            /></KField>
            <KField label="Note" optional class="sm:col-span-2"
                ><KTextarea
                    v-model="form.notes"
                    :placeholder="t('যেমন Ad management — Chittagong market')"
            /></KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="client-form"
                variant="primary"
                :loading="form.processing"
                >{{ t('যোগ করুন') }}</KButton
            >
        </template>
    </KModal>
</template>
