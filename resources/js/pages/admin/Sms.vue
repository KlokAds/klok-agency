<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KIcon from '@/components/k/KIcon.vue';
import KSegment from '@/components/k/KSegment.vue';
import KTag from '@/components/k/KTag.vue';
import { confirmAction } from '@/lib/feedback';
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

// A card or bank app's notification (NSave, Fasset, …) and what the books did with it.
type Note = {
    id: number;
    app: string | null;
    body: string;
    kind: string;
    amount: number | null;
    merchant: string | null;
    card: string | null;
    status: 'booked' | 'held' | 'duplicate' | 'ignored';
    problem: string | null;
    at: string | null;
};

const props = defineProps<{
    kind: 'sms' | 'app';
    sms: Sms[];
    notes: Note[];
    status: string | null;
    counts: { sms: Record<string, number>; app: Record<string, number> };
    last_seen: string | null;
}>();

const filter = ref(props.status ?? '');
const opened = ref<number | null>(null);

const kinds = computed(() => [
    { value: 'sms', label: t('টাকার SMS') },
    { value: 'app', label: t('Card ও bank app') },
]);
const tabs = computed(() =>
    props.kind === 'app'
        ? [
              { value: '', label: t('সব') },
              { value: 'booked', label: t('খাতায় উঠেছে') },
              { value: 'held', label: t('মেলাতে হবে') },
              { value: 'duplicate', label: t('আগেই ছিল') },
              { value: 'ignored', label: t('টাকা নড়েনি') },
          ]
        : [
              { value: '', label: t('সব') },
              { value: 'matched', label: t('মিলেছে') },
              { value: 'unmatched', label: t('মেলেনি') },
              { value: 'refund', label: 'Refund' },
          ],
);
const shown = computed(() =>
    props.kind === 'app' ? props.notes.length : props.sms.length,
);

function go(kind: string, status: string): void {
    const query: Record<string, string> = {};
    if (kind === 'app') query.kind = 'app';
    if (status) query.status = status;
    router.get('/admin/sms', query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function pickKind(picked: string | number | null | undefined): void {
    filter.value = '';
    go(String(picked ?? 'sms'), '');
}

function show(picked: string | number | null | undefined): void {
    filter.value = picked ? String(picked) : '';
    go(props.kind, filter.value);
}

// Clearing only takes a line off this list; what paid an invoice or went into the books stays.
function clearOne(id: number): void {
    router.post(
        `/admin/sms/${props.kind}/${id}/clear`,
        {},
        {
            preserveScroll: true,
        },
    );
}

async function clearAll(): Promise<void> {
    const ok = await confirmAction({
        title: t('এই list পুরো সরাবেন?'),
        body: t('শুধু list থেকে সরবে। কোনো invoice বা খাতার হিসাব বদলাবে না।'),
        confirm: t('সব সরান'),
    });

    if (ok) {
        router.post(
            '/admin/sms/clear',
            { kind: props.kind, status: filter.value || null },
            { preserveScroll: true },
        );
    }
}

const tone = (s: Sms['status']) =>
    s === 'matched' ? 'in' : s === 'refund' ? 'wait' : 'neutral';
const label = (s: Sms['status']) =>
    s === 'matched' ? t('মিলেছে') : s === 'refund' ? 'Refund' : t('মেলেনি');

const NOTE: Record<
    Note['status'],
    { label: string; tone: 'in' | 'wait' | 'neutral' }
> = {
    booked: { label: 'খাতায় উঠেছে', tone: 'in' }, // i18n
    held: { label: 'মেলাতে হবে', tone: 'wait' }, // i18n
    duplicate: { label: 'আগেই ছিল', tone: 'neutral' }, // i18n
    ignored: { label: 'টাকা নড়েনি', tone: 'neutral' }, // i18n
};
const KIND: Record<string, string> = {
    charge: 'খরচ', // i18n
    refund: 'Refund',
    topup: 'টাকা যোগ', // i18n
    declined: 'Declined',
    other: 'অন্য', // i18n
};
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        title="Payment SMS"
        :lead="
            t(
                'Phone থেকে যা এসেছে — টাকার SMS আর card ও bank app-এর notification, কোনটা কোথায় গেল। দেখা হলে list থেকে সরিয়ে দিন; হিসাব থেকে যায়। শুধু Owner দেখেন।',
            )
        "
    />

    <section class="panel mb-4 space-y-3 p-4 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <KSegment
                :model-value="kind"
                :options="kinds"
                size="sm"
                @update:model-value="pickKind"
            />
            <span class="text-[13.5px] text-ink-3">
                {{
                    last_seen
                        ? t('Phone থেকে শেষ খবর {ago}', { ago: ago(last_seen) })
                        : t('Phone থেকে এখনো কোনো খবর নেই')
                }}
            </span>
        </div>
        <div class="flex items-center justify-between gap-3">
            <KSegment
                :model-value="filter"
                :options="tabs"
                size="sm"
                select
                @update:model-value="show"
            />
            <KButton
                v-if="shown"
                size="sm"
                variant="plain"
                icon="bin"
                @click="clearAll"
                >{{ t('সব সরান') }}</KButton
            >
        </div>
    </section>

    <KEmpty
        v-if="!shown"
        :title="kind === 'app' ? t('কোনো notification নেই') : t('কোনো SMS নেই')"
    />

    <!-- Money SMS -->
    <ul v-else-if="kind === 'sms'" class="panel divide-y divide-line">
        <li v-for="s in sms" :key="s.id" class="flex items-start">
            <div class="min-w-0 flex-1">
                <button
                    type="button"
                    class="flex w-full items-start gap-3 py-3.5 pl-4 text-left hover:bg-sunken sm:pl-6"
                    :aria-expanded="opened === s.id"
                    @click="opened = opened === s.id ? null : s.id"
                >
                    <KTag :tone="tone(s.status)" class="mt-0.5 shrink-0">{{
                        label(s.status)
                    }}</KTag>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14.5px] font-medium">
                            {{ s.sender ?? s.provider }}
                            <template v-if="s.amount !== null">
                                ·
                                <span class="num">{{
                                    money(s.amount, 'bdt')
                                }}</span></template
                            >
                            <span v-if="s.payer" class="text-ink-3">
                                · {{ s.payer }}</span
                            >
                        </span>
                        <span class="block text-[13px] text-ink-3"
                            ><span v-if="s.trx_id" class="num">{{
                                s.trx_id
                            }}</span
                            ><template v-if="s.at"
                                ><template v-if="s.trx_id"> · </template
                                ><span class="whitespace-nowrap">{{
                                    ago(s.at)
                                }}</span></template
                            >
                        </span>
                    </span>
                    <Link
                        v-if="s.invoice"
                        :href="`/invoices/${s.invoice.uid}`"
                        class="shrink-0 text-[13.5px] font-semibold text-brand hover:underline"
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
                    class="mb-4 ml-4 rounded-lg bg-sunken p-3 text-[13.5px] wrap-anywhere whitespace-pre-wrap text-ink-2 sm:ml-6"
                >
                    {{ s.body }}
                </p>
            </div>
            <button
                type="button"
                class="m-2.5 flex h-11 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg px-2 text-ink-3 hover:bg-sunken hover:text-out sm:mr-4"
                :title="t('List থেকে সরান')"
                @click="clearOne(s.id)"
            >
                <KIcon name="close" :size="15" />
                <span class="text-[12.5px] leading-none">{{ t('সরান') }}</span>
            </button>
        </li>
    </ul>

    <!-- Card and bank apps' notifications -->
    <ul v-else class="panel divide-y divide-line">
        <li v-for="n in notes" :key="n.id" class="flex items-start">
            <div class="min-w-0 flex-1">
                <button
                    type="button"
                    class="flex w-full items-start gap-3 py-3.5 pl-4 text-left hover:bg-sunken sm:pl-6"
                    :aria-expanded="opened === n.id"
                    @click="opened = opened === n.id ? null : n.id"
                >
                    <KTag :tone="NOTE[n.status].tone" class="mt-0.5 shrink-0">{{
                        t(NOTE[n.status].label)
                    }}</KTag>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14.5px] font-medium">
                            {{ n.app ?? 'App' }} ·
                            {{ t(KIND[n.kind] ?? n.kind) }}
                            <template v-if="n.amount !== null">
                                ·
                                <span class="num">{{
                                    money(n.amount, 'usd')
                                }}</span></template
                            >
                            <span v-if="n.merchant" class="text-ink-3">
                                · {{ n.merchant }}</span
                            >
                        </span>
                        <span class="block text-[13px] text-ink-3"
                            ><template v-if="n.card">{{ n.card }} · </template
                            ><span v-if="n.at" class="whitespace-nowrap">{{
                                ago(n.at)
                            }}</span></span
                        >
                        <span
                            v-if="n.status === 'held' && n.problem"
                            class="mt-0.5 block text-[13px] text-wait"
                            >{{ n.problem }}</span
                        >
                    </span>
                </button>
                <p
                    v-if="opened === n.id"
                    class="mb-4 ml-4 rounded-lg bg-sunken p-3 text-[13.5px] wrap-anywhere whitespace-pre-wrap text-ink-2 sm:ml-6"
                >
                    {{ n.body }}
                </p>
            </div>
            <button
                type="button"
                class="m-2.5 flex h-11 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg px-2 text-ink-3 hover:bg-sunken hover:text-out sm:mr-4"
                :title="t('List থেকে সরান')"
                @click="clearOne(n.id)"
            >
                <KIcon name="close" :size="15" />
                <span class="text-[12.5px] leading-none">{{ t('সরান') }}</span>
            </button>
        </li>
    </ul>
</template>
