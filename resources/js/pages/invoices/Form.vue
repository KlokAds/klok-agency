<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KCheck from '@/components/k/KCheck.vue';
import KDate from '@/components/k/KDate.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KTag from '@/components/k/KTag.vue';
import KInput from '@/components/k/KInput.vue';
import KMoney from '@/components/k/KMoney.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { isoDate, money, niceDate, today } from '@/lib/format';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type Line = {
    kind: string;
    description: string;
    campaign_id: number | null;
    period_from: string | null;
    period_to: string | null;
    results: number | null;
    clicks: number | null;
    usd: string;
    rate: string;
    amount: string;
};

type CampaignSpend = {
    id: number;
    name: string;
    rate: number | null;
    usd: number;
    results: number;
    clicks: number;
    days: number;
    from: string;
    to: string;
    live: boolean;
    ended: boolean;
    account: string | null;
};

// Writing an invoice: campaign spend for a period (picked from what Klok
// Pilot recorded), an advance for ads still to run, and any service fee.
// USD lines are priced at the client rate: FX batch rate + commission.
const props = defineProps<{
    invoice: {
        id: number;
        number: string;
        client_id: number;
        issue_date: string;
        due_date: string | null;
        notes: string | null;
        items: Line[];
    } | null;
    next_number: string;
    client_id: number | null;
    pricing: {
        fx_rate: number | null;
        commission: number;
        rate: number | null;
    };
    options: { clients: Option[]; kinds: Option[]; campaigns: Option[] };
}>();

const defaultRate = props.pricing.rate ? String(props.pricing.rate) : '';

function inDays(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return isoDate(date);
}

function blank(kind: string): Line {
    return {
        kind,
        description: '',
        campaign_id: null,
        period_from: null,
        period_to: null,
        results: null,
        clicks: null,
        usd: '',
        rate: defaultRate,
        amount: '',
    };
}

const form = useForm({
    client_id: (props.invoice?.client_id ?? props.client_id) as
        | number
        | string
        | null,
    issue_date: props.invoice?.issue_date ?? today(),
    due_date: (props.invoice?.due_date ?? inDays(7)) as string | null,
    notes: props.invoice?.notes ?? '',
    items: (props.invoice?.items ?? []) as Line[],
});

const isUsd = (line: Line) => ['ad_spend', 'ad_budget'].includes(line.kind);

// An advance line for a campaign: the USD buys that many more days, and
// once paid (and approved) the campaign's end moves out by them on Meta.
type CampaignOption = Option & {
    client_id: number;
    budget: number | null;
    end: string | null;
    live: boolean;
};
const clientCampaigns = computed(() =>
    (props.options.campaigns as CampaignOption[]).filter(
        (c) => c.client_id === Number(form.client_id),
    ),
);
const campaignOf = (line: Line) =>
    clientCampaigns.value.find((c) => c.value === line.campaign_id) ?? null;
const daysFor = (line: Line): number | null => {
    const c = campaignOf(line);

    return c?.budget ? Math.floor(cents(line.usd) / c.budget) : null;
};
function addDaysIso(iso: string, n: number): string {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() + n);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const newEnd = (line: Line): string | null => {
    const c = campaignOf(line);
    const days = daysFor(line);

    if (!c || !days) {
        return null;
    }

    const from = c.end && c.end > today() ? c.end : today();

    return addDaysIso(from, days);
};
const cents = (text: string) =>
    Math.round(parseFloat((text || '0').replace(/,/g, '')) * 100) || 0;
const rateOf = (line: Line) => parseFloat(line.rate || '0') || 0;
const lineBdt = (line: Line) =>
    isUsd(line)
        ? Math.round(cents(line.usd) * rateOf(line))
        : cents(line.amount);

const total = computed(() =>
    form.items.reduce((sum, line) => sum + lineBdt(line), 0),
);
const totalUsd = computed(() =>
    form.items.filter(isUsd).reduce((sum, line) => sum + cents(line.usd), 0),
);
const bdt = (poisha: number) => money(poisha, 'bdt', { round: false });
const taka = (value: number | null) =>
    value === null
        ? '—'
        : String.fromCharCode(0x09f3) +
          value.toLocaleString('en-US', { maximumFractionDigits: 2 });

// Campaign spend for a period
const first = new Date();
first.setDate(1);
// Editing: the period the invoice's campaign lines already cover.
const billedLines = (props.invoice?.items ?? []).filter(
    (l) => l.kind === 'ad_spend' && l.campaign_id && l.period_from,
);
const period = ref({
    from: billedLines.length
        ? billedLines.map((l) => l.period_from as string).sort()[0]
        : isoDate(first),
    to: billedLines.length
        ? billedLines
              .map((l) => (l.period_to ?? l.period_from) as string)
              .sort()
              .reverse()[0]
        : today(),
});
const spend = ref<CampaignSpend[] | null>(null);
const picked = ref<Record<number, boolean>>({});
const loading = ref(false);
const loadError = ref<string | null>(null);

async function loadCampaigns(): Promise<void> {
    if (!form.client_id || !period.value.from || !period.value.to) {
        return;
    }

    loading.value = true;
    loadError.value = null;
    try {
        const query = new URLSearchParams({
            client: String(form.client_id),
            from: period.value.from,
            to: period.value.to,
        });
        const result = await getJson<{ campaigns: CampaignSpend[] }>(
            `/invoices/campaigns?${query}`,
        );
        spend.value = result.campaigns;
        // On an invoice being edited, the campaigns already on it stay ticked.
        const onInvoice = new Set(
            form.items
                .filter((l) => l.kind === 'ad_spend' && l.campaign_id)
                .map((l) => l.campaign_id),
        );
        picked.value = Object.fromEntries(
            result.campaigns.map((c) => [
                c.id,
                onInvoice.size ? onInvoice.has(c.id) : c.usd > 0,
            ]),
        );
    } catch {
        loadError.value = t('Campaign আনা গেল না।');
    } finally {
        loading.value = false;
    }
}

watch(
    () => form.client_id,
    () => {
        spend.value = null;
    },
);

// Editing an invoice with campaign lines: show that period's campaigns,
// and give any line whose name was lost its campaign's name back.
onMounted(() => {
    for (const line of form.items) {
        if (line.kind === 'ad_spend' && line.campaign_id && !line.description) {
            line.description =
                (props.options.campaigns.find(
                    (c) => c.value === line.campaign_id,
                )?.label as string) ?? '';
        }
    }

    if (billedLines.length) {
        void loadCampaigns();
    }
});

const pickedCount = computed(
    () => spend.value?.filter((c) => picked.value[c.id]).length ?? 0,
);

/** One line per ticked campaign; earlier campaign lines are replaced. */
function generate(): void {
    if (!spend.value) {
        return;
    }

    const lines = spend.value
        .filter((c) => picked.value[c.id] && c.usd > 0)
        .map<Line>((c) => ({
            ...blank('ad_spend'),
            description: c.name,
            campaign_id: c.id,
            period_from: c.from,
            period_to: c.to,
            results: c.results,
            clicks: c.clicks,
            usd: (c.usd / 100).toFixed(2),
            // The batches that paid for this spend, plus commission.
            rate: c.rate ? String(c.rate) : defaultRate,
        }));

    form.items = [
        ...lines,
        ...form.items.filter((line) => line.kind !== 'ad_spend'),
    ];
}

function add(kind: string): void {
    form.items.push(blank(kind));
}

function remove(index: number): void {
    form.items.splice(index, 1);
}

const kindLabel = (kind: string) =>
    props.options.kinds.find((k) => k.value === kind)?.label ?? kind;

function save(): void {
    if (props.invoice) {
        form.put(`/invoices/${props.invoice.id}`);
    } else {
        form.post('/invoices');
    }
}

const errorAt = (index: number, field: string) =>
    (form.errors as Record<string, string | undefined>)[
        `items.${index}.${field}`
    ];
</script>

<template>
    <PageHeader
        eyebrow="Invoice"
        :title="
            invoice
                ? t('Invoice {number} বদলান', { number: invoice.number })
                : t('নতুন invoice')
        "
        :lead="
            t(
                'Client বেছে নিন, campaign-এর খরচ আনুন বা নিজে line যোগ করুন। Client যা দেখবেন: USD, rate আর BDT — আপনার খরচ বা লাভ নয়।',
            )
        "
    >
        <KButton
            :href="invoice ? `/invoices/${invoice.id}` : '/invoices'"
            variant="quiet"
            icon="back"
            >{{ t('ফিরে যান') }}</KButton
        >
    </PageHeader>

    <form
        class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]"
        @submit.prevent="save"
    >
        <div class="flex min-w-0 flex-col gap-6">
            <section class="panel grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                <KField
                    label="Client"
                    :error="form.errors.client_id"
                    class="sm:col-span-2"
                >
                    <KSelect
                        v-model="form.client_id"
                        :options="options.clients"
                        :invalid="!!form.errors.client_id"
                        :placeholder="t('কোন client-এর?')"
                    />
                </KField>
                <KField :label="t('তারিখ')" :error="form.errors.issue_date">
                    <KDate v-model="form.issue_date" />
                </KField>
                <KField
                    :label="t('শেষ তারিখ')"
                    :error="form.errors.due_date"
                    optional
                >
                    <KDate
                        v-model="form.due_date"
                        clearable
                        :min="form.issue_date"
                    />
                </KField>
            </section>

            <!-- Campaign spend -->
            <section class="panel p-5 sm:p-6">
                <h2 class="flex items-center gap-2 text-[16px] font-bold">
                    <KIcon name="pulse" class="text-brand" />{{
                        t('Campaign-এর খরচ থেকে')
                    }}
                </h2>
                <p class="mt-1 text-[12.5px] text-ink-3">
                    {{
                        t(
                            'সময় বেছে নিন — এই client-এর প্রতিটা campaign কত খরচ করেছে আর কী ফলাফল এনেছে দেখাবে।',
                        )
                    }}
                </p>
                <div
                    class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                >
                    <KField :label="t('থেকে')">
                        <KDate v-model="period.from" :max="period.to" />
                    </KField>
                    <KField :label="t('পর্যন্ত')">
                        <KDate v-model="period.to" :min="period.from" />
                    </KField>
                    <KButton
                        :loading="loading"
                        :disabled="!form.client_id"
                        @click="loadCampaigns"
                        >{{ t('Campaign দেখুন') }}</KButton
                    >
                </div>
                <p v-if="loadError" class="mt-3 text-[12.5px] text-out">
                    {{ loadError }}
                </p>

                <template v-if="spend">
                    <p
                        v-if="!spend.length"
                        class="mt-4 rounded-lg bg-sunken px-3 py-2.5 text-[13px] text-ink-2"
                    >
                        {{
                            t(
                                'এই client-এর সাথে কোনো campaign যুক্ত নেই। Campaign পাতা থেকে যুক্ত করুন।',
                            )
                        }}
                    </p>
                    <ul
                        v-else
                        class="mt-4 divide-y divide-dashed divide-line rounded-xl border border-line"
                    >
                        <li
                            v-for="c in spend"
                            :key="c.id"
                            class="flex items-start gap-3 px-3 py-3"
                        >
                            <KCheck
                                v-model="picked[c.id]"
                                :disabled="!c.usd"
                                class="mt-0.5"
                            />
                            <div class="min-w-0 flex-1">
                                <p
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1 font-semibold break-words"
                                >
                                    <span
                                        class="size-1.5 shrink-0 rounded-full"
                                        :class="c.live ? 'bg-in' : 'bg-line-2'"
                                    />
                                    {{ c.name }}
                                    <KTag v-if="c.ended" tone="neutral">{{
                                        t('শেষ হয়েছে')
                                    }}</KTag>
                                </p>
                                <p class="mt-0.5 text-[12px] text-ink-3">
                                    {{
                                        t('{n} result', {
                                            n: c.results.toLocaleString(
                                                'en-US',
                                            ),
                                        })
                                    }}
                                    ·
                                    {{
                                        t('{n} click', {
                                            n: c.clicks.toLocaleString('en-US'),
                                        })
                                    }}
                                    <template v-if="c.results && c.usd">
                                        ·
                                        {{
                                            t('প্রতি result {cost}', {
                                                cost: money(
                                                    Math.round(
                                                        c.usd / c.results,
                                                    ),
                                                    'usd',
                                                ),
                                            })
                                        }}</template
                                    >
                                    · {{ t('{n} দিন খরচ', { n: c.days }) }}
                                </p>
                            </div>
                            <p
                                class="num shrink-0 font-medium"
                                :class="c.usd ? '' : 'text-ink-3'"
                            >
                                {{ money(c.usd, 'usd') }}
                            </p>
                        </li>
                    </ul>
                    <div
                        v-if="spend.length"
                        class="mt-3 flex flex-wrap items-center justify-between gap-3"
                    >
                        <p class="num text-[12.5px] text-ink-3">
                            {{ niceDate(period.from, false) }} –
                            {{ niceDate(period.to) }}
                        </p>
                        <KButton
                            variant="ink"
                            icon="plus"
                            :disabled="!pickedCount"
                            @click="generate"
                            >{{
                                t('{n}টা campaign দিয়ে invoice বানান', {
                                    n: pickedCount,
                                })
                            }}</KButton
                        >
                    </div>
                </template>
            </section>

            <!-- Lines -->
            <section class="panel overflow-hidden">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4"
                >
                    <h2 class="text-[16px] font-bold">
                        {{ t('Invoice-এর line') }}
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <KButton size="sm" icon="plus" @click="add('ad_budget')"
                            >Advance ad budget</KButton
                        >
                        <KButton size="sm" icon="plus" @click="add('service')"
                            >Service</KButton
                        >
                        <KButton
                            size="sm"
                            icon="plus"
                            variant="quiet"
                            @click="add('other')"
                            >{{ t('অন্যান্য') }}</KButton
                        >
                    </div>
                </div>
                <p
                    v-if="form.errors.items"
                    class="px-5 pt-3 text-[12.5px] text-out"
                >
                    {{ form.errors.items }}
                </p>
                <p
                    v-if="!form.items.length"
                    class="px-5 py-8 text-center text-[13px] text-ink-3"
                >
                    {{
                        t(
                            'এখনো কোনো line নেই — উপর থেকে campaign আনুন বা line যোগ করুন।',
                        )
                    }}
                </p>
                <ul class="divide-y divide-line">
                    <li
                        v-for="(line, index) in form.items"
                        :key="index"
                        class="grid grid-cols-1 gap-3 p-4 sm:p-5"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="eyebrow">{{
                                kindLabel(line.kind)
                            }}</span>
                            <button
                                type="button"
                                class="grid size-8 place-items-center rounded-lg text-ink-3 hover:bg-out-soft hover:text-out"
                                :aria-label="t('সরান')"
                                @click="remove(index)"
                            >
                                <KIcon name="close" :size="16" />
                            </button>
                        </div>
                        <KField :error="errorAt(index, 'description')">
                            <KInput
                                v-model="line.description"
                                :invalid="!!errorAt(index, 'description')"
                                :placeholder="
                                    isUsd(line)
                                        ? line.kind === 'ad_spend'
                                            ? 'Campaign'
                                            : 'Advance ad budget'
                                        : t('বিবরণ')
                                "
                            />
                        </KField>
                        <p
                            v-if="line.kind === 'ad_spend' && line.period_from"
                            class="num -mt-1 text-[12px] text-ink-3"
                        >
                            {{ niceDate(line.period_from, false) }} –
                            {{ niceDate(line.period_to) }}
                            <template v-if="line.results !== null">
                                ·
                                {{
                                    t('{n} result', { n: line.results })
                                }}</template
                            >
                            <template v-if="line.clicks !== null">
                                ·
                                {{
                                    t('{n} click', { n: line.clicks })
                                }}</template
                            >
                        </p>
                        <div
                            v-if="line.kind === 'ad_budget'"
                            class="grid grid-cols-1 gap-1.5"
                        >
                            <KField
                                :label="t('কোন campaign-এর দিন বাড়বে')"
                                optional
                            >
                                <KSelect
                                    v-model="line.campaign_id"
                                    :options="clientCampaigns"
                                    clearable
                                    :placeholder="
                                        t('Campaign ছাড়া — শুধু আগাম টাকা')
                                    "
                                />
                            </KField>
                            <p
                                v-if="campaignOf(line)"
                                class="text-[12px]"
                                :class="
                                    daysFor(line)
                                        ? 'text-brand-ink'
                                        : 'text-ink-3'
                                "
                            >
                                <template v-if="!campaignOf(line)?.budget">{{
                                    t(
                                        'এই campaign-এর দিনের budget জানা নেই — Campaign পাতায় Pilot থেকে আনুন।',
                                    )
                                }}</template>
                                <template v-else-if="daysFor(line)">{{
                                    t(
                                        '{usd} = {days} দিন ({budget}/দিন) — টাকা এলে approve করলে Meta-তে শেষ {date} পর্যন্ত বাড়বে।',
                                        {
                                            usd: money(cents(line.usd), 'usd'),
                                            days: daysFor(line) ?? 0,
                                            budget: money(
                                                campaignOf(line)?.budget ?? 0,
                                                'usd',
                                            ),
                                            date: niceDate(newEnd(line)),
                                        },
                                    )
                                }}</template>
                                <template v-else>{{
                                    t(
                                        'USD দিন — {budget}/দিন হিসাবে কত দিন বাড়বে দেখাবে।',
                                        {
                                            budget: money(
                                                campaignOf(line)?.budget ?? 0,
                                                'usd',
                                            ),
                                        },
                                    )
                                }}</template>
                            </p>
                        </div>
                        <div
                            v-if="isUsd(line)"
                            class="grid grid-cols-2 gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                        >
                            <KField label="USD" :error="errorAt(index, 'usd')">
                                <KMoney
                                    v-model="line.usd"
                                    currency="usd"
                                    :invalid="!!errorAt(index, 'usd')"
                                />
                            </KField>
                            <KField
                                :label="t('Rate (প্রতি USD)')"
                                :error="errorAt(index, 'rate')"
                            >
                                <KInput
                                    v-model="line.rate"
                                    inputmode="decimal"
                                    :invalid="!!errorAt(index, 'rate')"
                                />
                            </KField>
                            <p
                                class="num col-span-2 text-right text-[15px] font-semibold sm:col-span-1 sm:pb-2.5"
                            >
                                {{ bdt(lineBdt(line)) }}
                            </p>
                        </div>
                        <KField
                            v-else
                            label="BDT"
                            :error="errorAt(index, 'amount')"
                        >
                            <KMoney
                                v-model="line.amount"
                                currency="bdt"
                                :invalid="!!errorAt(index, 'amount')"
                            />
                        </KField>
                    </li>
                </ul>
            </section>

            <section class="panel p-5 sm:p-6">
                <KField
                    :label="t('Client-এর জন্য নোট')"
                    optional
                    :error="form.errors.notes"
                >
                    <KTextarea v-model="form.notes" :rows="3" />
                </KField>
            </section>
        </div>

        <aside class="flex flex-col gap-4 xl:sticky xl:top-24 xl:self-start">
            <section class="panel p-5">
                <p class="eyebrow">
                    {{ invoice ? invoice.number : next_number }}
                </p>
                <dl class="mt-3 space-y-2 text-[13.5px]">
                    <div
                        v-if="totalUsd"
                        class="flex justify-between text-ink-2"
                    >
                        <dt>{{ t('Ad খরচ (USD)') }}</dt>
                        <dd class="num">{{ money(totalUsd, 'usd') }}</dd>
                    </div>
                    <div
                        class="flex justify-between border-t border-line pt-2 text-[20px] font-bold"
                    >
                        <dt>{{ t('মোট') }}</dt>
                        <dd class="num">{{ bdt(total) }}</dd>
                    </div>
                </dl>
                <KButton
                    type="submit"
                    variant="primary"
                    block
                    class="mt-4"
                    :loading="form.processing"
                    :disabled="!form.items.length || !form.client_id"
                    >{{
                        invoice ? t('সংরক্ষণ') : t('Invoice তৈরি করুন')
                    }}</KButton
                >
                <p class="mt-2 text-[11.5px] text-ink-3">
                    {{
                        t(
                            'Client-কে পাঠানোর আগ পর্যন্ত বদলানো যায়; পাঠানোর পর আর বদলাবে না।',
                        )
                    }}
                </p>
            </section>
            <section class="panel p-5 text-[12.5px] text-ink-2">
                <p class="font-semibold text-ink">{{ t('Client rate') }}</p>
                <p class="mt-1">
                    {{
                        pricing.fx_rate
                            ? t(
                                  'FX batch {fx} + {commission}% commission = {rate} প্রতি USD',
                                  {
                                      fx: taka(
                                          Math.round(pricing.fx_rate * 100) /
                                              100,
                                      ),
                                      commission: pricing.commission,
                                      rate: taka(pricing.rate),
                                  },
                              )
                            : t('কোনো FX batch নেই — rate নিজে লিখুন।')
                    }}
                </p>
                <p class="mt-1 text-ink-3">
                    {{
                        t(
                            'প্রতি line-এ rate বদলানো যায়। এই হিসাব client দেখবেন না।',
                        )
                    }}
                </p>
            </section>
        </aside>
    </form>
</template>
