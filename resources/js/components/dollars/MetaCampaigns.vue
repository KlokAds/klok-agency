<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import KTag from '@/components/k/KTag.vue';
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
    renewal: { id: number; status: string } | null;
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
    campaigns: ForecastCampaign[];
};

// Client by client: where they stand now (due or money left), when they
// last paid, their campaigns with end dates, what Meta still takes off the
// card, and what the next payment has to be — the old due first, then the
// days to come.
defineProps<{
    forecast: {
        clients: ForecastClient[];
        pending: number;
        other: number;
        rate: number | null;
    };
}>();

const RENEWAL: Record<string, string> = {
    open: 'Renewal link পাঠানো', // i18n
    invoiced: 'Invoice — টাকার অপেক্ষা', // i18n
    paid: 'টাকা এসেছে — approve করুন', // i18n
    declined: 'Contract শেষ', // i18n
};
</script>

<template>
    <section class="min-w-0 xl:col-span-2">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-[16px] font-bold">
                    {{ t('Client অনুযায়ী — Meta') }}
                </h2>
                <p class="mt-1 max-w-2xl text-[12.5px] text-ink-3">
                    {{
                        t(
                            'প্রতিটা client এখন কোথায়: শেষ কবে কত দিয়েছে, due না টাকা বাকি, campaign কবে শেষ, আর পরের payment কত লাগবে। নতুন টাকা আগে পুরনো due শোধ করে, বাকিটায় দিন বাড়ে।',
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
            class="panel px-5 py-6 text-[13px] text-ink-3 sm:px-6"
        >
            {{
                t(
                    'Client-এর সাথে যুক্ত কোনো Meta campaign নেই — Campaign পাতায় যুক্ত করুন।',
                )
            }}
        </p>

        <div class="grid gap-4 lg:grid-cols-2">
            <article
                v-for="c in forecast.clients"
                :key="c.id"
                class="panel min-w-0 overflow-hidden"
            >
                <!-- Who, last payment, where they stand -->
                <header
                    class="flex flex-wrap items-start justify-between gap-3 px-5 pt-4"
                >
                    <div class="min-w-0">
                        <Link
                            :href="`/clients/${c.id}`"
                            class="text-[15px] font-semibold hover:text-brand"
                            >{{ c.name }}</Link
                        >
                        <p class="mt-0.5 text-[12px] text-ink-3">
                            <template v-if="c.last_paid">
                                {{ t('শেষ payment') }}
                                <template v-if="c.last_paid.date"
                                    >{{ niceDate(c.last_paid.date, false) }} ·
                                </template>
                                <span class="num">{{
                                    money(c.last_paid.amount, 'bdt')
                                }}</span>
                                <template v-if="c.last_paid.usd">
                                    ({{
                                        money(c.last_paid.usd, 'usd')
                                    }})</template
                                >
                            </template>
                            <template v-else>{{
                                t('এখনো কোনো payment নেই')
                            }}</template>
                            ·
                            {{
                                c.prepaid
                                    ? t('আগে টাকা দেয়')
                                    : t('খরচের পরে বিল')
                            }}
                        </p>
                    </div>
                    <div class="text-right">
                        <template v-if="c.due_now > 0">
                            <p class="text-[11.5px] text-out">
                                {{ t('এখন due') }}
                            </p>
                            <p class="num text-[18px] font-semibold text-out">
                                {{ money(c.due_now, 'usd') }}
                            </p>
                            <p
                                v-if="c.due_now_bdt"
                                class="num text-[11.5px] text-ink-3"
                            >
                                ≈ {{ money(c.due_now_bdt, 'bdt') }}
                            </p>
                        </template>
                        <template v-else-if="c.credit_now > 0">
                            <p class="text-[11.5px] text-in">
                                {{ t('টাকা বাকি') }}
                            </p>
                            <p class="num text-[18px] font-semibold text-in">
                                {{ money(c.credit_now, 'usd') }}
                            </p>
                        </template>
                        <KTag v-else tone="neutral">{{ t('মিলে আছে') }}</KTag>
                    </div>
                </header>

                <!-- The four numbers -->
                <dl
                    class="mx-5 mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line text-[12px] sm:grid-cols-4"
                >
                    <div class="bg-surface px-3 py-2">
                        <dt class="text-ink-3">{{ t('Meta কাটবে') }}</dt>
                        <dd class="num mt-0.5 font-semibold text-wait">
                            {{ money(c.pending, 'usd') }}
                        </dd>
                    </div>
                    <div class="bg-surface px-3 py-2">
                        <dt class="text-ink-3">{{ t('শেষ পর্যন্ত খরচ') }}</dt>
                        <dd class="num mt-0.5 font-semibold">
                            {{ money(c.to_spend, 'usd') }}
                        </dd>
                    </div>
                    <div class="bg-surface px-3 py-2">
                        <dt class="text-ink-3">{{ t('টাকায় চলবে') }}</dt>
                        <dd class="mt-0.5 font-semibold">
                            <template v-if="!c.prepaid">—</template>
                            <template v-else-if="c.lasts_until">{{
                                niceDate(c.lasts_until, false)
                            }}</template>
                            <span v-else class="text-out">{{
                                t('টাকা শেষ')
                            }}</span>
                        </dd>
                    </div>
                    <div class="bg-surface px-3 py-2">
                        <dt class="text-ink-3">{{ t('পরের payment') }}</dt>
                        <dd
                            class="num mt-0.5 font-semibold"
                            :class="c.needed > 0 ? 'text-out' : 'text-in'"
                        >
                            <template v-if="c.needed > 0"
                                >{{ money(c.needed, 'usd') }}
                                <span
                                    v-if="c.due_bdt"
                                    class="block text-[11px] font-medium text-ink-3"
                                    >≈ {{ money(c.due_bdt, 'bdt') }}</span
                                ></template
                            >
                            <template v-else>{{ t('লাগবে না') }}</template>
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="c.needed > 0"
                    class="mx-5 mt-2 text-[11.5px] text-ink-3"
                >
                    {{
                        c.due_now > 0 && !c.to_spend
                            ? t('আগের due {due} শোধ করতে হবে।', {
                                  due: money(c.due_now, 'usd'),
                              })
                            : c.due_now > 0
                              ? t(
                                    'আগের due {due} শোধ, তারপর শেষ ({end}) পর্যন্ত {rest}।',
                                    {
                                        due: money(c.due_now, 'usd'),
                                        end: c.ends_on
                                            ? niceDate(c.ends_on, false)
                                            : '—',
                                        rest: money(c.to_spend, 'usd'),
                                    },
                                )
                              : t(
                                    'বাকি টাকা বাদে শেষ ({end}) পর্যন্ত চালাতে লাগবে।',
                                    {
                                        end: c.ends_on
                                            ? niceDate(c.ends_on, false)
                                            : '—',
                                    },
                                )
                    }}
                </p>

                <!-- Campaigns -->
                <ul class="mt-3 border-t border-line">
                    <li
                        v-for="k in c.campaigns"
                        :key="k.id"
                        class="flex items-start gap-3 border-b border-line px-5 py-2.5 text-[12.5px] last:border-0"
                    >
                        <span
                            class="mt-1.5 size-1.5 shrink-0 rounded-full"
                            :class="k.live ? 'bg-in' : 'bg-line-2'"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{
                                k.name
                            }}</span>
                            <span class="text-ink-3">
                                <template v-if="k.budget"
                                    >{{ money(k.budget, 'usd') }}/{{ t('দিন') }}
                                    ·
                                </template>
                                <template v-if="!k.live">{{
                                    t('বন্ধ')
                                }}</template>
                                <template v-else-if="k.end"
                                    >{{ t('শেষ') }}
                                    {{ niceDate(k.end, false) }} ({{
                                        t('{n} দিন', { n: k.days_left ?? 0 })
                                    }})</template
                                >
                                <template v-else>{{
                                    t('শেষের দিন নেই')
                                }}</template>
                            </span>
                            <span
                                v-if="k.paused_for_credit || k.renewal"
                                class="mt-1 flex flex-wrap gap-1.5"
                            >
                                <KTag v-if="k.paused_for_credit" tone="out">{{
                                    t('টাকা শেষ — বন্ধ')
                                }}</KTag>
                                <Link
                                    v-if="k.renewal"
                                    :href="`/renewals/${k.renewal.id}`"
                                    ><KTag
                                        :tone="
                                            k.renewal.status === 'paid'
                                                ? 'wait'
                                                : k.renewal.status ===
                                                    'declined'
                                                  ? 'out'
                                                  : 'neutral'
                                        "
                                        >{{
                                            t(
                                                RENEWAL[k.renewal.status] ??
                                                    k.renewal.status,
                                            )
                                        }}</KTag
                                    ></Link
                                >
                            </span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span class="num block">{{
                                k.to_spend === null
                                    ? '—'
                                    : money(k.to_spend, 'usd')
                            }}</span>
                            <span
                                v-if="k.pending"
                                class="num block text-[11px] text-wait"
                                >{{ t('Meta কাটবে') }}
                                {{ money(k.pending, 'usd') }}</span
                            >
                        </span>
                    </li>
                    <li
                        v-if="c.loose"
                        class="flex justify-between gap-3 px-5 py-2.5 text-[12.5px] text-ink-3"
                    >
                        <span>{{ t('হাতে লেখা খরচ (campaign ছাড়া)') }}</span>
                        <span class="num text-wait"
                            >{{ t('Meta কাটবে') }}
                            {{ money(c.loose, 'usd') }}</span
                        >
                    </li>
                    <li
                        v-if="!c.campaigns.length && !c.loose"
                        class="px-5 py-2.5 text-[12.5px] text-ink-3"
                    >
                        {{ t('কোনো চালু campaign নেই।') }}
                    </li>
                </ul>
            </article>
        </div>

        <p v-if="forecast.other" class="mt-3 text-[12px] text-ink-3">
            {{
                t('Client ছাড়া Meta খরচ (নিজের ad, adjustment): {amount}', {
                    amount: money(forecast.other, 'usd'),
                })
            }}
        </p>
    </section>
</template>
