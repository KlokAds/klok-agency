<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

export type ForecastCampaign = {
    id: number;
    name: string;
    live: boolean;
    paused_for_credit: boolean;
    pending: number;
    end: string | null;
    days_left: number | null;
    budget: number | null;
    to_spend: number | null;
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
    campaigns: ForecastCampaign[];
};

// Meta campaign by campaign: what the card still owes Meta for it, what it
// will spend to its end, and per client whether their money covers that.
defineProps<{
    forecast: {
        clients: ForecastClient[];
        pending: number;
        other: number;
        rate: number | null;
    };
}>();
</script>

<template>
    <section class="panel min-w-0 overflow-hidden xl:col-span-2">
        <div
            class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5 sm:px-6 sm:pt-6"
        >
            <div>
                <h2 class="text-[16px] font-bold">
                    {{ t('Meta — campaign অনুযায়ী') }}
                </h2>
                <p class="mt-1 max-w-2xl text-[12.5px] text-ink-3">
                    {{
                        t(
                            'কোন campaign-এর জন্য Meta এখনো কত কাটবে, শেষের তারিখ পর্যন্ত আর কত খরচ হবে, আর client-এর দেওয়া টাকায় তা হয় কিনা।',
                        )
                    }}
                </p>
            </div>
            <p class="text-right text-[12.5px] text-ink-3">
                {{ t('Meta এখনো কাটবে') }}
                <span class="num block text-[17px] font-semibold text-wait">{{
                    money(forecast.pending, 'usd')
                }}</span>
            </p>
        </div>

        <p
            v-if="!forecast.clients.length"
            class="px-5 py-6 text-[13px] text-ink-3 sm:px-6"
        >
            {{
                t(
                    'Client-এর সাথে যুক্ত কোনো Meta campaign নেই — Campaign পাতায় যুক্ত করুন।',
                )
            }}
        </p>

        <div v-else class="mt-4 overflow-x-auto">
            <table class="ledger w-full min-w-[820px] text-[13px]">
                <thead>
                    <tr>
                        <th class="px-5 py-2.5 text-left sm:px-6">Campaign</th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('Meta কাটবে') }}
                        </th>
                        <th class="px-3 py-2.5 text-left">{{ t('শেষ') }}</th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('শেষ পর্যন্ত খরচ') }}
                        </th>
                        <th class="px-5 py-2.5 text-right sm:px-6">
                            {{ t('Client-এর হিসাব') }}
                        </th>
                    </tr>
                </thead>
                <tbody v-for="c in forecast.clients" :key="c.id">
                    <tr class="bg-surface-2">
                        <td class="px-5 py-2.5 sm:px-6" colspan="2">
                            <Link
                                :href="`/clients/${c.id}`"
                                class="font-semibold hover:text-brand"
                                >{{ c.name }}</Link
                            >
                            <span
                                v-if="c.last_paid"
                                class="ml-2 text-[12px] text-ink-3"
                                >{{
                                    c.last_paid.date
                                        ? t('শেষ টাকা {date} — {amount}', {
                                              date: niceDate(
                                                  c.last_paid.date,
                                                  false,
                                              ),
                                              amount: money(
                                                  c.last_paid.amount,
                                                  'bdt',
                                              ),
                                          })
                                        : t('শেষ টাকা {amount}', {
                                              amount: money(
                                                  c.last_paid.amount,
                                                  'bdt',
                                              ),
                                          })
                                }}<template v-if="c.last_paid.usd">
                                    ({{
                                        money(c.last_paid.usd, 'usd')
                                    }})</template
                                ></span
                            >
                        </td>
                        <td class="px-3 py-2.5 text-[12px] text-ink-3">
                            <template v-if="c.prepaid">
                                {{ t('বাকি credit') }}
                                <b class="num text-ink">{{
                                    money(c.credit_left, 'usd')
                                }}</b>
                            </template>
                            <template v-else
                                >{{ t('খরচের পরে বিল') }} ·
                                {{ t('এখনো পাওনা') }}
                                <b class="num text-ink">{{
                                    money(c.owed, 'usd')
                                }}</b></template
                            >
                        </td>
                        <td class="num px-3 py-2.5 text-right font-semibold">
                            {{ money(c.to_spend, 'usd') }}
                        </td>
                        <td class="px-5 py-2.5 text-right sm:px-6">
                            <span
                                v-if="c.balance < 0"
                                class="font-semibold text-out"
                                >{{ t('Due') }}
                                <span class="num">{{
                                    money(-c.balance, 'usd')
                                }}</span>
                                <span
                                    v-if="c.due_bdt"
                                    class="num block text-[11.5px] font-medium"
                                    >≈ {{ money(c.due_bdt, 'bdt') }}</span
                                ></span
                            >
                            <span v-else class="font-semibold text-in"
                                >{{ t('বেশি আছে') }}
                                <span class="num">{{
                                    money(c.balance, 'usd')
                                }}</span></span
                            >
                        </td>
                    </tr>
                    <tr v-for="k in c.campaigns" :key="k.id">
                        <td class="max-w-[340px] py-2 pr-3 pl-9 sm:pl-10">
                            <span class="flex items-center gap-2">
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="k.live ? 'bg-in' : 'bg-line-2'"
                                />
                                <span class="truncate">{{ k.name }}</span>
                            </span>
                            <span
                                v-if="k.paused_for_credit"
                                class="pl-3.5 text-[11.5px] text-out"
                                >{{ t('টাকা শেষ — বন্ধ') }}</span
                            >
                        </td>
                        <td
                            class="num px-3 py-2 text-right"
                            :class="k.pending ? 'text-wait' : 'text-ink-3'"
                        >
                            {{ money(k.pending, 'usd') }}
                        </td>
                        <td class="px-3 py-2 text-ink-2">
                            <template v-if="!k.live">{{ t('বন্ধ') }}</template>
                            <template v-else-if="k.end">
                                {{ niceDate(k.end, false) }}
                                <span class="text-[11.5px] text-ink-3">{{
                                    t('· {n} দিন', { n: k.days_left ?? 0 })
                                }}</span>
                            </template>
                            <template v-else>{{ t('শেষের দিন নেই') }}</template>
                        </td>
                        <td class="num px-3 py-2 text-right text-ink-2">
                            <template v-if="k.to_spend !== null">
                                {{ money(k.to_spend, 'usd') }}
                                <span
                                    v-if="k.budget"
                                    class="block text-[11.5px] text-ink-3"
                                    >{{ money(k.budget, 'usd') }} ×
                                    {{ k.days_left }}</span
                                >
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td />
                    </tr>
                    <tr v-if="c.loose">
                        <td
                            class="py-2 pr-3 pl-9 text-[12.5px] text-ink-3 sm:pl-10"
                        >
                            {{ t('হাতে লেখা খরচ (campaign ছাড়া)') }}
                        </td>
                        <td class="num px-3 py-2 text-right text-wait">
                            {{ money(c.loose, 'usd') }}
                        </td>
                        <td colspan="3" />
                    </tr>
                </tbody>
            </table>
        </div>
        <p
            v-if="forecast.other"
            class="px-5 py-3 text-[12px] text-ink-3 sm:px-6"
        >
            {{
                t('Client ছাড়া Meta খরচ (নিজের ad, adjustment): {amount}', {
                    amount: money(forecast.other, 'usd'),
                })
            }}
        </p>
    </section>
</template>
