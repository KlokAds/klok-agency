<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MonthlyChart from '@/components/MonthlyChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import KAmount from '@/components/k/KAmount.vue';
import KIcon from '@/components/k/KIcon.vue';
import KTag from '@/components/k/KTag.vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

type Platform = {
    id: number;
    name: string;
    spent: number;
    charged: number;
    due: number;
};

const props = defineProps<{
    summary: {
        card: number;
        holding: number;
        platforms: Platform[];
        platform_due: number;
        business_usd: number;
        usd_check: {
            bought: number;
            consumed: number;
            remaining: number;
            ok: boolean;
        };
        overdrawn: boolean;
        bdt: number;
        usd_at_cost: number;
        receivable_usd: number;
        receivable_cost: number;
        capital: number;
        drawings: number;
        assets: number;
        equity: number;
        equity_ok: boolean;
        margin: number;
        net: number;
        review_count: number;
        undated_count: number;
    };
    monthly: {
        month: string;
        label: string;
        received: number;
        cost: number;
        expense: number;
        result: number;
        usd_spent: number;
    }[];
    clients: {
        id: number;
        name: string;
        margin: number;
        received: number;
        usd_settled: number;
        usd_pending: number;
        status: string;
    }[];
    pending: {
        id: number;
        date: string | null;
        client: string;
        description: string;
        amount: number;
        cost: number;
    }[];
    recent: {
        id: number;
        book: 'usd' | 'bdt';
        date: string | null;
        description: string;
        category: string;
        client: string | null;
        amount: number;
        status: string | null;
    }[];
}>();

const user = usePage().props.auth.user;
const s = computed(() => props.summary);
const topMargin = computed(() =>
    Math.max(1, ...props.clients.map((c) => Math.abs(c.margin))),
);

const hour = new Date().getHours();
const greeting =
    hour < 12 ? 'শুভ সকাল' : hour < 17 ? 'শুভ দুপুর' : 'শুভ সন্ধ্যা'; // i18n
</script>

<template>
    <PageHeader
        :eyebrow="`${t(greeting)}, ${user.name}`"
        :title="t('আজকের ডেস্ক')"
        :lead="
            t(
                'Card, খাতা আর client — এক নজরে। সব সংখ্যা খাতা থেকে নিজে হিসাব হয়।',
            )
        "
    />

    <!-- Data that still needs a human look -->
    <div
        v-if="s.review_count || s.undated_count"
        class="mb-6 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-xl border border-dashed border-wait/50 bg-wait-soft px-4 py-3 text-[13px] text-wait"
    >
        <KIcon name="warn" :size="16" />
        <span v-if="s.review_count">{{
            t('{n}টা row পুরনো sheet থেকে "মিলিয়ে দেখুন" চিহ্ন নিয়ে এসেছে', {
                n: s.review_count,
            })
        }}</span>
        <span v-if="s.undated_count">{{
            t('{n}টা পুরনো row-এ তারিখ নেই', { n: s.undated_count })
        }}</span>
        <Link
            href="/bdt"
            class="ml-auto font-semibold underline-offset-2 hover:underline"
            >{{ t('খাতায় দেখুন →') }}</Link
        >
    </div>

    <!-- The four numbers that matter -->
    <section
        class="grid gap-px overflow-hidden rounded-[16px] border border-line bg-line sm:grid-cols-2 xl:grid-cols-4"
    >
        <div class="bg-rail p-5 text-rail-ink sm:p-6">
            <p
                class="text-[11px] font-bold tracking-[0.14em] text-rail-dim uppercase"
            >
                {{ t('ব্যবসার আসল USD') }}
            </p>
            <p class="num mt-3 text-[34px] leading-none font-medium text-white">
                {{ money(s.business_usd, 'usd') }}
            </p>
            <p
                class="mt-3 flex items-center gap-1.5 text-[12.5px]"
                :class="s.usd_check.ok ? 'text-[#2dd4bf]' : 'text-[#f0875e]'"
            >
                <KIcon :name="s.usd_check.ok ? 'check' : 'warn'" :size="14" />
                {{
                    s.usd_check.ok
                        ? t('মিলেছে — কেনা − খরচ')
                        : t('মেলেনি — কেনা − খরচ = {amount}', {
                              amount: money(s.usd_check.remaining, 'usd'),
                          })
                }}
            </p>
        </div>
        <div class="bg-surface p-5 sm:p-6">
            <p class="eyebrow">{{ t('Card-এ এখন (app)') }}</p>
            <p class="num mt-3 text-[30px] leading-none font-medium">
                {{ money(s.card, 'usd') }}
            </p>
            <p class="mt-3 text-[12.5px] text-ink-3">
                {{ t('Platform আরো কাটবে') }}
                <span class="num font-semibold text-out">{{
                    money(s.platform_due, 'usd')
                }}</span>
            </p>
        </div>
        <div class="bg-surface p-5 sm:p-6">
            <p class="eyebrow">{{ t('BDT হাতে / Bank') }}</p>
            <p class="num mt-3 text-[30px] leading-none font-medium">
                {{ money(s.bdt, 'bdt') }}
            </p>
            <p class="mt-3 text-[12.5px] text-ink-3">
                {{ t('BDT খাতার balance') }}
            </p>
        </div>
        <div class="bg-surface p-5 sm:p-6">
            <p class="eyebrow">Profit / (Loss)</p>
            <p
                class="num mt-3 text-[30px] leading-none font-medium"
                :class="s.net < -50 ? 'text-out' : s.net > 50 ? 'text-in' : ''"
            >
                {{ money(s.net, 'bdt', { sign: true }) }}
            </p>
            <p class="mt-3 text-[12.5px] text-ink-3">
                Client margin
                <span class="num font-semibold text-ink-2">{{
                    money(s.margin, 'bdt')
                }}</span>
                {{ t('− খরচ') }}
            </p>
        </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1.35fr_1fr]">
        <!-- Month by month -->
        <section class="panel p-5 sm:p-6">
            <div class="mb-4 flex items-baseline justify-between gap-4">
                <div>
                    <h2 class="text-[16px] font-bold">
                        {{ t('মাসে মাসে ফলাফল') }}
                    </h2>
                    <p class="text-[12.5px] text-ink-3">
                        {{
                            t(
                                'Client-এর টাকা − USD-এর FIFO cost − খরচ · শেষ ৬ মাস',
                            )
                        }}
                    </p>
                </div>
                <Link
                    href="/reports"
                    class="shrink-0 text-[13px] font-semibold whitespace-nowrap text-brand-ink hover:underline"
                    >P&L →</Link
                >
            </div>
            <MonthlyChart :rows="monthly" />
        </section>

        <!-- Where the money sits -->
        <section class="panel p-5 sm:p-6">
            <h2 class="text-[16px] font-bold">{{ t('লাভ-লসের হিসাব') }}</h2>
            <p class="text-[12.5px] text-ink-3">
                {{ t('যা আছে = যা দিয়েছেন − যা তুলেছেন + লাভ') }}
            </p>
            <dl class="mt-4 text-[14px]">
                <div
                    class="flex justify-between border-b border-dashed border-line py-2.5"
                >
                    <dt class="text-ink-2">{{ t('BDT হাতে') }}</dt>
                    <dd class="num">{{ money(s.bdt, 'bdt') }}</dd>
                </div>
                <div
                    class="flex justify-between border-b border-dashed border-line py-2.5"
                >
                    <dt class="text-ink-2">{{ t('USD (কেনা দামে)') }}</dt>
                    <dd class="num">{{ money(s.usd_at_cost, 'bdt') }}</dd>
                </div>
                <div
                    class="flex justify-between border-b border-dashed border-line py-2.5"
                >
                    <dt class="text-ink-2">
                        {{ t('Client-দের কাছে পাওনা') }}
                        <span class="text-ink-3"
                            >({{ money(s.receivable_usd, 'usd') }})</span
                        >
                    </dt>
                    <dd class="num">{{ money(s.receivable_cost, 'bdt') }}</dd>
                </div>
                <div class="flex justify-between py-2.5 font-semibold">
                    <dt>{{ t('মোট সম্পদ') }}</dt>
                    <dd class="num">{{ money(s.assets, 'bdt') }}</dd>
                </div>
            </dl>
            <dl class="mt-2 rounded-xl bg-surface-2 px-4 py-1 text-[13.5px]">
                <div
                    class="flex justify-between border-b border-dashed border-line py-2"
                >
                    <dt class="text-ink-2">{{ t('Capital দিয়েছেন') }}</dt>
                    <dd class="num">{{ money(s.capital, 'bdt') }}</dd>
                </div>
                <div
                    class="flex justify-between border-b border-dashed border-line py-2"
                >
                    <dt class="text-ink-2">{{ t('Owner তুলেছেন') }}</dt>
                    <dd class="num">{{ money(-s.drawings, 'bdt') }}</dd>
                </div>
                <div
                    class="flex justify-between border-b border-dashed border-line py-2"
                >
                    <dt class="text-ink-2">{{ t('লাভ / (লস)') }}</dt>
                    <dd class="num">
                        {{ money(s.net, 'bdt', { sign: true }) }}
                    </dd>
                </div>
                <div
                    class="flex items-center justify-between py-2 font-semibold"
                >
                    <dt class="flex items-center gap-1.5">
                        <KIcon
                            :name="s.equity_ok ? 'check' : 'warn'"
                            :size="15"
                            :class="s.equity_ok ? 'text-in' : 'text-out'"
                        />
                        {{ s.equity_ok ? t('মিলেছে') : t('মেলেনি') }}
                    </dt>
                    <dd class="num">{{ money(s.equity, 'bdt') }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <!-- Clients -->
        <section class="panel">
            <div class="flex items-baseline justify-between px-5 pt-5 sm:px-6">
                <h2 class="text-[16px] font-bold">
                    {{ t('Client-ভিত্তিক margin') }}
                </h2>
                <Link
                    href="/clients"
                    class="text-[13px] font-semibold text-brand-ink hover:underline"
                    >{{ t('সব client →') }}</Link
                >
            </div>
            <ul class="mt-3 px-5 pb-4 sm:px-6">
                <li
                    v-for="c in clients"
                    :key="c.id"
                    class="border-b border-dashed border-line py-3 last:border-0"
                >
                    <Link :href="`/clients/${c.id}`" class="group block">
                        <div class="flex items-baseline justify-between gap-3">
                            <span
                                class="font-semibold group-hover:text-brand-ink"
                                >{{ c.name }}</span
                            >
                            <KAmount
                                :value="c.margin"
                                currency="bdt"
                                class="text-[14px] font-medium"
                            />
                        </div>
                        <div class="mt-1.5 flex items-center gap-3">
                            <span
                                class="h-1.5 flex-1 overflow-hidden rounded-full bg-sunken"
                            >
                                <span
                                    class="block h-full rounded-full"
                                    :class="
                                        c.margin >= 0
                                            ? 'bg-[var(--pos)]'
                                            : 'bg-[var(--neg)]'
                                    "
                                    :style="{
                                        width: `${(Math.abs(c.margin) / topMargin) * 100}%`,
                                    }"
                                />
                            </span>
                            <span
                                class="num w-24 text-right text-[11.5px] text-ink-3"
                                >{{
                                    t('{amount} খরচ', {
                                        amount: money(c.usd_settled, 'usd'),
                                    })
                                }}</span
                            >
                        </div>
                    </Link>
                </li>
            </ul>
        </section>

        <div class="flex flex-col gap-6">
            <!-- Platforms -->
            <section class="panel p-5 sm:p-6">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-[16px] font-bold">
                        {{ t('Ad Platform — খরচ বনাম কাটা') }}
                    </h2>
                    <Link
                        href="/platforms"
                        class="text-[13px] font-semibold text-brand-ink hover:underline"
                        >{{ t('বিস্তারিত →') }}</Link
                    >
                </div>
                <table class="mt-3 w-full text-[13.5px]">
                    <thead>
                        <tr
                            class="text-left text-[11px] font-bold tracking-[0.08em] text-ink-3 uppercase"
                        >
                            <th class="pb-2 font-bold">Platform</th>
                            <th class="pb-2 text-right font-bold">
                                {{ t('খরচ') }}
                            </th>
                            <th class="pb-2 text-right font-bold">
                                {{ t('কেটেছে') }}
                            </th>
                            <th class="pb-2 text-right font-bold">
                                {{ t('আরো কাটবে') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in s.platforms"
                            :key="p.id"
                            class="border-t border-dashed border-line"
                        >
                            <td class="py-2.5 font-medium">{{ p.name }}</td>
                            <td class="num py-2.5 text-right">
                                {{ p.spent ? money(p.spent, 'usd') : '–' }}
                            </td>
                            <td class="num py-2.5 text-right">
                                {{ p.charged ? money(p.charged, 'usd') : '–' }}
                            </td>
                            <td
                                class="num py-2.5 text-right font-semibold"
                                :class="p.due > 0 && 'text-out'"
                            >
                                {{ p.due ? money(p.due, 'usd') : '–' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Pending from clients -->
            <section class="panel p-5 sm:p-6">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-[16px] font-bold">
                        {{ t('Client দেবে') }}
                    </h2>
                    <span class="num text-[14px] font-semibold text-wait">{{
                        money(s.receivable_usd, 'usd')
                    }}</span>
                </div>
                <ul v-if="pending.length" class="mt-2">
                    <li
                        v-for="p in pending"
                        :key="p.id"
                        class="flex items-center gap-3 border-b border-dashed border-line py-2.5 text-[13.5px] last:border-0"
                    >
                        <KTag tone="wait">Pending</KTag>
                        <span class="min-w-0 flex-1 truncate"
                            ><b class="font-semibold">{{ p.client }}</b> ·
                            {{ p.description }}</span
                        >
                        <span class="text-[12px] text-ink-3">{{
                            niceDate(p.date, false)
                        }}</span>
                        <span class="num font-medium">{{
                            money(p.amount, 'usd')
                        }}</span>
                    </li>
                </ul>
                <p v-else class="mt-3 text-[13.5px] text-ink-3">
                    {{ t('সব client টাকা দিয়ে দিয়েছে।') }}
                </p>
            </section>
        </div>
    </div>

    <!-- Latest entries -->
    <section class="panel mt-6">
        <h2 class="px-5 pt-5 text-[16px] font-bold sm:px-6">
            {{ t('সর্বশেষ entry') }}
        </h2>
        <ul class="mt-2 px-5 pb-3 sm:px-6">
            <li
                v-for="r in recent"
                :key="r.book + r.id"
                class="flex items-center gap-3 border-b border-dashed border-line py-3 text-[13.5px] last:border-0"
            >
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-lg bg-sunken text-[12px] font-bold text-ink-2"
                    >{{ r.book === 'usd' ? '$' : '\u09F3' }}</span
                >
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{
                        r.description
                    }}</span>
                    <span class="block text-[12px] text-ink-3"
                        >{{ r.category
                        }}<template v-if="r.client"> · {{ r.client }}</template>
                        · {{ niceDate(r.date) }}</span
                    >
                </span>
                <KAmount
                    :value="r.amount"
                    :currency="r.book"
                    sign
                    :round="r.book === 'bdt'"
                    class="font-medium"
                />
            </li>
        </ul>
    </section>
</template>
