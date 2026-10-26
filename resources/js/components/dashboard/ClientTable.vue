<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KTag from '@/components/k/KTag.vue';
import type { ClientRow } from '@/components/dashboard/types';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{
    clients: ClientRow[];
    platformDue: number;
    metaLive?: boolean;
}>();

type Tone = 'neutral' | 'in' | 'out' | 'wait';

function usd(value: number): string {
    return value ? money(value, 'usd') : '–';
}

function status(c: ClientRow): { tone: Tone; label: string } {
    if (c.balance_usd < 0) return { tone: 'out', label: t('টাকা নিতে হবে') };
    if (c.days_left !== null && c.days_left <= 2)
        return { tone: 'out', label: t('Credit শেষ হচ্ছে') };
    if (c.days_left !== null && c.days_left <= 5)
        return { tone: 'wait', label: t('শীঘ্রই recharge') };
    if (!c.active) return { tone: 'neutral', label: 'Inactive' };
    if (c.balance_usd > 0) return { tone: 'in', label: t('Credit আছে') };

    return { tone: 'neutral', label: t('মিটে গেছে') };
}

function daysTone(days: number | null): string {
    if (days === null) return 'text-ink-3';
    if (days <= 2) return 'text-out font-semibold';
    if (days <= 5) return 'text-wait font-semibold';

    return '';
}

const rows = computed(() =>
    props.clients.map((c) => ({ ...c, tag: status(c) })),
);

const total = computed(() => {
    const sum = (key: keyof ClientRow): number =>
        props.clients.reduce((n, c) => n + Number(c[key] ?? 0), 0);

    return {
        credit: sum('credit_usd'),
        creditBdt: sum('credit_bdt'),
        spent: sum('spent_usd'),
        spentBdt: sum('spent_bdt'),
        held: props.clients.reduce((n, c) => n + Math.max(0, c.balance_usd), 0),
        owed: props.clients.reduce((n, c) => n + Math.min(0, c.balance_usd), 0),
        unbilled: sum('unbilled_usd'),
    };
});

// Credit taken from clients day by day that Meta has not charged yet, against what Meta
// itself says it will charge: the two should agree; a gap is spend outside any client's
// campaign or Meta's own timing, never a separate "Klok Ads" due.
const gap = computed(() => props.platformDue - total.value.unbilled);
</script>

<template>
    <section class="panel overflow-hidden">
        <div
            class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 pt-5 sm:px-6"
        >
            <div>
                <h2 class="dash-title">
                    {{ t('Client-দের হিসাব') }}
                </h2>
                <p class="mt-0.5 text-[13.5px] text-ink-3">
                    {{
                        t(
                            'আগাম যা দিয়েছে, campaign-এ যা খরচ হয়েছে, কত বাকি — আর Meta এখনো card থেকে কত কাটবে।',
                        )
                    }}
                </p>
            </div>
            <KButton href="/clients" size="sm" class="no-print shrink-0">{{
                t('সব client')
            }}</KButton>
        </div>

        <KEmpty
            v-if="!rows.length"
            class="m-5"
            :title="t('এখন কোনো client-এর টাকা চলছে না')"
        />

        <template v-else>
            <!-- Phone: one card per client -->
            <ul class="mt-3 px-4 pb-3 md:hidden">
                <li
                    v-for="c in rows"
                    :key="c.id"
                    class="border-b border-line py-3 last:border-0"
                >
                    <div class="flex items-center justify-between gap-3">
                        <Link
                            :href="`/clients/${c.id}`"
                            class="truncate font-medium hover:text-brand-ink"
                            >{{ c.name }}</Link
                        >
                        <KTag :tone="c.tag.tone">{{ c.tag.label }}</KTag>
                    </div>
                    <dl
                        class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-[13.5px]"
                    >
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-3">{{ t('আগাম credit') }}</dt>
                            <dd class="num text-right">
                                {{ usd(c.credit_usd)
                                }}<span
                                    v-if="c.credit_bdt"
                                    class="block text-[13px] text-ink-3"
                                    >{{ money(c.credit_bdt, 'bdt') }}</span
                                >
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-3">{{ t('খরচ হয়েছে') }}</dt>
                            <dd class="num text-right">
                                {{ usd(c.spent_usd)
                                }}<span
                                    v-if="c.spent_bdt"
                                    class="block text-[13px] text-ink-3"
                                    >{{ money(c.spent_bdt, 'bdt') }}</span
                                >
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-3">
                                {{
                                    c.balance_usd < 0
                                        ? t('পাওনা')
                                        : t('বাকি credit')
                                }}
                            </dt>
                            <dd
                                class="num font-semibold"
                                :class="c.balance_usd < 0 && 'text-out'"
                            >
                                {{ usd(Math.abs(c.balance_usd)) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-3">{{ t('Card-এ আসেনি') }}</dt>
                            <dd class="num">{{ usd(c.unbilled_usd) }}</dd>
                        </div>
                        <div
                            v-if="c.days_left !== null"
                            class="col-span-2 flex justify-between gap-2"
                        >
                            <dt class="text-ink-3">{{ t('দিন বাকি') }}</dt>
                            <dd class="num" :class="daysTone(c.days_left)">
                                {{
                                    t('{days} · রোজ {amount}', {
                                        days:
                                            c.days_left > 0
                                                ? t('{n} দিন', {
                                                      n: c.days_left,
                                                  })
                                                : t('Credit শেষ'),
                                        amount: money(c.daily_usd, 'usd'),
                                    })
                                }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="c.unearned > 0 || c.pending_cost > 0"
                        class="mt-1.5 text-[13px] text-ink-3"
                    >
                        <template v-if="c.unearned > 0">{{
                            t('আগাম টাকা জমা {amount}', {
                                amount: money(c.unearned, 'bdt'),
                            })
                        }}</template>
                        <template v-if="c.pending_cost > 0">{{
                            t('কেনা দামে {amount}', {
                                amount: money(c.pending_cost, 'bdt'),
                            })
                        }}</template>
                    </p>
                </li>
            </ul>

            <!-- Tablet and up: a ruled table -->
            <div class="mt-4 hidden overflow-x-auto md:block">
                <table class="ledger [&_tfoot_td]:border-b-0">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th class="r">{{ t('আগাম credit') }}</th>
                            <th class="r">{{ t('খরচ হয়েছে') }}</th>
                            <th class="r">{{ t('বাকি credit') }}</th>
                            <th class="r">
                                {{ t('Credit কাটা, card-এ আসেনি') }}
                            </th>
                            <th class="r">{{ t('দিন বাকি') }}</th>
                            <th>{{ t('অবস্থা') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in rows" :key="c.id">
                            <td>
                                <Link
                                    :href="`/clients/${c.id}`"
                                    class="group flex items-center gap-2.5 font-medium"
                                >
                                    <span
                                        class="grid size-7 shrink-0 place-items-center rounded-full border border-line bg-surface-2 text-[13px] font-semibold text-ink-2"
                                        >{{ c.name.charAt(0) }}</span
                                    >
                                    <span
                                        class="group-hover:text-brand-ink group-hover:underline"
                                        >{{ c.name }}</span
                                    >
                                </Link>
                            </td>
                            <td class="r num">
                                {{ usd(c.credit_usd) }}
                                <span
                                    v-if="c.credit_bdt"
                                    class="block text-[13px] text-ink-3"
                                    >{{ money(c.credit_bdt, 'bdt') }}</span
                                >
                            </td>
                            <td class="r num">
                                {{ usd(c.spent_usd) }}
                                <span
                                    v-if="c.spent_bdt"
                                    class="block text-[13px] text-ink-3"
                                    >{{ money(c.spent_bdt, 'bdt') }}</span
                                >
                            </td>
                            <td class="r">
                                <span
                                    v-if="c.balance_usd < 0"
                                    class="num font-semibold text-out"
                                    >{{ t('পাওনা') }}
                                    {{ money(-c.balance_usd, 'usd') }}</span
                                >
                                <span v-else class="num font-semibold">{{
                                    usd(c.balance_usd)
                                }}</span>
                                <span
                                    v-if="c.unearned > 0"
                                    class="num block text-[13px] text-ink-3"
                                    >{{ money(c.unearned, 'bdt') }}
                                    {{ t('জমা') }}</span
                                >
                                <span
                                    v-else-if="c.pending_cost > 0"
                                    class="num block text-[13px] text-ink-3"
                                    >{{
                                        t('কেনা দামে {amount}', {
                                            amount: money(
                                                c.pending_cost,
                                                'bdt',
                                            ),
                                        })
                                    }}</span
                                >
                            </td>
                            <td
                                class="r num"
                                :class="c.unbilled_usd > 0 && 'text-wait'"
                            >
                                {{ usd(c.unbilled_usd) }}
                            </td>
                            <td class="r num" :class="daysTone(c.days_left)">
                                <template v-if="c.days_left !== null">
                                    {{
                                        c.days_left > 0
                                            ? t('{n} দিন', { n: c.days_left })
                                            : t('Credit শেষ')
                                    }}
                                    <span
                                        class="block text-[13px] font-normal text-ink-3"
                                        >{{
                                            t('রোজ {amount}', {
                                                amount: money(
                                                    c.daily_usd,
                                                    'usd',
                                                ),
                                            })
                                        }}</span
                                    >
                                </template>
                                <template v-else>–</template>
                            </td>
                            <td>
                                <KTag :tone="c.tag.tone">{{
                                    c.tag.label
                                }}</KTag>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold [&_td:first-child]:pl-[52px]">
                            <td
                                class="border-t border-line-2 bg-surface-2 px-3.5 py-3"
                            >
                                {{ t('মোট') }}
                            </td>
                            <td
                                class="r num border-t border-line-2 bg-surface-2 px-3.5 py-3"
                            >
                                {{ usd(total.credit) }}
                                <span
                                    v-if="total.creditBdt"
                                    class="block text-[13px] text-ink-3"
                                    >{{ money(total.creditBdt, 'bdt') }}</span
                                >
                            </td>
                            <td
                                class="r num border-t border-line-2 bg-surface-2 px-3.5 py-3"
                            >
                                {{ usd(total.spent) }}
                                <span
                                    v-if="total.spentBdt"
                                    class="block text-[13px] text-ink-3"
                                    >{{ money(total.spentBdt, 'bdt') }}</span
                                >
                            </td>
                            <td
                                class="r num border-t border-line-2 bg-surface-2 px-3.5 py-3"
                            >
                                {{ usd(total.held) }}
                                <span
                                    v-if="total.owed"
                                    class="block text-[13px] text-out"
                                    >{{ t('পাওনা') }}
                                    {{ money(-total.owed, 'usd') }}</span
                                >
                            </td>
                            <td
                                class="r num border-t border-line-2 bg-surface-2 px-3.5 py-3 text-wait"
                            >
                                {{ usd(total.unbilled) }}
                                <span
                                    class="block text-[13px] font-normal text-ink-3"
                                    >{{
                                        metaLive
                                            ? t('Meta-র হিসাবে')
                                            : t('খাতায়')
                                    }}
                                    {{ usd(platformDue)
                                    }}<template v-if="gap !== 0">
                                        · {{ t('পার্থক্য') }}
                                        {{ usd(gap) }}</template
                                    ></span
                                >
                            </td>
                            <td class="border-t border-line-2 bg-surface-2" />
                            <td class="border-t border-line-2 bg-surface-2" />
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Phone totals -->
            <dl
                class="mx-4 mb-4 rounded-xl bg-surface-2 px-4 py-2 text-[14px] md:hidden"
            >
                <div class="flex justify-between border-b border-line py-1.5">
                    <dt class="text-ink-2">{{ t('মোট বাকি credit') }}</dt>
                    <dd class="num font-semibold">{{ usd(total.held) }}</dd>
                </div>
                <div
                    v-if="total.owed"
                    class="flex justify-between border-b border-line py-1.5"
                >
                    <dt class="text-ink-2">{{ t('মোট পাওনা') }}</dt>
                    <dd class="num font-semibold text-out">
                        {{ money(-total.owed, 'usd') }}
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-ink-2">
                        {{ t('Card-এ আসেনি') }}
                        <span class="block text-[13px] text-ink-3"
                            >{{ metaLive ? t('Meta-র হিসাবে') : t('খাতায়') }}
                            {{ usd(platformDue) }}</span
                        >
                    </dt>
                    <dd class="num font-semibold text-wait">
                        {{ usd(total.unbilled) }}
                    </dd>
                </div>
            </dl>
        </template>
    </section>
</template>
