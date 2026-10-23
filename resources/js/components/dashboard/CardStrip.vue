<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import type { Fx, Summary } from '@/components/dashboard/types';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ summary: Summary; fx: Fx }>();

// Keep a little on the card so a charge never bounces.
const BUFFER = 1000;

const s = computed(() => props.summary);
const dueRows = computed(() => s.value.platforms.filter((p) => p.due !== 0));
const afterTone = computed(() =>
    s.value.card_after < 0
        ? 'out'
        : s.value.card_after < BUFFER
          ? 'wait'
          : 'ok',
);
</script>

<template>
    <section
        class="grid gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-2 xl:grid-cols-4"
    >
        <!-- Card now -->
        <div class="bg-rail p-5 text-rail-ink sm:p-6">
            <p
                class="flex items-center gap-1.5 text-[12.5px] font-medium text-rail-dim"
            >
                <KIcon name="wallet" :size="14" />
                {{ t('Card-এ এখন') }}
            </p>
            <p class="num mt-3 text-[34px] leading-none font-medium text-white">
                {{ money(s.card, 'usd') }}
            </p>
            <p class="mt-3 text-[12.5px] text-rail-dim">
                {{ t('Agency-র USD card-এ আজকের balance') }}
                <template v-if="s.holding">
                    · {{ t('অন্য account-এ') }}
                    <b class="num font-semibold text-rail-ink">{{
                        money(s.holding, 'usd')
                    }}</b>
                </template>
            </p>
            <Link
                href="/reconcile"
                class="no-print mt-2 inline-block text-[12.5px] font-semibold text-[#2dd4bf] hover:underline"
                >{{ t('Card মেলান') }} →</Link
            >
        </div>

        <!-- Still to be charged -->
        <div class="bg-surface p-5 sm:p-6">
            <p class="eyebrow">{{ t('Meta এখনো কাটবে') }}</p>
            <p
                class="num mt-3 text-[30px] leading-none font-medium"
                :class="s.platform_due > 0 && 'text-wait'"
            >
                {{ money(s.platform_due, 'usd') }}
            </p>
            <ul v-if="dueRows.length" class="mt-3 space-y-1 text-[12.5px]">
                <li
                    v-for="p in dueRows"
                    :key="p.id"
                    class="flex justify-between gap-3 text-ink-3"
                >
                    <span class="truncate">{{ p.name }}</span>
                    <span class="num font-semibold text-ink-2">{{
                        money(p.due, 'usd')
                    }}</span>
                </li>
            </ul>
            <p v-else class="mt-3 text-[12.5px] text-ink-3">
                {{ t('সব খরচ card থেকে কাটা হয়ে গেছে।') }}
            </p>
        </div>

        <!-- After the charge -->
        <div class="bg-surface p-5 sm:p-6">
            <p class="eyebrow">{{ t('কাটার পরে থাকবে') }}</p>
            <p
                class="num mt-3 text-[30px] leading-none font-medium"
                :class="{
                    'text-out': afterTone === 'out',
                    'text-wait': afterTone === 'wait',
                }"
            >
                {{ money(s.card_after, 'usd') }}
            </p>
            <p class="num mt-3 text-[12.5px] text-ink-3">
                {{ money(s.card, 'usd') }} − {{ money(s.platform_due, 'usd') }}
            </p>
            <p
                v-if="afterTone !== 'ok'"
                class="mt-2 flex items-start gap-1.5 text-[12.5px] font-semibold"
                :class="afterTone === 'out' ? 'text-out' : 'text-wait'"
            >
                <KIcon name="warn" :size="14" class="mt-0.5 shrink-0" />
                {{
                    afterTone === 'out'
                        ? t(
                              'Card-এ টাকা কম — Meta কাটলে বাকি পড়বে। Card-এ dollar দিন।',
                          )
                        : t('Card প্রায় খালি হয়ে যাবে — আগেই dollar দিন।')
                }}
            </p>
        </div>

        <!-- Bought dollars left -->
        <div class="bg-surface p-5 sm:p-6" :class="fx.low && 'bg-wait-soft'">
            <p class="eyebrow">{{ t('কেনা dollar বাকি') }}</p>
            <p
                class="num mt-3 text-[30px] leading-none font-medium"
                :class="
                    fx.remaining < 0 ? 'text-out' : fx.low ? 'text-wait' : ''
                "
            >
                {{ money(fx.remaining, 'usd') }}
            </p>
            <p class="mt-3 text-[12.5px] text-ink-3">
                <template v-if="fx.current">
                    {{
                        t('Batch {n} চলছে · ৳{rate}/$', {
                            n: fx.current.number,
                            rate: fx.current.rate.toFixed(2),
                        })
                    }}
                    <template v-if="fx.queued">
                        ·
                        {{
                            t('পরে আরো {n}টা batch ({amount})', {
                                n: fx.queued,
                                amount: money(fx.queued_usd, 'usd'),
                            })
                        }}
                    </template>
                </template>
                <template v-else>{{
                    t('কোনো batch-এ dollar বাকি নেই')
                }}</template>
            </p>
            <p v-if="fx.days !== null" class="mt-1 text-[12.5px] text-ink-3">
                {{
                    t('রোজ গড়ে {amount} খরচ — প্রায় {n} দিন চলবে', {
                        amount: money(fx.daily_usd, 'usd'),
                        n: fx.days,
                    })
                }}
            </p>
            <p
                v-if="fx.low"
                class="mt-2 flex items-start gap-1.5 text-[12.5px] font-semibold"
                :class="fx.remaining < 0 ? 'text-out' : 'text-wait'"
            >
                <KIcon name="warn" :size="14" class="mt-0.5 shrink-0" />
                {{ t('নতুন dollar কিনতে হবে') }}
            </p>
            <Link
                href="/fx"
                class="no-print mt-2 inline-block text-[12.5px] font-semibold text-brand-ink hover:underline"
                >{{ t('FX Batch') }} →</Link
            >
        </div>
    </section>
</template>
