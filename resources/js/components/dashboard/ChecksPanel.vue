<script setup lang="ts">
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import type { Summary } from '@/components/dashboard/types';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ summary: Summary }>();
const s = computed(() => props.summary);
const usdGap = computed(
    () => s.value.usd_check.remaining - s.value.business_usd,
);
const bdtGap = computed(() => s.value.assets - s.value.equity);
</script>

<template>
    <section class="panel p-5 sm:p-6">
        <h2 class="text-[16px] font-bold">{{ t('হিসাব মিলছে কিনা') }}</h2>
        <p class="text-[13.5px] text-ink-3">
            {{ t('দুইভাবে হিসাব করে দেখা — দুটো এক হলে খাতা ঠিক আছে।') }}
        </p>

        <!-- USD -->
        <div
            class="mt-4 rounded-xl border px-4 py-3"
            :class="
                s.usd_check.ok
                    ? 'border-line bg-surface-2'
                    : 'border-out/40 bg-out-soft'
            "
        >
            <p class="flex items-center gap-2 text-[15px] font-semibold">
                <KIcon
                    :name="s.usd_check.ok ? 'check' : 'warn'"
                    :size="16"
                    :class="s.usd_check.ok ? 'text-in' : 'text-out'"
                />
                {{ t('USD: কেনা − খরচ = হাতে থাকা dollar') }}
            </p>
            <div
                class="mt-2 grid grid-cols-1 gap-x-6 gap-y-3 text-[14px] sm:grid-cols-2"
            >
                <dl class="space-y-1">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('মোট কেনা') }}</dt>
                        <dd class="num">
                            {{ money(s.usd_check.bought, 'usd') }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('বাদ: মোট খরচ') }}</dt>
                        <dd class="num">
                            {{ money(-s.usd_check.consumed, 'usd') }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between gap-3 border-t border-line pt-1 font-semibold"
                    >
                        <dt>{{ t('বাকি থাকার কথা') }}</dt>
                        <dd class="num">
                            {{ money(s.usd_check.remaining, 'usd') }}
                        </dd>
                    </div>
                </dl>
                <dl class="space-y-1">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('Card-এ এখন') }}</dt>
                        <dd class="num">{{ money(s.card, 'usd') }}</dd>
                    </div>
                    <div v-if="s.holding" class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('অন্য account-এ') }}</dt>
                        <dd class="num">{{ money(s.holding, 'usd') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">
                            {{ t('বাদ: Platform এখনো কাটবে') }}
                        </dt>
                        <dd class="num">{{ money(-s.platform_due, 'usd') }}</dd>
                    </div>
                    <div
                        class="flex justify-between gap-3 border-t border-line pt-1 font-semibold"
                    >
                        <dt>{{ t('আসলে আছে') }}</dt>
                        <dd class="num">{{ money(s.business_usd, 'usd') }}</dd>
                    </div>
                </dl>
            </div>
            <p
                class="mt-2 text-[13.5px]"
                :class="
                    s.usd_check.ok ? 'text-ink-3' : 'font-semibold text-out'
                "
            >
                {{
                    s.usd_check.ok
                        ? t(
                              'মিলেছে — যত dollar কেনা হয়েছে আর খরচ হয়েছে, তার বাকিটা ঠিক card-এ আছে।',
                          )
                        : t(
                              'মেলেনি — {amount} পার্থক্য। কোনো USD entry বাদ পড়েছে বা ভুল লেখা।',
                              {
                                  amount: money(usdGap, 'usd'),
                              },
                          )
                }}
            </p>
        </div>

        <!-- BDT / equity -->
        <div
            class="mt-3 rounded-xl border px-4 py-3"
            :class="
                s.equity_ok
                    ? 'border-line bg-surface-2'
                    : 'border-out/40 bg-out-soft'
            "
        >
            <p class="flex items-center gap-2 text-[15px] font-semibold">
                <KIcon
                    :name="s.equity_ok ? 'check' : 'warn'"
                    :size="16"
                    :class="s.equity_ok ? 'text-in' : 'text-out'"
                />
                {{
                    t(
                        'ব্যবসার মোট সম্পদ = মালিকের টাকা + লাভ + client-দের আগাম',
                    )
                }}
            </p>
            <div
                class="mt-2 grid grid-cols-1 gap-x-6 gap-y-3 text-[14px] sm:grid-cols-2"
            >
                <dl class="space-y-1">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('BDT হাতে') }}</dt>
                        <dd class="num">{{ money(s.bdt, 'bdt') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('USD (কেনা দামে)') }}</dt>
                        <dd class="num">{{ money(s.usd_at_cost, 'bdt') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">
                            {{ t('Client-দের কাছে পাওনা') }}
                        </dt>
                        <dd class="num">
                            {{ money(s.receivable_cost, 'bdt') }}
                        </dd>
                    </div>
                    <div v-if="s.owner_due" class="flex justify-between gap-3">
                        <dt class="text-ink-2">
                            {{ t('Owner-এর কাছে পাওনা') }}
                        </dt>
                        <dd class="num">{{ money(s.owner_due, 'bdt') }}</dd>
                    </div>
                    <div
                        class="flex justify-between gap-3 border-t border-line pt-1 font-semibold"
                    >
                        <dt>{{ t('মোট সম্পদ') }}</dt>
                        <dd class="num">{{ money(s.assets, 'bdt') }}</dd>
                    </div>
                </dl>
                <dl class="space-y-1">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('Capital দিয়েছেন') }}</dt>
                        <dd class="num">{{ money(s.capital, 'bdt') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('Owner তুলেছেন') }}</dt>
                        <dd class="num">{{ money(-s.drawings, 'bdt') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">{{ t('লাভ / (লস)') }}</dt>
                        <dd class="num">
                            {{ money(s.net, 'bdt', { sign: true }) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-2">
                            {{ t('Client-দের আগাম টাকা') }}
                        </dt>
                        <dd class="num">{{ money(s.unearned, 'bdt') }}</dd>
                    </div>
                    <div
                        class="flex justify-between gap-3 border-t border-line pt-1 font-semibold"
                    >
                        <dt>{{ t('মোট') }}</dt>
                        <dd class="num">{{ money(s.equity, 'bdt') }}</dd>
                    </div>
                </dl>
            </div>
            <p
                class="mt-2 text-[13.5px]"
                :class="s.equity_ok ? 'text-ink-3' : 'font-semibold text-out'"
            >
                {{
                    s.equity_ok
                        ? t('মিলেছে — যা আছে আর যেখান থেকে এসেছে, দুটো এক।')
                        : t(
                              'মেলেনি — {amount} পার্থক্য। কোনো BDT entry বা FX batch ভুল আছে।',
                              {
                                  amount: money(bdtGap, 'bdt'),
                              },
                          )
                }}
            </p>
        </div>
    </section>
</template>
