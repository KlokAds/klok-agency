<script setup lang="ts">
import { computed } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import type { Fx, Summary } from '@/components/dashboard/types';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ summary: Summary; fx: Fx }>();

// Keep a little on the card so a charge never bounces.
const BUFFER = 1000;
const TAKA = '\u{09F3}';

const s = computed(() => props.summary);
const dueRows = computed(() => s.value.platforms.filter((p) => p.due !== 0));
const afterTone = computed(() =>
    s.value.card_after < 0
        ? 'out'
        : s.value.card_after < BUFFER
          ? 'wait'
          : 'ok',
);

// The hero figure, with the cents set a step quieter.
const hero = computed(() => {
    const text = money(s.value.card, 'usd');
    const dot = text.lastIndexOf('.');

    return dot === -1
        ? { whole: text, cents: '' }
        : { whole: text.slice(0, dot), cents: text.slice(dot) };
});

// Days the bought dollars last, on a 30-day strip.
const runway = computed(() =>
    Math.max(0, Math.min(30, Math.round(props.fx.days ?? 0))),
);

// How the card splits: what Meta will still take, and what stays.
const meter = computed(() => {
    const card = Math.max(0, s.value.card);
    const due = Math.max(0, s.value.platform_due);
    const base = Math.max(card, due, 1);

    return {
        due: Math.min(100, (due / base) * 100),
        left: Math.max(0, ((card - due) / base) * 100),
    };
});
</script>

<template>
    <section class="panel overflow-hidden">
        <div
            class="grid grid-cols-1 lg:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]"
        >
            <!-- The card: one hero figure and how it splits -->
            <div class="p-5 sm:p-7">
                <div class="flex items-center justify-between gap-3">
                    <p
                        class="flex items-center gap-2 text-[13.5px] font-medium text-ink-2"
                    >
                        <KIcon name="wallet" :size="15" class="text-brand" />
                        {{ t('Card-এ এখন') }}
                    </p>
                    <KButton
                        href="/reconcile"
                        size="sm"
                        icon="scale"
                        class="no-print shrink-0"
                        >{{ t('Card মেলান') }}</KButton
                    >
                </div>

                <p
                    class="mt-3 text-[44px] leading-none font-semibold tracking-[-0.035em] sm:text-[56px]"
                >
                    {{ hero.whole
                    }}<span class="text-[0.55em] font-medium text-ink-3">{{
                        hero.cents
                    }}</span>
                </p>
                <p class="mt-2.5 text-[13px] text-ink-3">
                    {{ t('Agency-র USD card-এ আজকের balance') }}
                    <template v-if="s.holding">
                        · {{ t('অন্য account-এ') }}
                        <b class="num font-semibold text-ink-2">{{
                            money(s.holding, 'usd')
                        }}</b>
                    </template>
                </p>

                <!-- Split bar -->
                <div
                    class="mt-6 flex h-2 gap-0.5 overflow-hidden rounded-full bg-sunken"
                    role="presentation"
                >
                    <span
                        v-if="meter.due"
                        class="h-full rounded-full bg-wait"
                        :style="{ width: `${meter.due}%` }"
                    />
                    <span
                        v-if="meter.left"
                        class="h-full rounded-full"
                        :class="afterTone === 'ok' ? 'bg-brand' : 'bg-out'"
                        :style="{ width: `${meter.left}%` }"
                    />
                </div>

                <dl
                    class="mt-4 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2"
                >
                    <div>
                        <dt
                            class="flex items-center gap-2 text-[13px] text-ink-2"
                        >
                            <span class="size-2 rounded-full bg-wait" />
                            {{ t('Meta এখনো কাটবে') }}
                        </dt>
                        <dd
                            class="num mt-1 text-[22px] leading-tight font-semibold"
                            :class="s.platform_due > 0 && 'text-wait'"
                        >
                            {{ money(s.platform_due, 'usd') }}
                        </dd>
                        <ul
                            v-if="dueRows.length"
                            class="mt-1.5 space-y-0.5 text-[12.5px]"
                        >
                            <li
                                v-for="p in dueRows"
                                :key="p.id"
                                class="flex justify-between gap-3 text-ink-3"
                            >
                                <span class="truncate">{{ p.name }}</span>
                                <span class="num text-ink-2">{{
                                    money(p.due, 'usd')
                                }}</span>
                            </li>
                        </ul>
                        <p v-else class="mt-1.5 text-[12.5px] text-ink-3">
                            {{ t('সব খরচ card থেকে কাটা হয়ে গেছে।') }}
                        </p>
                    </div>
                    <div>
                        <dt
                            class="flex items-center gap-2 text-[13px] text-ink-2"
                        >
                            <span
                                class="size-2 rounded-full"
                                :class="
                                    afterTone === 'ok' ? 'bg-brand' : 'bg-out'
                                "
                            />
                            {{ t('কাটার পরে থাকবে') }}
                        </dt>
                        <dd
                            class="num mt-1 text-[22px] leading-tight font-semibold"
                            :class="{
                                'text-out': afterTone === 'out',
                                'text-wait': afterTone === 'wait',
                            }"
                        >
                            {{ money(s.card_after, 'usd') }}
                        </dd>
                        <p class="num mt-1.5 text-[12.5px] text-ink-3">
                            {{ money(s.card, 'usd') }} −
                            {{ money(s.platform_due, 'usd') }}
                        </p>
                    </div>
                </dl>

                <p
                    v-if="afterTone !== 'ok'"
                    class="mt-4 flex items-start gap-2 rounded-lg px-3 py-2 text-[13px] font-medium"
                    :class="
                        afterTone === 'out'
                            ? 'bg-out-soft text-out'
                            : 'bg-wait-soft text-wait'
                    "
                >
                    <KIcon name="warn" :size="15" class="mt-0.5 shrink-0" />
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
            <div
                class="flex flex-col border-t border-line bg-surface-2 p-5 sm:p-7 lg:border-t-0 lg:border-l"
            >
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[13.5px] font-medium text-ink-2">
                        {{ t('কেনা dollar বাকি') }}
                    </p>
                    <KButton href="/fx" size="sm" class="no-print shrink-0">{{
                        t('FX Batch')
                    }}</KButton>
                </div>
                <p
                    class="num mt-3 text-[30px] leading-none font-semibold tracking-[-0.02em]"
                    :class="
                        fx.remaining < 0
                            ? 'text-out'
                            : fx.low
                              ? 'text-wait'
                              : ''
                    "
                >
                    {{ money(fx.remaining, 'usd') }}
                </p>

                <p v-if="!fx.current" class="mt-3 text-[13px] text-ink-3">
                    {{ t('কোনো batch-এ dollar বাকি নেই') }}
                </p>

                <dl class="mt-auto space-y-2.5 pt-6 text-[13px]">
                    <div
                        v-if="fx.current"
                        class="flex justify-between gap-3 border-b border-line pb-2.5"
                    >
                        <dt class="text-ink-3">
                            {{
                                t('Batch {n} চলছে', {
                                    n: fx.current.number,
                                })
                            }}
                        </dt>
                        <dd class="num font-medium">
                            {{ TAKA }}{{ fx.current.rate.toFixed(2) }}/$
                        </dd>
                    </div>
                    <div
                        v-if="fx.queued"
                        class="flex justify-between gap-3 border-b border-line pb-2.5"
                    >
                        <dt class="text-ink-3">
                            {{ t('পরে আরো {n}টা batch', { n: fx.queued }) }}
                        </dt>
                        <dd class="num font-medium">
                            {{ money(fx.queued_usd, 'usd') }}
                        </dd>
                    </div>
                    <div
                        v-if="fx.days !== null"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-ink-3">
                            {{
                                t('রোজ গড়ে {amount} খরচ', {
                                    amount: money(fx.daily_usd, 'usd'),
                                })
                            }}
                        </dt>
                        <dd class="num font-medium whitespace-nowrap">
                            {{ t('প্রায় {n} দিন', { n: fx.days }) }}
                        </dd>
                    </div>
                </dl>

                <!-- Runway: a month of days, filled by how long the dollars last -->
                <div
                    v-if="fx.days !== null"
                    class="mt-3 grid grid-cols-[repeat(30,minmax(0,1fr))] gap-[3px]"
                    role="presentation"
                >
                    <span
                        v-for="d in 30"
                        :key="d"
                        class="h-2 rounded-[2px]"
                        :class="
                            d <= runway
                                ? fx.low
                                    ? 'bg-wait'
                                    : 'bg-brand'
                                : 'bg-sunken'
                        "
                    />
                </div>

                <p
                    v-if="fx.low"
                    class="flex items-start gap-2 pt-4 text-[13px] font-medium"
                    :class="fx.remaining < 0 ? 'text-out' : 'text-wait'"
                >
                    <KIcon name="warn" :size="15" class="mt-0.5 shrink-0" />
                    {{ t('নতুন dollar কিনতে হবে') }}
                </p>
            </div>
        </div>
    </section>
</template>
