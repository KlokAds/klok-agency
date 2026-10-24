<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KSegment from '@/components/k/KSegment.vue';
import KTag from '@/components/k/KTag.vue';
import { ago, money } from '@/lib/format';
import { t } from '@/lib/i18n';

type Sms = {
    id: number;
    provider: string;
    sender: string | null;
    body: string | null;
    amount: number | null;
    payer: string | null;
    trx_id: string | null;
    status: 'matched' | 'unmatched' | 'refund';
    at: string | null;
    invoice: { uid: string; number: string; client: string | null } | null;
};

const props = defineProps<{
    sms: Sms[];
    status: string | null;
    counts: Record<string, number>;
    last_seen: string | null;
}>();

const filter = ref(props.status ?? '');
const opened = ref<number | null>(null);

const tabs = [
    { value: '', label: t('সব') },
    { value: 'matched', label: t('মিলেছে') },
    { value: 'unmatched', label: t('মেলেনি') },
    { value: 'refund', label: 'Refund' },
];

function show(picked: string | number | null | undefined): void {
    const value = picked ? String(picked) : '';
    filter.value = value;
    router.get('/admin/sms', value ? { status: value } : {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

const tone = (s: Sms['status']) =>
    s === 'matched' ? 'in' : s === 'refund' ? 'wait' : 'neutral';
const label = (s: Sms['status']) =>
    s === 'matched' ? t('মিলেছে') : s === 'refund' ? 'Refund' : t('মেলেনি');
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        title="Payment SMS"
        :lead="
            t(
                'Phone থেকে যত টাকার SMS server-এ এসেছে — কোনটা কোন invoice-এ মিলেছে, কোনটা refund, কোনটা মেলেনি (একদিন পর নিজে মুছে যায়)। শুধু Owner দেখেন।',
            )
        "
    />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <KSegment
            :model-value="filter"
            :options="tabs"
            size="sm"
            @update:model-value="show"
        />
        <span class="text-[12.5px] text-ink-3">
            {{
                last_seen
                    ? t('Phone থেকে শেষ খবর {ago}', { ago: ago(last_seen) })
                    : t('Phone থেকে এখনো কোনো খবর নেই')
            }}
        </span>
    </div>

    <KEmpty v-if="!sms.length" :title="t('কোনো SMS নেই')" />

    <ul v-else class="panel divide-y divide-line">
        <li v-for="s in sms" :key="s.id">
            <button
                type="button"
                class="flex w-full items-start gap-3 px-5 py-3.5 text-left hover:bg-sunken sm:px-6"
                :aria-expanded="opened === s.id"
                @click="opened = opened === s.id ? null : s.id"
            >
                <KTag :tone="tone(s.status)" class="mt-0.5 shrink-0">{{
                    label(s.status)
                }}</KTag>
                <span class="min-w-0 flex-1">
                    <span class="block text-[13.5px] font-medium">
                        {{ s.sender ?? s.provider }} ·
                        <span class="num">{{
                            s.amount !== null ? money(s.amount, 'bdt') : '—'
                        }}</span>
                        <span v-if="s.payer" class="text-ink-3">
                            · {{ s.payer }}</span
                        >
                    </span>
                    <span class="num block text-[12px] text-ink-3"
                        >{{ s.trx_id }}
                        <template v-if="s.at"> · {{ ago(s.at) }}</template>
                    </span>
                </span>
                <Link
                    v-if="s.invoice"
                    :href="`/invoices/${s.invoice.uid}`"
                    class="shrink-0 text-[12.5px] font-semibold text-brand hover:underline"
                    @click.stop
                    >{{ s.invoice.number
                    }}<span
                        v-if="s.invoice.client"
                        class="hidden font-normal text-ink-3 sm:inline"
                    >
                        · {{ s.invoice.client }}</span
                    ></Link
                >
            </button>
            <p
                v-if="opened === s.id && s.body"
                class="mx-5 mb-4 rounded-lg bg-sunken p-3 text-[12.5px] wrap-anywhere whitespace-pre-wrap text-ink-2 sm:mx-6"
            >
                {{ s.body }}
            </p>
        </li>
    </ul>
</template>
