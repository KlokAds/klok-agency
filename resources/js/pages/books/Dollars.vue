<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import StatTiles from '@/components/StatTiles.vue';
import type { StatTile } from '@/components/StatTiles.vue';
import MetaCampaigns from '@/components/dollars/MetaCampaigns.vue';
import type { ForecastClient } from '@/components/dollars/MetaCampaigns.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTag from '@/components/k/KTag.vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { openQuickEntry } from '@/lib/quick-entry';
import type { Option } from '@/types';

type Account = {
    id: number;
    code: string;
    name: string;
    kind: string;
    is_active: boolean;
    balance: number | null;
};

// Where the dollars are: the card and other USD accounts, what each ad
// platform still has to charge, and the bought dollars left per FX batch.
const props = defineProps<{
    summary: {
        card: number;
        holding: number;
        platform_due: number;
        card_after: number;
        business_usd: number;
        usd_check: {
            bought: number;
            consumed: number;
            remaining: number;
            ok: boolean;
        };
        usd_at_cost: number;
    };
    ad_accounts: {
        id: number;
        name: string;
        currency: string | null;
        since: string;
        spent: number;
        charged: number;
        pending: number;
        campaigns: {
            id: number;
            slug: string | null;
            name: string;
            client: string | null;
            spent: number;
            charged: number;
            pending: number;
        }[];
        charges: {
            id: number;
            date: string | null;
            amount: number;
            card: string | null;
            by_campaign: { name: string; amount: number }[];
        }[];
    }[];
    platforms: {
        id: number;
        name: string;
        spent: number;
        charged: number;
        due: number;
    }[];
    forecast: {
        clients: ForecastClient[];
        pending: number;
        other: number;
        rate: number | null;
    };
    accounts: Account[];
    batches: {
        id: number;
        number: number;
        date: string | null;
        usd: number;
        bdt: number;
        rate: number;
        left: number;
    }[];
    kinds: Option[];
}>();

const can = computed(() => usePage().props.auth.can);
const kindLabel = (kind: string) =>
    props.kinds.find((k) => k.value === kind)?.label ?? kind;
const holdings = computed(() =>
    props.accounts.filter((a) => a.kind === 'holding' && a.is_active),
);

// The card in four figures: what is on it, what the platforms still take, what stays, and
// what sits in other accounts.
const tiles = computed<StatTile[]>(() => [
    {
        key: 'card',
        label: t('Card-এ এখন'),
        value: money(props.summary.card, 'usd'),
        icon: 'wallet',
        badge: 'bg-white/10 text-white',
        dark: true,
        action: { label: t('Card মেলান'), href: '/reconcile', icon: 'scale' },
    },
    {
        key: 'due',
        label: t('Platform এখনো কাটবে'),
        value: money(props.summary.platform_due, 'usd'),
        icon: 'clock',
        badge: 'bg-wait-soft text-wait',
        text: 'text-wait',
        sub: t('খরচ হয়েছে, card থেকে এখনো কাটেনি'),
    },
    {
        key: 'after',
        label: t('কাটার পরে থাকবে'),
        value: money(props.summary.card_after, 'usd'),
        icon: 'check',
        badge:
            props.summary.card_after < 0
                ? 'bg-out-soft text-out'
                : 'bg-in-soft text-in',
        text: props.summary.card_after < 0 ? 'text-out' : 'text-in',
        sub: t('Card − Platform এখনো কাটবে'),
    },
    {
        key: 'holding',
        label: t('অন্য account-এ'),
        value: money(props.summary.holding, 'usd'),
        icon: 'dollar',
        badge: 'bg-brand-soft text-brand-ink',
        sub: holdings.value.length
            ? holdings.value.map((h) => h.name).join(', ')
            : t('কোনো account নেই'),
    },
]);
const inUse = computed(() => props.batches.find((b) => b.left > 0) ?? null);
const later = computed(() =>
    props.batches.filter((b) => b.left > 0 && b.id !== inUse.value?.id),
);

const accountOpen = ref(false);
const editingAccount = ref<Account | null>(null);
const accountForm = useForm({ name: '', kind: 'platform', is_active: true });
const newKinds = computed(() => props.kinds.filter((k) => k.value !== 'card'));

function startAccount(a?: Account): void {
    editingAccount.value = a ?? null;
    accountForm.clearErrors();
    Object.assign(
        accountForm,
        a
            ? { name: a.name, kind: a.kind, is_active: a.is_active }
            : { name: '', kind: 'platform', is_active: true },
    );
    accountOpen.value = true;
}

function saveAccount(): void {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (accountOpen.value = false),
    };

    if (editingAccount.value) {
        accountForm.put(`/dollars/accounts/${editingAccount.value.id}`, opts);
    } else {
        accountForm.post('/dollars/accounts', opts);
    }
}
</script>

<template>
    <SectionTabs section="dollars" />
    <PageHeader
        :eyebrow="t('টাকা')"
        :title="t('Dollar কোথায়')"
        :lead="
            t(
                'Card-এ আর অন্য account-এ কত dollar, কোন platform এখনো কত কাটবে, আর কেনা dollar কোন batch-এ কত বাকি।',
            )
        "
    >
        <div class="flex flex-wrap gap-2">
            <KButton
                v-if="can['fx.create']"
                icon="swap"
                @click="openQuickEntry('buy-usd')"
                >{{ t('USD কিনলাম') }}</KButton
            >
            <KButton
                v-if="can['usd.create']"
                variant="ink"
                icon="wallet"
                @click="openQuickEntry('card-charge')"
                >{{ t('Card থেকে কেটেছে') }}</KButton
            >
        </div>
    </PageHeader>

    <!-- The card -->
    <StatTiles :tiles="tiles" :cols="4" />

    <!-- Check -->
    <div
        class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-line bg-surface px-5 py-3 text-[14.5px]"
    >
        <span
            class="flex items-center gap-1.5"
            :class="summary.usd_check.ok ? 'text-in' : 'text-out'"
        >
            <KIcon :name="summary.usd_check.ok ? 'check' : 'warn'" :size="14" />
            {{
                summary.usd_check.ok
                    ? t('মিলেছে — কেনা dollar − খরচ = হাতে থাকা dollar')
                    : t('মেলেনি — কোনো USD entry বাদ পড়েছে বা ভুল')
            }}
        </span>
        <span class="text-ink-3"
            >{{ t('কেনা') }}
            <b class="num text-ink">{{
                money(summary.usd_check.bought, 'usd')
            }}</b>
            − {{ t('খরচ') }}
            <b class="num text-ink">{{
                money(summary.usd_check.consumed, 'usd')
            }}</b>
            =
            <b class="num text-ink">{{
                money(summary.usd_check.remaining, 'usd')
            }}</b></span
        >
        <span class="text-ink-3"
            >{{ t('কেনা দামে') }}
            <b class="num text-ink">{{
                money(summary.usd_at_cost, 'bdt')
            }}</b></span
        >
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <!-- FX batches -->
        <section class="panel min-w-0 p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-[16px] font-bold">
                    {{ t('কেনা dollar — batch ধরে') }}
                </h2>
                <KButton href="/fx" size="sm" class="shrink-0"
                    >FX Batch</KButton
                >
            </div>
            <p class="mt-1 text-[13.5px] text-ink-3">
                {{
                    t(
                        'খরচ সবসময় পুরনো batch থেকে কাটে (FIFO) — সেই batch-এর দামে হিসাব হয়।',
                    )
                }}
            </p>
            <p
                v-if="!inUse"
                class="mt-4 rounded-lg bg-out-soft px-3 py-2 text-[14px] text-out"
            >
                {{ t('কোনো batch-এ dollar বাকি নেই — নতুন dollar কিনতে হবে।') }}
            </p>
            <ul class="mt-4 flex flex-col gap-3">
                <li
                    v-for="b in [inUse, ...later].filter((x) => x !== null)"
                    :key="b!.id"
                >
                    <div
                        class="flex items-center justify-between gap-3 text-[14px]"
                    >
                        <span class="flex items-center gap-2">
                            <span class="font-semibold"
                                >Batch {{ b!.number }}</span
                            >
                            <KTag v-if="b!.id === inUse?.id" tone="brand">{{
                                t('চলছে')
                            }}</KTag>
                            <span class="num text-ink-3"
                                >&#2547;{{ b!.rate }}/$</span
                            >
                        </span>
                        <span class="num"
                            >{{ money(b!.left, 'usd') }}
                            <span class="text-ink-3"
                                >/ {{ money(b!.usd, 'usd') }}</span
                            ></span
                        >
                    </div>
                    <div class="mt-1 h-1.5 rounded-full bg-sunken">
                        <div
                            class="h-1.5 rounded-full bg-brand"
                            :style="{
                                width:
                                    (b!.left / Math.max(1, b!.usd)) * 100 + '%',
                            }"
                        />
                    </div>
                    <p v-if="b!.date" class="mt-1 text-[13px] text-ink-3">
                        {{ niceDate(b!.date, false) }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Platforms -->
        <section class="panel min-w-0 overflow-hidden">
            <div class="px-5 pt-5 sm:px-6">
                <h2 class="text-[16px] font-bold">{{ t('Platform ধরে') }}</h2>
                <p class="mt-1 text-[13.5px] text-ink-3">
                    {{
                        t(
                            'কোন platform-এ কত খরচ হয়েছে, card থেকে কত কেটেছে, কত বাকি।',
                        )
                    }}
                </p>
            </div>
            <KEmpty v-if="!platforms.length" :title="t('কোনো platform নেই')" />
            <div v-else class="mt-3 overflow-x-auto">
                <table v-stack class="stack w-full min-w-[440px] text-[14px]">
                    <thead>
                        <tr
                            class="border-b border-line text-left text-[13.5px] font-semibold text-ink-3"
                        >
                            <th class="px-5 py-2.5 sm:px-6">Platform</th>
                            <th class="px-3 py-2.5 text-right">
                                {{ t('খরচ') }}
                            </th>
                            <th class="px-3 py-2.5 text-right">
                                {{ t('কেটেছে') }}
                            </th>
                            <th class="px-5 py-2.5 text-right sm:px-6">
                                {{ t('বাকি') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in platforms"
                            :key="p.id"
                            class="border-b border-line last:border-0"
                        >
                            <td class="px-5 py-2.5 font-medium sm:px-6">
                                {{ p.name }}
                            </td>
                            <td class="num px-3 py-2.5 text-right">
                                {{ money(p.spent, 'usd') }}
                            </td>
                            <td class="num px-3 py-2.5 text-right">
                                {{ money(p.charged, 'usd') }}
                            </td>
                            <td
                                class="num px-5 py-2.5 text-right font-semibold sm:px-6"
                                :class="p.due > 0 ? 'text-wait' : 'text-ink-3'"
                            >
                                {{ money(p.due, 'usd') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel min-w-0 p-5 sm:p-6 xl:col-span-2">
            <h2 class="text-[16px] font-bold">{{ t('Ad account ধরে') }}</h2>
            <p class="mt-1 text-[13.5px] text-ink-3">
                {{
                    t(
                        'Meta যা খরচ গুনেছে, card থেকে যা কেটেছে, আর যা এখনো কাটবে — account আর campaign ধরে। প্রতিটা charge কোন campaign-এর টাকা, তা-ও।',
                    )
                }}
            </p>
            <KEmpty
                v-if="!ad_accounts.length"
                :title="t('এখনো কোনো ad account-এর খরচ নেই')"
                class="mt-3"
            />
            <div v-else class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <article
                    v-for="a in ad_accounts"
                    :key="a.id"
                    class="rounded-xl border border-line"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4"
                    >
                        <h3 class="font-bold">
                            {{ a.name }}
                            <span
                                class="ml-1 text-[13px] font-medium text-ink-3"
                                >{{ a.currency }}</span
                            >
                        </h3>
                        <span class="num text-[13px] text-ink-3">{{
                            t('{date} থেকে', { date: niceDate(a.since) })
                        }}</span>
                    </div>
                    <div
                        class="mt-3 grid grid-cols-3 divide-x divide-line border-y border-line bg-sunken/60 py-2.5 text-center"
                    >
                        <p>
                            <span class="block text-[13px] text-ink-3">{{
                                t('Meta গুনেছে')
                            }}</span>
                            <b class="num text-[15px]">{{
                                money(a.spent, 'usd')
                            }}</b>
                        </p>
                        <p>
                            <span class="block text-[13px] text-in">{{
                                t('Card থেকে কেটেছে')
                            }}</span>
                            <b class="num text-[15px] text-in">{{
                                money(a.charged, 'usd')
                            }}</b>
                        </p>
                        <p>
                            <span class="block text-[13px] text-wait">{{
                                t('এখনো কাটবে')
                            }}</span>
                            <b class="num text-[15px] text-wait">{{
                                money(a.pending, 'usd')
                            }}</b>
                        </p>
                    </div>
                    <ul class="divide-y divide-line">
                        <li
                            v-for="c in a.campaigns"
                            :key="c.id"
                            class="flex items-center gap-3 px-4 py-2 text-[13.5px]"
                        >
                            <span class="min-w-0 flex-1">
                                <Link
                                    :href="`/campaigns/${c.slug ?? c.id}`"
                                    class="block truncate font-medium hover:text-brand"
                                    >{{ c.name }}</Link
                                >
                                <span
                                    v-if="c.client"
                                    class="block truncate text-[13px] text-ink-3"
                                    >{{ c.client }}</span
                                >
                            </span>
                            <span class="num w-16 shrink-0 text-right">{{
                                money(c.spent, 'usd')
                            }}</span>
                            <span
                                class="num w-16 shrink-0 text-right text-in"
                                >{{ money(c.charged, 'usd') }}</span
                            >
                            <span
                                class="num w-16 shrink-0 text-right text-wait"
                                >{{ money(c.pending, 'usd') }}</span
                            >
                        </li>
                    </ul>
                    <details
                        v-if="a.charges.length"
                        class="border-t border-line px-4 py-2.5 text-[13.5px]"
                    >
                        <summary
                            class="cursor-pointer font-semibold text-ink-2"
                        >
                            {{
                                t('{n}টা charge — কোন campaign-এর', {
                                    n: a.charges.length,
                                })
                            }}
                        </summary>
                        <ul class="mt-2 space-y-2">
                            <li v-for="ch in a.charges" :key="ch.id">
                                <p
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span class="num text-ink-3"
                                        >{{ niceDate(ch.date)
                                        }}<template v-if="ch.card">
                                            · card {{ ch.card }}</template
                                        ></span
                                    >
                                    <b class="num">{{
                                        money(ch.amount, 'usd')
                                    }}</b>
                                </p>
                                <p
                                    v-if="ch.by_campaign.length"
                                    class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[13px] text-ink-3"
                                >
                                    <span
                                        v-for="(b, i) in ch.by_campaign"
                                        :key="i"
                                        >{{ b.name }}
                                        <b class="num text-ink">{{
                                            money(b.amount, 'usd')
                                        }}</b></span
                                    >
                                </p>
                                <p v-else class="mt-0.5 text-[13px] text-ink-3">
                                    {{ t('ভাগ এখনো হয়নি') }}
                                </p>
                            </li>
                        </ul>
                    </details>
                </article>
            </div>
        </section>

        <MetaCampaigns :forecast="forecast" />

        <!-- USD accounts -->
        <section class="panel min-w-0 p-5 sm:p-6 xl:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-[16px] font-bold">
                        {{ t('USD account ও platform') }}
                    </h2>
                    <p class="mt-1 text-[13.5px] text-ink-3">
                        {{
                            t(
                                'Card, dollar রাখার অন্য account, আর ad platform।',
                            )
                        }}
                    </p>
                </div>
                <KButton
                    v-if="can['usd.edit']"
                    size="sm"
                    icon="plus"
                    @click="startAccount()"
                    >{{ t('যোগ') }}</KButton
                >
            </div>
            <ul class="mt-3 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                <li
                    v-for="a in accounts"
                    :key="a.id"
                    class="flex items-center gap-3 border-b border-line py-2.5 text-[15px]"
                >
                    <span
                        class="min-w-0 flex-1 truncate font-medium"
                        :class="!a.is_active && 'text-ink-3 line-through'"
                        >{{ a.name }}</span
                    >
                    <span v-if="a.balance !== null" class="num text-[14px]">{{
                        money(a.balance, 'usd')
                    }}</span>
                    <KTag>{{ kindLabel(a.kind) }}</KTag>
                    <button
                        v-if="can['usd.edit']"
                        type="button"
                        class="flex h-11 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg border border-brand/30 bg-brand-soft px-1.5 text-brand-ink transition-colors hover:border-brand"
                        @click="startAccount(a)"
                    >
                        <KIcon name="pen" :size="15" />
                        <span class="text-[13px] leading-none">{{
                            t('বদলান')
                        }}</span>
                    </button>
                </li>
            </ul>
        </section>
    </div>

    <KModal
        v-model="accountOpen"
        width="sm"
        :title="editingAccount ? editingAccount.name : t('নতুন account')"
    >
        <form
            id="acct-form"
            class="flex flex-col gap-4"
            @submit.prevent="saveAccount"
        >
            <KField :label="t('নাম')" :error="accountForm.errors.name"
                ><KInput
                    v-model="accountForm.name"
                    :placeholder="t('যেমন LinkedIn Ads')"
            /></KField>
            <KField v-if="!editingAccount" :label="t('ধরন')"
                ><KSegment v-model="accountForm.kind" :options="newKinds"
            /></KField>
            <KSwitch
                v-if="editingAccount && editingAccount.kind !== 'card'"
                v-model="accountForm.is_active"
                :label="t('চালু')"
            />
        </form>
        <template #footer>
            <KButton variant="quiet" @click="accountOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="acct-form"
                variant="primary"
                :loading="accountForm.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>
</template>
