<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { useFilters } from '@/composables/useFilters';
import { money } from '@/lib/format';
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
    type: string;
    status: string;
    phone: string | null;
    email: string | null;
    company: string | null;
    notes: string | null;
    figures: Figures | null;
};

const props = defineProps<{
    clients: Paginated<Client>;
    filters: { q?: string | null; status: string; type?: string | null };
    totals: { margin: number; spent: number; pending: number };
    options: { types: Option[]; statuses: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);

const { filters } = useFilters('/clients', {
    q: props.filters.q ?? null,
    status: props.filters.status,
    type: props.filters.type ?? null,
});

const open = ref(false);
const form = useForm({
    name: '',
    type: 'client',
    status: 'active',
    phone: '',
    email: '',
    company: '',
    notes: '',
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

    <div
        class="mb-6 grid gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-3"
    >
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('মোট margin') }}</p>
            <p class="num mt-2 text-[24px]">
                <KAmount :value="totals.margin" currency="bdt" />
            </p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('Client-দের ad খরচ (settled)') }}</p>
            <p class="num mt-2 text-[24px]">{{ money(totals.spent, 'usd') }}</p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('এখনো পাওনা') }}</p>
            <p class="num mt-2 text-[24px] text-wait">
                {{ money(totals.pending, 'usd') }}
            </p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <KInput
            v-model="filters.q"
            type="search"
            icon="search"
            :placeholder="t('Client খুঁজুন')"
            size="sm"
            class="w-full max-w-xs"
        />
        <KSegment v-model="filters.status" :options="showOptions" size="sm" />
        <KSelect
            v-model="filters.type"
            :options="options.types"
            :placeholder="t('সব ধরন')"
            clearable
            size="sm"
            class="w-40"
        />
    </div>

    <p
        v-if="!clients.data.length"
        class="panel px-5 py-10 text-center text-[13px] text-ink-3"
    >
        {{ t('কোনো client পাওয়া যায়নি।') }}
    </p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Link
            v-for="c in clients.data"
            :key="c.id"
            :href="`/clients/${c.id}`"
            class="panel group flex flex-col p-5 transition-colors hover:border-line-2"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p
                        class="truncate text-[17px] font-bold group-hover:text-brand-ink"
                    >
                        {{ c.name }}
                    </p>
                    <p class="truncate text-[12.5px] text-ink-3">
                        {{ c.notes ?? c.company ?? '—' }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-1">
                    <KTag v-if="c.type === 'associate'">Associate</KTag>
                    <KTag v-if="c.status === 'inactive'" tone="neutral"
                        >Inactive</KTag
                    >
                </div>
            </div>
            <dl
                class="mt-5 grid grid-cols-3 gap-2 border-t border-line pt-4 text-[12px]"
            >
                <div>
                    <dt class="text-ink-3">{{ t('ad খরচ') }}</dt>
                    <dd class="num mt-0.5 text-[14px] text-ink">
                        {{ money(c.figures?.usd_settled ?? 0, 'usd') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-3">{{ t('দিয়েছে') }}</dt>
                    <dd class="num mt-0.5 text-[14px] text-ink">
                        {{ money(c.figures?.received ?? 0, 'bdt') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-3">margin</dt>
                    <dd class="mt-0.5 text-[14px] font-semibold">
                        <KAmount
                            :value="c.figures?.margin ?? 0"
                            currency="bdt"
                        />
                    </dd>
                </div>
            </dl>
            <p
                v-if="c.figures?.pending_count"
                class="mt-3 rounded-lg bg-wait-soft px-3 py-1.5 text-[12.5px] font-medium text-wait"
            >
                {{
                    t('{amount} পাওনা', {
                        amount: money(c.figures.usd_pending, 'usd'),
                    })
                }}
                · {{ t('{n}টা pending', { n: c.figures.pending_count }) }}
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
            <KField :label="t('ধরন')"
                ><KSegment v-model="form.type" :options="options.types"
            /></KField>
            <KField :label="t('অবস্থা')"
                ><KSegment v-model="form.status" :options="options.statuses"
            /></KField>
            <KField label="Phone" optional :error="form.errors.phone"
                ><KInput v-model="form.phone" type="tel"
            /></KField>
            <KField label="Email" optional :error="form.errors.email"
                ><KInput v-model="form.email" type="email"
            /></KField>
            <KField label="Company" optional class="sm:col-span-2"
                ><KInput v-model="form.company"
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
