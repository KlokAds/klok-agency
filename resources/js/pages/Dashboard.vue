<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MonthlyChart from '@/components/MonthlyChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import CardStrip from '@/components/dashboard/CardStrip.vue';
import ChecksPanel from '@/components/dashboard/ChecksPanel.vue';
import ClientTable from '@/components/dashboard/ClientTable.vue';
import type {
    ClientRow,
    Fx,
    Summary,
    Wallets,
} from '@/components/dashboard/types';
import KAmount from '@/components/k/KAmount.vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{
    summary: Summary;
    wallets: Wallets;
    fx: Fx;
    clients: ClientRow[];
    month_net: number;
    monthly: {
        month: string;
        label: string;
        received: number;
        cost: number;
        expense: number;
        result: number;
        usd_spent: number;
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

const page = usePage();
const user = page.props.auth.user;
const can = computed(() => page.props.auth.can);
const s = computed(() => props.summary);

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
                'Card-এ কত আছে, Meta আর কত কাটবে, কোন client-এর কত বাকি — এক নজরে। সব সংখ্যা খাতা থেকে নিজে হিসাব হয়।',
            )
        "
    >
        <KButton
            v-if="can['clients.view']"
            href="/clients"
            icon="people"
            class="max-sm:hidden"
            >Clients</KButton
        >
        <KButton
            v-if="can['reports.view']"
            href="/reports"
            icon="chart"
            class="max-sm:hidden"
            >P&amp;L</KButton
        >
    </PageHeader>

    <!-- Data that still needs a human look -->
    <div
        v-if="s.review_count || s.undated_count"
        class="mb-7 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-xl border border-wait/25 bg-wait-soft px-4 py-3 text-[13px] text-wait"
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
            class="ml-auto font-medium underline-offset-2 hover:underline"
            >{{ t('খাতায় দেখুন →') }}</Link
        >
    </div>

    <!-- USD: the card and the bought dollars -->
    <h2 class="dash-head">
        <span>{{ t('Dollar — card আর কেনা dollar') }}</span>
    </h2>
    <CardStrip :summary="summary" :fx="fx" />

    <div class="mt-5">
        <ClientTable :clients="clients" :platform-due="s.platform_due" />
    </div>

    <!-- BDT -->
    <h2 class="dash-head mt-9">
        <span>{{ t('টাকা — কোথায় কত আছে') }}</span>
    </h2>
    <section class="panel dash-tiles">
        <div class="p-5 sm:p-6">
            <p class="dash-label">{{ t('BDT কোথায়') }}</p>
            <p class="dash-fig">
                {{ money(wallets.total, 'bdt') }}
            </p>
            <ul class="mt-4 space-y-1.5 text-[12.5px]">
                <li
                    v-for="p in wallets.places"
                    :key="p.key"
                    class="flex justify-between gap-3 text-ink-3"
                >
                    <span>{{ p.label }}</span>
                    <span class="num font-medium text-ink-2">{{
                        money(p.balance, 'bdt')
                    }}</span>
                </li>
                <li
                    v-if="wallets.untagged_count"
                    class="flex justify-between gap-3 text-ink-3"
                >
                    <span>{{
                        t('জায়গা লেখা নেই ({n} row)', {
                            n: wallets.untagged_count,
                        })
                    }}</span>
                    <span class="num font-medium text-ink-2">{{
                        money(wallets.untagged, 'bdt')
                    }}</span>
                </li>
            </ul>
            <Link
                href="/wallets"
                class="no-print mt-3 inline-block text-[13px] font-medium text-brand-ink hover:underline"
                >{{ t('বিস্তারিত') }} →</Link
            >
        </div>
        <div class="p-5 sm:p-6">
            <p class="dash-label">{{ t('Client-দের আগাম টাকা') }}</p>
            <p class="dash-fig">
                {{ money(s.unearned, 'bdt') }}
            </p>
            <p class="mt-4 text-[12.5px] text-ink-3">
                {{
                    t(
                        'Client আগাম দিয়েছে কিন্তু campaign-এ এখনো খরচ হয়নি। এটা client-দের টাকা — লাভ নয়।',
                    )
                }}
            </p>
        </div>
        <div class="p-5 sm:p-6">
            <template v-if="s.owner_due > 0">
                <p class="dash-label">{{ t('Owner-এর কাছে পাওনা') }}</p>
                <p class="dash-fig">
                    {{ money(s.owner_due, 'bdt') }}
                </p>
                <p class="mt-4 text-[12.5px] text-ink-3">
                    {{
                        t(
                            'Owner কিছুদিনের জন্য নিয়েছেন, ফেরত দেবেন — এটা ব্যবসার টাকা।',
                        )
                    }}
                </p>
            </template>
            <template v-else>
                <p class="dash-label">{{ t('Client দেবে') }}</p>
                <p
                    class="dash-fig"
                    :class="s.receivable_cost > 0 && 'text-wait'"
                >
                    {{ money(s.receivable_cost, 'bdt') }}
                </p>
                <p class="mt-4 text-[12.5px] text-ink-3">
                    {{
                        t(
                            '{amount} খরচ হয়েছে, client এখনো টাকা দেয়নি (কেনা দামে)।',
                            {
                                amount: money(s.receivable_usd, 'usd'),
                            },
                        )
                    }}
                </p>
            </template>
        </div>
        <div class="p-5 sm:p-6">
            <p class="dash-label">{{ t('এই মাসের লাভ') }}</p>
            <p class="dash-fig">
                <KAmount :value="month_net" currency="bdt" sign />
            </p>
            <p class="mt-4 text-[12.5px] text-ink-3">
                {{ t('শুরু থেকে') }}
                <KAmount
                    :value="s.net"
                    currency="bdt"
                    sign
                    class="font-medium"
                />
                ·
                <Link
                    href="/reports?preset=this_month"
                    class="no-print font-medium text-brand-ink hover:underline"
                    >P&amp;L →</Link
                >
            </p>
        </div>
    </section>

    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[1fr_1fr]">
        <ChecksPanel :summary="summary" />

        <!-- Month by month -->
        <section class="panel p-5 sm:p-6">
            <div class="mb-4 flex items-baseline justify-between gap-4">
                <div>
                    <h2 class="dash-title">
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
                    class="shrink-0 text-[13px] font-medium whitespace-nowrap text-brand-ink hover:underline"
                    >P&L →</Link
                >
            </div>
            <MonthlyChart :rows="monthly" />
        </section>
    </div>

    <!-- Latest entries -->
    <section v-if="recent.length" class="panel mt-5">
        <h2 class="dash-title px-5 pt-5 sm:px-6">
            {{ t('সর্বশেষ entry') }}
        </h2>
        <ul
            class="mt-2 grid grid-cols-1 px-5 pb-2 sm:px-6 lg:grid-cols-2 lg:gap-x-10"
        >
            <li
                v-for="r in recent"
                :key="r.book + r.id"
                class="dash-entry flex items-center gap-3 border-b border-line py-3 text-[13.5px]"
            >
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-full border border-line bg-surface-2 text-[12px] font-semibold text-ink-2"
                    >{{ r.book === 'usd' ? '$' : '\u09F3' }}</span
                >
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{
                        r.description
                    }}</span>
                    <span class="block truncate text-[12px] text-ink-3"
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
