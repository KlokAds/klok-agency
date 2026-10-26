<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KTag from '@/components/k/KTag.vue';
import { endsAt, money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

export type ForecastCampaign = {
    id: number;
    name: string;
    live: boolean;
    paused_for_credit: boolean;
    pending: number;
    end: string | null;
    ends_at: string | null;
    ended: boolean;
    days_left: number | null;
    budget: number | null;
    to_spend: number | null;
    renewal: { id: number; status: string } | null;
    spent: number;
    extendable: boolean;
};

export type ForecastClient = {
    id: number;
    name: string;
    prepaid: boolean;
    credit_left: number | null;
    owed: number | null;
    last_paid: {
        date: string | null;
        amount: number;
        usd: number | null;
    } | null;
    pending: number;
    loose: number;
    to_spend: number;
    balance: number;
    due_bdt: number | null;
    due_now: number;
    due_now_bdt: number | null;
    credit_now: number;
    daily: number;
    lasts_until: string | null;
    ends_on: string | null;
    needed: number;
    collected: number;
    collected_usd: number;
    campaigns: ForecastCampaign[];
};

type Row =
    | { kind: 'campaign'; campaign: ForecastCampaign }
    | { kind: 'loose'; amount: number };

// One row per campaign, a client's campaigns one under another: the
// client's money (last payment, due now, next payment, collected) once
// for them all, and each campaign's end, what Meta still takes off the
// card for it, its spend, and Extend.
const props = defineProps<{
    forecast: {
        clients: ForecastClient[];
        pending: number;
        other: number;
        rate: number | null;
    };
}>();

const can = computed(() => usePage().props.auth.can);

function rows(c: ForecastClient): Row[] {
    const list: Row[] = c.campaigns.map((campaign) => ({
        kind: 'campaign',
        campaign,
    }));

    if (c.loose) {
        list.push({ kind: 'loose', amount: c.loose });
    }

    return list.length ? list : [{ kind: 'loose', amount: 0 }];
}

const toBdt = (cents: number) =>
    props.forecast.rate ? Math.round(cents * props.forecast.rate) : null;

const RENEWAL: Record<string, string> = {
    open: 'Renewal link পাঠানো', // i18n
    invoiced: 'Invoice — টাকার অপেক্ষা', // i18n
    paid: 'টাকা এসেছে — approve করুন', // i18n
    declined: 'Contract শেষ', // i18n
};

// Extend: more days on Meta, from this row.
const extending = ref<ForecastCampaign | null>(null);
const extendOpen = ref(false);
const extendDays = ref('7');
const extendError = ref<string | null>(null);
const saving = ref(false);

function startExtend(k: ForecastCampaign): void {
    extending.value = k;
    extendDays.value = '7';
    extendError.value = null;
    extendOpen.value = true;
}

function extend(): void {
    if (!extending.value) {
        return;
    }

    router.post(
        `/campaigns/${extending.value.id}/extend`,
        { days: Number.parseInt(extendDays.value, 10) || 0 },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => (extendOpen.value = false),
            onError: (e) => (extendError.value = e.days ?? null),
        },
    );
}

const extendCost = computed(() => {
    const days = Number.parseInt(extendDays.value, 10) || 0;
    const usd = (extending.value?.budget ?? 0) * days;

    return { usd, bdt: toBdt(usd) };
});
</script>

<template>
    <section class="panel min-w-0 overflow-hidden xl:col-span-2">
        <div
            class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5 sm:px-6 sm:pt-6"
        >
            <div>
                <h2 class="text-[16px] font-bold">
                    {{ t('Client অনুযায়ী — Meta') }}
                </h2>
                <p class="mt-1 max-w-2xl text-[13.5px] text-ink-3">
                    {{
                        t(
                            'প্রতিটা client এখন কোথায়: শেষ কবে কত দিয়েছে, due না টাকা বাকি, campaign কবে শেষ, আর পরের payment কত লাগবে। নতুন টাকা আগে পুরনো due শোধ করে, বাকিটায় দিন বাড়ে।',
                        )
                    }}
                </p>
            </div>
            <p class="text-[13.5px] whitespace-nowrap text-ink-3 sm:text-right">
                {{ t('Meta এখনো কাটবে') }}
                <span class="num block text-[17px] font-semibold text-wait">{{
                    money(forecast.pending, 'usd')
                }}</span>
            </p>
        </div>

        <KEmpty
            v-if="!forecast.clients.length"
            :title="t('কোনো campaign পাওয়া যায়নি')"
            :body="
                t(
                    'Client-এর সাথে যুক্ত কোনো Meta campaign নেই — Campaign পাতায় যুক্ত করুন।',
                )
            "
        />

        <div v-else class="mt-4 overflow-x-auto">
            <table class="ledger w-full min-w-[1120px] text-[14px]">
                <thead>
                    <tr>
                        <th class="px-5 py-2.5 text-left sm:px-6">Client</th>
                        <th class="px-3 py-2.5 text-left">Campaign</th>
                        <th class="px-3 py-2.5 text-left">{{ t('শেষ') }}</th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('Meta বাকি') }}
                        </th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('এখন due') }}
                        </th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('পরের payment') }}
                        </th>
                        <th class="px-3 py-2.5 text-center">Extend</th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('মোট খরচ') }}
                        </th>
                        <th class="px-5 py-2.5 text-right sm:px-6">
                            {{ t('মোট পাওয়া') }}
                        </th>
                    </tr>
                </thead>
                <tbody v-for="c in forecast.clients" :key="c.id">
                    <tr
                        v-for="(row, i) in rows(c)"
                        :key="
                            row.kind === 'campaign' ? row.campaign.id : 'loose'
                        "
                        class="align-top"
                    >
                        <!-- Client: once for all their campaigns -->
                        <td
                            v-if="i === 0"
                            :rowspan="rows(c).length"
                            class="w-[190px] border-r border-line px-5 py-3 sm:px-6"
                        >
                            <Link
                                :href="`/clients/${c.id}`"
                                class="font-semibold hover:text-brand"
                                >{{ c.name }}</Link
                            >
                            <p class="mt-0.5 text-[13px] text-ink-3">
                                <template v-if="c.last_paid">
                                    {{ t('শেষ payment') }}
                                    <template v-if="c.last_paid.date"
                                        ><br />{{
                                            niceDate(c.last_paid.date, false)
                                        }}
                                        ·
                                    </template>
                                    <span class="num">{{
                                        money(c.last_paid.amount, 'bdt')
                                    }}</span>
                                </template>
                                <template v-else>{{
                                    t('এখনো কোনো payment নেই')
                                }}</template>
                            </p>
                            <p class="mt-1 text-[13px] text-ink-3">
                                {{
                                    c.prepaid
                                        ? t('আগে টাকা দেয়')
                                        : t('খরচের পরে বিল')
                                }}
                            </p>
                        </td>

                        <!-- Campaign -->
                        <template v-if="row.kind === 'campaign'">
                            <td class="max-w-[260px] px-3 py-3">
                                <span class="flex items-center gap-2">
                                    <span
                                        class="size-1.5 shrink-0 rounded-full"
                                        :class="
                                            row.campaign.live
                                                ? 'bg-in'
                                                : 'bg-line-2'
                                        "
                                    />
                                    <span
                                        class="truncate font-medium"
                                        :title="row.campaign.name"
                                        >{{ row.campaign.name }}</span
                                    >
                                </span>
                                <span
                                    v-if="row.campaign.budget"
                                    class="num pl-3.5 text-[13px] text-ink-3"
                                    >{{ money(row.campaign.budget, 'usd') }}/{{
                                        t('দিন')
                                    }}</span
                                >
                                <span
                                    v-if="
                                        row.campaign.paused_for_credit ||
                                        row.campaign.renewal
                                    "
                                    class="mt-1 flex flex-wrap gap-1.5 pl-3.5"
                                >
                                    <KTag
                                        v-if="row.campaign.paused_for_credit"
                                        tone="out"
                                        >{{ t('টাকা শেষ — বন্ধ') }}</KTag
                                    >
                                    <Link
                                        v-if="row.campaign.renewal"
                                        :href="`/renewals/${row.campaign.renewal.id}`"
                                        ><KTag
                                            :tone="
                                                row.campaign.renewal.status ===
                                                'paid'
                                                    ? 'wait'
                                                    : 'neutral'
                                            "
                                            >{{
                                                t(
                                                    RENEWAL[
                                                        row.campaign.renewal
                                                            .status
                                                    ] ??
                                                        row.campaign.renewal
                                                            .status,
                                                )
                                            }}</KTag
                                        ></Link
                                    >
                                </span>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                <template v-if="row.campaign.ended">
                                    {{ t('শেষ হয়েছে') }}
                                    <span
                                        class="block text-[13px] text-ink-3"
                                        >{{
                                            endsAt(row.campaign.ends_at)
                                        }}</span
                                    >
                                </template>
                                <template v-else-if="!row.campaign.live">{{
                                    t('বন্ধ')
                                }}</template>
                                <template v-else-if="row.campaign.ends_at">
                                    {{ endsAt(row.campaign.ends_at) }}
                                    <span
                                        class="block text-[13px] text-ink-3"
                                        >{{
                                            t('{n} দিন বাকি', {
                                                n: row.campaign.days_left ?? 0,
                                            })
                                        }}</span
                                    >
                                </template>
                                <span v-else class="text-ink-3">{{
                                    t('শেষের দিন নেই')
                                }}</span>
                            </td>
                            <td
                                class="num px-3 py-3 text-right"
                                :class="
                                    row.campaign.pending
                                        ? 'text-wait'
                                        : 'text-ink-3'
                                "
                            >
                                {{ money(row.campaign.pending, 'usd') }}
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-3 py-3 text-[13.5px] text-ink-3">
                                {{
                                    row.amount
                                        ? t('হাতে লেখা খরচ (campaign ছাড়া)')
                                        : t('কোনো চালু campaign নেই।')
                                }}
                            </td>
                            <td class="px-3 py-3" />
                            <td
                                class="num px-3 py-3 text-right"
                                :class="row.amount ? 'text-wait' : 'text-ink-3'"
                            >
                                {{ row.amount ? money(row.amount, 'usd') : '' }}
                            </td>
                        </template>

                        <!-- Client money: once -->
                        <template v-if="i === 0">
                            <td
                                :rowspan="rows(c).length"
                                class="border-l border-line px-3 py-3 text-right"
                            >
                                <template v-if="c.due_now > 0">
                                    <span
                                        class="num block font-semibold text-out"
                                        >{{ money(c.due_now, 'usd') }}</span
                                    >
                                    <span
                                        v-if="c.due_now_bdt"
                                        class="num block text-[13px] text-ink-3"
                                        >≈
                                        {{ money(c.due_now_bdt, 'bdt') }}</span
                                    >
                                </template>
                                <template v-else-if="c.credit_now > 0">
                                    <span
                                        class="num block font-semibold text-in"
                                        >+{{ money(c.credit_now, 'usd') }}</span
                                    >
                                    <span class="block text-[13px] text-ink-3"
                                        >{{ t('টাকা বাকি') }}
                                        <template v-if="c.lasts_until">
                                            ·
                                            {{
                                                niceDate(c.lasts_until, false)
                                            }}</template
                                        ></span
                                    >
                                </template>
                                <span v-else class="text-ink-3">{{
                                    t('মিলে আছে')
                                }}</span>
                            </td>
                            <td
                                :rowspan="rows(c).length"
                                class="px-3 py-3 text-right"
                            >
                                <template v-if="c.needed > 0">
                                    <span class="num block font-semibold">{{
                                        money(c.needed, 'usd')
                                    }}</span>
                                    <span
                                        v-if="c.due_bdt"
                                        class="num block text-[13px] text-ink-3"
                                        >≈ {{ money(c.due_bdt, 'bdt') }}</span
                                    >
                                    <span
                                        v-if="c.ends_on"
                                        class="block text-[13px] text-ink-3"
                                        >{{
                                            t('{date} পর্যন্ত', {
                                                date: niceDate(
                                                    c.ends_on,
                                                    false,
                                                ),
                                            })
                                        }}</span
                                    >
                                </template>
                                <span v-else class="text-in">{{
                                    t('লাগবে না')
                                }}</span>
                            </td>
                        </template>

                        <!-- Extend and spend: per campaign -->
                        <td class="px-3 py-3 text-center">
                            <KButton
                                v-if="
                                    row.kind === 'campaign' &&
                                    row.campaign.extendable &&
                                    can['clients.edit']
                                "
                                size="sm"
                                icon="renew"
                                @click="startExtend(row.campaign)"
                                >Extend</KButton
                            >
                        </td>
                        <td class="num px-3 py-3 text-right">
                            <template v-if="row.kind === 'campaign'">{{
                                money(row.campaign.spent, 'usd')
                            }}</template>
                        </td>

                        <td
                            v-if="i === 0"
                            :rowspan="rows(c).length"
                            class="border-l border-line px-5 py-3 text-right sm:px-6"
                        >
                            <span class="num block font-semibold">{{
                                money(c.collected, 'bdt')
                            }}</span>
                            <span
                                v-if="c.collected_usd"
                                class="num block text-[13px] text-ink-3"
                                >{{ money(c.collected_usd, 'usd') }}
                                {{ t('ad-এর জন্য') }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p
            v-if="forecast.other"
            class="px-5 py-3 text-[13px] text-ink-3 sm:px-6"
        >
            {{
                t('Client ছাড়া Meta খরচ (নিজের ad, adjustment): {amount}', {
                    amount: money(forecast.other, 'usd'),
                })
            }}
        </p>

        <KModal v-model="extendOpen" :title="extending?.name ?? 'Campaign'">
            <p class="text-[14.5px] text-ink-2">
                {{
                    t(
                        'Campaign আরও কত দিন চলবে? শেষের তারিখ Meta-তে বাড়বে; টাকা শেষ হয়ে বন্ধ থাকলে আবার চালু হবে। Client-এর টাকা খাতায় না থাকলে spend cap আবার থামিয়ে দেবে।',
                    )
                }}
            </p>
            <KField
                :label="t('কত দিন')"
                :error="extendError ?? undefined"
                class="mt-4"
                :hint="
                    extending?.end
                        ? t('এখন শেষ {date}', {
                              date: niceDate(extending.end, false),
                          })
                        : undefined
                "
            >
                <KInput
                    v-model="extendDays"
                    inputmode="numeric"
                    class="max-w-[140px]"
                >
                    <template #trail
                        ><span class="text-[13.5px] text-ink-3">{{
                            t('দিন')
                        }}</span></template
                    >
                </KInput>
            </KField>
            <p
                v-if="extending?.budget"
                class="num mt-3 rounded-lg bg-sunken px-3 py-2 text-[13.5px]"
            >
                {{ money(extending.budget, 'usd') }} ×
                {{ Number.parseInt(extendDays, 10) || 0 }} =
                <b>{{ money(extendCost.usd, 'usd') }}</b>
                <template v-if="extendCost.bdt">
                    ≈ {{ money(extendCost.bdt, 'bdt') }}</template
                >
            </p>
            <template #footer>
                <KButton variant="quiet" @click="extendOpen = false">{{
                    t('বাতিল')
                }}</KButton>
                <KButton
                    variant="primary"
                    icon="renew"
                    :loading="saving"
                    @click="extend"
                    >Extend</KButton
                >
            </template>
        </KModal>
    </section>
</template>
