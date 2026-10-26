<script setup lang="ts">
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import type { Summary } from '@/components/dashboard/types';
import type { Currency } from '@/lib/format';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The two ways the books are made to agree, each a card: how the figure should come out on the
 * left, what is actually there on the right, an "=" between them, and one verdict chip. Only a
 * mismatch gets a sentence; a book that balances needs none.
 */
const props = defineProps<{ summary: Summary }>();
const s = computed(() => props.summary);

type Line = { label: string; value: number; total?: boolean };
type Check = {
    key: string;
    title: string;
    currency: Currency;
    ok: boolean;
    gap: number;
    left: { caption: string; lines: Line[] };
    right: { caption: string; lines: Line[] };
    why: string;
};

const checks = computed<Check[]>(() => [
    {
        key: 'usd',
        title: t('Dollar'),
        currency: 'usd',
        ok: s.value.usd_check.ok,
        gap: s.value.usd_check.remaining - s.value.business_usd,
        left: {
            caption: t('কেনা − খরচ'),
            lines: [
                { label: t('মোট কেনা'), value: s.value.usd_check.bought },
                {
                    label: t('বাদ: মোট খরচ'),
                    value: -s.value.usd_check.consumed,
                },
                {
                    label: t('বাকি থাকার কথা'),
                    value: s.value.usd_check.remaining,
                    total: true,
                },
            ],
        },
        right: {
            caption: t('হাতে যা আছে'),
            lines: [
                { label: t('Card-এ এখন'), value: s.value.card },
                ...(s.value.holding
                    ? [{ label: t('অন্য account-এ'), value: s.value.holding }]
                    : []),
                {
                    label: t('বাদ: Platform এখনো কাটবে'),
                    value: -s.value.platform_due_book,
                },
                {
                    label: t('আসলে আছে'),
                    value: s.value.business_usd,
                    total: true,
                },
            ],
        },
        why: t('কোনো USD entry বাদ পড়েছে বা ভুল লেখা।'),
    },
    {
        key: 'bdt',
        title: t('ব্যবসার সম্পদ'),
        currency: 'bdt',
        ok: s.value.equity_ok,
        gap: s.value.assets - s.value.equity,
        left: {
            caption: t('যা আছে'),
            lines: [
                { label: t('BDT হাতে'), value: s.value.bdt },
                { label: t('USD (কেনা দামে)'), value: s.value.usd_at_cost },
                {
                    label: t('Client-দের কাছে পাওনা'),
                    value: s.value.receivable_cost,
                },
                ...(s.value.owner_due
                    ? [
                          {
                              label: t('Owner-এর কাছে পাওনা'),
                              value: s.value.owner_due,
                          },
                      ]
                    : []),
                { label: t('মোট সম্পদ'), value: s.value.assets, total: true },
            ],
        },
        right: {
            caption: t('যেখান থেকে এসেছে'),
            lines: [
                { label: t('Capital দিয়েছেন'), value: s.value.capital },
                { label: t('Owner তুলেছেন'), value: -s.value.drawings },
                { label: t('লাভ / (লস)'), value: s.value.net },
                { label: t('Client-দের আগাম টাকা'), value: s.value.unearned },
                { label: t('মোট'), value: s.value.equity, total: true },
            ],
        },
        why: t('কোনো BDT entry বা FX batch ভুল আছে।'),
    },
]);
const allOk = computed(() => checks.value.every((c) => c.ok));
</script>

<template>
    <section class="panel overflow-hidden">
        <div
            class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6"
        >
            <div>
                <h2 class="text-[16px] font-bold">
                    {{ t('হিসাব মিলছে কিনা') }}
                </h2>
                <p class="text-[13.5px] text-ink-3">
                    {{
                        t('দুইভাবে হিসাব করে দেখা — দুটো এক হলে খাতা ঠিক আছে।')
                    }}
                </p>
            </div>
            <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[13px] font-semibold"
                :class="allOk ? 'bg-in-soft text-in' : 'bg-out-soft text-out'"
            >
                <KIcon :name="allOk ? 'check' : 'warn'" :size="14" />
                {{ allOk ? t('দুটো হিসাবই মিলেছে') : t('একটা মেলেনি') }}
            </span>
        </div>

        <div
            class="mt-4 grid grid-cols-1 gap-4 px-5 pb-5 sm:px-6 sm:pb-6 xl:grid-cols-2"
        >
            <article
                v-for="c in checks"
                :key="c.key"
                class="overflow-hidden rounded-2xl border"
                :class="c.ok ? 'border-line' : 'border-out/40'"
            >
                <!-- Head: what is being checked, and the verdict -->
                <div
                    class="flex items-center justify-between gap-3 px-4 py-3"
                    :class="c.ok ? 'bg-sunken' : 'bg-out-soft'"
                >
                    <p class="flex items-center gap-2.5 text-[15px] font-bold">
                        <span
                            class="grid size-8 place-items-center rounded-full"
                            :class="
                                c.ok
                                    ? 'bg-in-soft text-in'
                                    : 'bg-out text-white'
                            "
                            ><KIcon :name="c.ok ? 'check' : 'warn'" :size="16"
                        /></span>
                        {{ c.title }}
                    </p>
                    <span
                        class="num rounded-lg px-2.5 py-1 text-[13px] font-semibold"
                        :class="
                            c.ok ? 'bg-surface text-in' : 'bg-out text-white'
                        "
                        >{{
                            c.ok
                                ? t('মিলেছে')
                                : t('পার্থক্য {amount}', {
                                      amount: money(c.gap, c.currency),
                                  })
                        }}</span
                    >
                </div>

                <!-- Body: two columns with an = between -->
                <div
                    class="relative grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:gap-8"
                >
                    <span
                        class="num absolute top-1/2 left-1/2 z-10 hidden size-8 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full border border-line bg-surface text-[15px] font-bold text-ink-3 sm:grid"
                        >=</span
                    >
                    <dl
                        v-for="side in [c.left, c.right]"
                        :key="side.caption"
                        class="space-y-1.5 text-[14px]"
                    >
                        <p
                            class="text-[12px] font-semibold tracking-[0.06em] text-ink-3 uppercase"
                        >
                            {{ side.caption }}
                        </p>
                        <div
                            v-for="l in side.lines"
                            :key="l.label"
                            class="flex items-baseline justify-between gap-3"
                            :class="
                                l.total
                                    ? 'mt-1 rounded-lg bg-sunken px-2.5 py-1.5 font-bold'
                                    : 'px-2.5'
                            "
                        >
                            <dt :class="l.total ? 'text-ink' : 'text-ink-2'">
                                {{ l.label }}
                            </dt>
                            <dd
                                class="num"
                                :class="
                                    l.total
                                        ? c.ok
                                            ? 'text-in'
                                            : 'text-out'
                                        : ''
                                "
                            >
                                {{
                                    money(l.value, c.currency, {
                                        sign: l.label === t('লাভ / (লস)'),
                                    })
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>
                <p
                    v-if="!c.ok"
                    class="border-t border-out/30 bg-out-soft px-4 py-2 text-[13.5px] font-medium text-out"
                >
                    {{ c.why }}
                </p>
            </article>
        </div>
    </section>
</template>
