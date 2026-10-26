<script setup lang="ts">
import { computed } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import type { Fx, Summary } from '@/components/dashboard/types';
import { ago, money } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{ summary: Summary; fx: Fx }>();

// Keep a little on the card so a charge never bounces.
const BUFFER = 1000;
const TAKA = '\u{09F3}';

const s = computed(() => props.summary);
// Meta's own figure replaces the book's Meta line when Pilot could read it; other platforms
// stay as the books have them.
// The books already carry Meta's own figure where Pilot could read it (Books::summary), so
// every page shows the same number; a row says when its due is Meta's word and what the book had.
const live = computed(() => s.value.meta_live ?? null);
const dueRows = computed(() =>
    s.value.platforms
        .filter((p) => p.due !== 0 || p.live)
        .map((p) => ({ ...p, book: p.book_due ?? p.due, isLive: !!p.live })),
);
const platformDue = computed(() => s.value.platform_due);
// What stays on the card once every platform has charged what it says it will.
const cardAfter = computed(() => s.value.card - platformDue.value);
const afterTone = computed(() =>
    cardAfter.value < 0 ? 'out' : cardAfter.value < BUFFER ? 'wait' : 'ok',
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
                        class="flex items-center gap-2 text-[14.5px] font-medium text-ink-2"
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
                <p class="mt-2.5 text-[14px] text-ink-3">
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
                            class="flex items-center gap-2 text-[14px] text-ink-2"
                        >
                            <span class="size-2 rounded-full bg-wait" />
                            {{ t('Platform এখনো কাটবে') }}
                        </dt>
                        <dd
                            class="num mt-1 text-[22px] leading-tight font-semibold"
                            :class="platformDue > 0 && 'text-wait'"
                        >
                            {{ money(platformDue, 'usd') }}
                        </dd>
                        <ul
                            v-if="dueRows.length"
                            class="mt-2 space-y-1.5 text-[13.5px]"
                        >
                            <li
                                v-for="p in dueRows"
                                :key="p.id"
                                class="flex items-center justify-between gap-3 rounded-lg bg-sunken px-3 py-2"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="grid size-6 shrink-0 place-items-center rounded-md text-[11px] font-bold text-white"
                                        :class="
                                            p.code === 'meta'
                                                ? 'bg-[#1877f2]'
                                                : p.code === 'google'
                                                  ? 'bg-[#ea4335]'
                                                  : 'bg-ink-3'
                                        "
                                        >{{ p.name.charAt(0) }}</span
                                    >
                                    <span
                                        class="truncate font-medium text-ink"
                                        >{{ p.name }}</span
                                    >
                                    <span
                                        v-if="p.isLive"
                                        class="shrink-0 rounded-md bg-brand-soft px-1.5 py-0.5 text-[11.5px] font-semibold text-brand-ink"
                                        >{{ t('Meta-র হিসাবে') }}</span
                                    >
                                </span>
                                <span class="num shrink-0 text-right">
                                    <b
                                        class="text-[15px]"
                                        :class="
                                            p.due > 0
                                                ? 'text-wait'
                                                : 'text-ink-2'
                                        "
                                        >{{ money(p.due, 'usd') }}</b
                                    >
                                    <span
                                        v-if="
                                            p.isLive &&
                                            Math.abs(p.book - p.due) >= 100
                                        "
                                        class="block text-[11.5px] text-ink-3"
                                        :title="
                                            t(
                                                'খাতার নিজের হিসাব — পুরোনো entry বা charge-এর ভাগ না মিললে এটা আলাদা হয়',
                                            )
                                        "
                                        >{{
                                            t('খাতার হিসাবে {amount}', {
                                                amount: money(p.book, 'usd'),
                                            })
                                        }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                        <p v-else class="mt-1.5 text-[13.5px] text-ink-3">
                            {{ t('সব খরচ card থেকে কাটা হয়ে গেছে।') }}
                        </p>
                        <!-- Meta's own figure, account by account: ours by card, the rest a client's own card. -->
                        <div
                            v-if="live"
                            class="mt-3 rounded-xl border border-brand/30 bg-brand-soft/40 px-3.5 py-3"
                        >
                            <p
                                class="flex items-center justify-between gap-2 text-[12.5px] font-semibold text-brand-ink"
                            >
                                <span class="inline-flex items-center gap-1.5"
                                    ><KIcon name="pulse" :size="14" />{{
                                        t('Ad account ধরে — Meta-র হিসাব')
                                    }}</span
                                >
                                <span class="font-normal text-ink-3">{{
                                    t('Meta থেকে পড়া {ago}', {
                                        ago: ago(live.read_at),
                                    })
                                }}</span>
                            </p>
                            <ul class="mt-2 space-y-1.5 text-[13.5px]">
                                <li
                                    v-for="a in live.accounts"
                                    :key="a.id"
                                    class="flex items-center justify-between gap-3"
                                >
                                    <span
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <span
                                            class="truncate font-medium text-ink"
                                            >{{ a.name }}</span
                                        >
                                        <span
                                            v-if="a.card"
                                            class="num shrink-0 rounded-md bg-surface px-1.5 py-0.5 text-[12px] text-ink-2"
                                            >{{ a.card }}</span
                                        >
                                        <span
                                            v-if="
                                                a.unbilled !== null && !a.ours
                                            "
                                            class="shrink-0 text-[12px] text-ink-3"
                                            >{{
                                                a.card
                                                    ? t('client-এর নিজের card')
                                                    : t('card নেই')
                                            }}</span
                                        >
                                    </span>
                                    <span
                                        class="num shrink-0 text-[15px] font-bold"
                                        :class="
                                            a.ours ? 'text-wait' : 'text-ink-3'
                                        "
                                        >{{
                                            a.unbilled === null
                                                ? '—'
                                                : money(a.unbilled, 'usd')
                                        }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div>
                        <dt
                            class="flex items-center gap-2 text-[14px] text-ink-2"
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
                            {{ money(cardAfter, 'usd') }}
                        </dd>
                        <p class="num mt-1.5 text-[13.5px] text-ink-3">
                            {{ money(s.card, 'usd') }} −
                            {{ money(s.platform_due, 'usd') }}
                        </p>
                    </div>
                </dl>

                <p
                    v-if="afterTone !== 'ok'"
                    class="mt-4 flex items-start gap-2 rounded-lg px-3 py-2 text-[14px] font-medium"
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
                    <p class="text-[14.5px] font-medium text-ink-2">
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

                <p v-if="!fx.current" class="mt-3 text-[14px] text-ink-3">
                    {{ t('কোনো batch-এ dollar বাকি নেই') }}
                </p>

                <!-- The dollars in taka terms: what the ones left cost, how much of the bought has gone. -->
                <dl class="mt-4 grid grid-cols-2 gap-3 text-[14px]">
                    <div class="rounded-xl bg-surface px-3.5 py-2.5">
                        <dt class="text-[12.5px] text-ink-3">
                            {{ t('কেনা দামে') }}
                        </dt>
                        <dd class="num mt-0.5 font-semibold">
                            {{ money(s.usd_at_cost, 'bdt') }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-surface px-3.5 py-2.5">
                        <dt class="text-[12.5px] text-ink-3">
                            {{ t('মোট কেনা · খরচ') }}
                        </dt>
                        <dd class="num mt-0.5 font-semibold">
                            {{ money(s.usd_check.bought, 'usd') }}
                            <span class="text-ink-3"
                                >·
                                {{ money(s.usd_check.consumed, 'usd') }}</span
                            >
                        </dd>
                    </div>
                </dl>
                <div
                    class="mt-2 h-1.5 overflow-hidden rounded-full bg-sunken"
                    :title="t('কেনা dollar-এর কতটা খরচ হয়েছে')"
                >
                    <div
                        class="h-full rounded-full bg-brand"
                        :style="{
                            width:
                                Math.min(
                                    100,
                                    Math.round(
                                        (s.usd_check.consumed /
                                            Math.max(1, s.usd_check.bought)) *
                                            100,
                                    ),
                                ) + '%',
                        }"
                    />
                </div>

                <dl class="mt-auto space-y-2.5 pt-5 text-[14px]">
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
                    class="flex items-start gap-2 pt-4 text-[14px] font-medium"
                    :class="fx.remaining < 0 ? 'text-out' : 'text-wait'"
                >
                    <KIcon name="warn" :size="15" class="mt-0.5 shrink-0" />
                    {{ t('নতুন dollar কিনতে হবে') }}
                </p>
            </div>
        </div>
    </section>
</template>
