<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
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
    platforms: {
        id: number;
        name: string;
        spent: number;
        charged: number;
        due: number;
    }[];
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
    <section
        class="grid gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-2 xl:grid-cols-4"
    >
        <div class="bg-rail p-5 text-rail-ink sm:p-6">
            <p class="text-[12.5px] font-medium text-rail-dim">
                {{ t('Card-এ এখন') }}
            </p>
            <p class="num mt-3 text-[30px] leading-none font-medium text-white">
                {{ money(summary.card, 'usd') }}
            </p>
            <Link
                href="/reconcile"
                class="mt-3 inline-block text-[12.5px] text-[#2dd4bf] hover:underline"
                >{{ t('Card মেলান') }} →</Link
            >
        </div>
        <div class="bg-surface p-5 sm:p-6">
            <p class="eyebrow">{{ t('Meta এখনো কাটবে') }}</p>
            <p class="num mt-3 text-[30px] leading-none font-medium text-wait">
                {{ money(summary.platform_due, 'usd') }}
            </p>
            <p class="mt-3 text-[12.5px] text-ink-3">
                {{ t('খরচ হয়েছে, card থেকে এখনো কাটেনি') }}
            </p>
        </div>
        <div class="bg-surface p-5 sm:p-6">
            <p class="eyebrow">{{ t('কাটার পরে থাকবে') }}</p>
            <p
                class="num mt-3 text-[30px] leading-none font-medium"
                :class="summary.card_after < 0 ? 'text-out' : ''"
            >
                {{ money(summary.card_after, 'usd') }}
            </p>
            <p class="mt-3 text-[12.5px] text-ink-3">
                {{ t('Card − Meta এখনো কাটবে') }}
            </p>
        </div>
        <div class="bg-surface p-5 sm:p-6">
            <p class="eyebrow">{{ t('অন্য account-এ') }}</p>
            <p class="num mt-3 text-[30px] leading-none font-medium">
                {{ money(summary.holding, 'usd') }}
            </p>
            <p class="mt-3 truncate text-[12.5px] text-ink-3">
                {{
                    holdings.length
                        ? holdings.map((h) => h.name).join(', ')
                        : t('কোনো account নেই')
                }}
            </p>
        </div>
    </section>

    <!-- Check -->
    <div
        class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-line bg-surface px-5 py-3 text-[13.5px]"
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

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <!-- FX batches -->
        <section class="panel min-w-0 p-5 sm:p-6">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[16px] font-bold">
                    {{ t('কেনা dollar — batch ধরে') }}
                </h2>
                <Link
                    href="/fx"
                    class="text-[13px] font-semibold text-brand-ink hover:underline"
                    >FX Batch →</Link
                >
            </div>
            <p class="mt-1 text-[12.5px] text-ink-3">
                {{
                    t(
                        'খরচ সবসময় পুরনো batch থেকে কাটে (FIFO) — সেই batch-এর দামে হিসাব হয়।',
                    )
                }}
            </p>
            <p
                v-if="!inUse"
                class="mt-4 rounded-lg bg-out-soft px-3 py-2 text-[13px] text-out"
            >
                {{ t('কোনো batch-এ dollar বাকি নেই — নতুন dollar কিনতে হবে।') }}
            </p>
            <ul class="mt-4 flex flex-col gap-3">
                <li
                    v-for="b in [inUse, ...later].filter((x) => x !== null)"
                    :key="b!.id"
                >
                    <div
                        class="flex items-center justify-between gap-3 text-[13px]"
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
                    <p v-if="b!.date" class="mt-1 text-[11.5px] text-ink-3">
                        {{ niceDate(b!.date, false) }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Platforms -->
        <section class="panel min-w-0 overflow-hidden">
            <div class="px-5 pt-5 sm:px-6">
                <h2 class="text-[16px] font-bold">{{ t('Platform ধরে') }}</h2>
                <p class="mt-1 text-[12.5px] text-ink-3">
                    {{
                        t(
                            'কোন platform-এ কত খরচ হয়েছে, card থেকে কত কেটেছে, কত বাকি।',
                        )
                    }}
                </p>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[440px] text-[13px]">
                    <thead>
                        <tr
                            class="border-b border-line text-left text-[12.5px] font-semibold text-ink-3"
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

        <!-- USD accounts -->
        <section class="panel min-w-0 p-5 sm:p-6 xl:col-span-2">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-[16px] font-bold">
                        {{ t('USD account ও platform') }}
                    </h2>
                    <p class="mt-1 text-[12.5px] text-ink-3">
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
            <ul class="mt-3 grid gap-x-8 sm:grid-cols-2">
                <li
                    v-for="a in accounts"
                    :key="a.id"
                    class="flex items-center gap-3 border-b border-line py-2.5 text-[14px]"
                >
                    <span
                        class="min-w-0 flex-1 truncate font-medium"
                        :class="!a.is_active && 'text-ink-3 line-through'"
                        >{{ a.name }}</span
                    >
                    <span v-if="a.balance !== null" class="num text-[13px]">{{
                        money(a.balance, 'usd')
                    }}</span>
                    <KTag>{{ kindLabel(a.kind) }}</KTag>
                    <button
                        v-if="can['usd.edit']"
                        type="button"
                        class="rounded-md p-1.5 text-ink-3 hover:bg-sunken hover:text-ink"
                        :aria-label="t('বদলান')"
                        @click="startAccount(a)"
                    >
                        <KIcon name="pen" :size="15" />
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
