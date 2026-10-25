<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionTabs from '@/components/SectionTabs.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KPager from '@/components/k/KPager.vue';
import KTag from '@/components/k/KTag.vue';
import { ago, money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    status: string;
    charged_at: string | null;
    amount: number | null;
    currency: string | null;
    transaction_id: string | null;
    account: string | null;
    card: string | null;
    campaigns: { name: string; cents: number; campaign_id: number | null }[];
    problem: string | null;
    subject: string | null;
    entry_id: number | null;
};

// Meta's receipts as they came in, and what each became in the books.
defineProps<{
    receipts: Paginated<Row>;
    statuses: Record<string, string>;
    configured: boolean;
    checked_at: string | null;
    error: string | null;
}>();

const can = computed(() => usePage().props.auth.can);
const errors = computed(() => usePage().props.errors as Record<string, string>);
const checking = ref(false);

function check(): void {
    router.post(
        '/dollars/receipts/check',
        {},
        {
            preserveScroll: true,
            onStart: () => (checking.value = true),
            onFinish: () => (checking.value = false),
        },
    );
}

const TONE: Record<string, 'in' | 'neutral' | 'out' | 'wait'> = {
    booked: 'in',
    duplicate: 'neutral',
    unread: 'out',
    other_account: 'wait',
};
</script>

<template>
    <SectionTabs section="dollars" />
    <PageHeader
        :eyebrow="t('টাকা')"
        title="Meta receipt"
        :lead="
            t(
                'Meta-র payment receipt email থেকে card charge নিজে খাতায় ওঠে — campaign-এর ভাগসহ, একই transaction দুবার নয়।',
            )
        "
    >
        <KButton
            v-if="configured && can['usd.create']"
            icon="undo"
            :loading="checking"
            @click="check"
            >{{ t('এখনই দেখুন') }}</KButton
        >
    </PageHeader>

    <p v-if="!configured" class="panel mb-4 px-5 py-4 text-[14px] text-ink-2">
        {{
            t(
                'Receipt-এর mailbox এখনো দেওয়া হয়নি — Settings → Email-এ mailbox আর password দিন।',
            )
        }}
    </p>
    <p
        v-if="error || errors.mailbox"
        class="panel mb-4 border-out/30 bg-out-soft px-5 py-3 text-[14px] text-out"
    >
        {{ errors.mailbox ?? error }}
    </p>
    <p v-if="configured && checked_at" class="mb-3 text-[13.5px] text-ink-3">
        {{ t('শেষ দেখা') }} {{ ago(checked_at) }}
    </p>

    <section class="panel overflow-hidden">
        <KEmpty
            v-if="!receipts.data.length"
            :title="t('এখনো কোনো receipt আসেনি')"
            :body="
                t(
                    'Gmail থেকে Meta-র receipt forward হয়ে এলেই এখানে দেখা যাবে।',
                )
            "
        />
        <div v-else class="overflow-x-auto">
            <table
                v-stack
                class="stack ledger w-full min-w-[760px] text-[14px]"
            >
                <thead>
                    <tr>
                        <th class="px-5 py-2.5 text-left">{{ t('তারিখ') }}</th>
                        <th class="px-3 py-2.5 text-left">Receipt</th>
                        <th class="px-3 py-2.5 text-left">Campaign</th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('পরিমাণ') }}
                        </th>
                        <th class="px-5 py-2.5 text-left">{{ t('অবস্থা') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in receipts.data"
                        :key="r.id"
                        class="align-top"
                    >
                        <td class="num px-5 py-3 whitespace-nowrap">
                            {{
                                r.charged_at
                                    ? niceDate(r.charged_at)
                                    : t('তারিখ নেই')
                            }}
                        </td>
                        <td class="max-w-[240px] px-3 py-3">
                            <Link
                                :href="`/dollars/receipts/${r.id}`"
                                class="block truncate font-medium hover:text-brand"
                                >{{ r.account ?? r.subject ?? 'Meta' }}</Link
                            >
                            <span
                                v-if="r.transaction_id || r.card"
                                class="num text-[13px] text-ink-3"
                                >{{ r.transaction_id
                                }}<template v-if="r.card">
                                    <template v-if="r.transaction_id"
                                        >·</template
                                    >
                                    card {{ r.card }}</template
                                ></span
                            >
                        </td>
                        <td class="px-3 py-3">
                            <p
                                v-for="c in r.campaigns"
                                :key="c.name"
                                class="flex justify-between gap-3 text-[13.5px]"
                            >
                                <span
                                    class="truncate"
                                    :class="c.campaign_id ? '' : 'text-wait'"
                                    :title="c.name"
                                    >{{ c.name }}</span
                                >
                                <span class="num shrink-0">{{
                                    money(c.cents, 'usd')
                                }}</span>
                            </p>
                            <span
                                v-if="!r.campaigns.length"
                                class="text-[13px] text-ink-3"
                                >{{ t('ভাগ নেই — পুরনো খরচে যাবে') }}</span
                            >
                        </td>
                        <td class="num px-3 py-3 text-right font-semibold">
                            {{
                                r.amount !== null ? money(r.amount, 'usd') : ''
                            }}
                        </td>
                        <td class="px-5 py-3">
                            <KTag :tone="TONE[r.status] ?? 'neutral'">{{
                                t(statuses[r.status] ?? r.status)
                            }}</KTag>
                            <p
                                v-if="r.problem"
                                class="mt-1 max-w-[260px] text-[13px] text-ink-3"
                            >
                                {{ r.problem }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <KPager :page="receipts" :sizes="[10, 25, 50, 100]" />
    </section>
</template>
