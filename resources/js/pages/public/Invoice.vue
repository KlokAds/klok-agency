<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import InvoiceDoc from '@/components/InvoiceDoc.vue';
import PortalLayout from '@/layouts/PortalLayout.vue';
import type { Business, InvoiceDocument } from '@/components/InvoiceDoc.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import { money, niceDate } from '@/lib/format';
import OpenInApp from '@/components/OpenInApp.vue';
import { confirmAction } from '@/lib/feedback';
import { HttpError, getJson, postJson } from '@/lib/http';
import { locale, switchLocale, t } from '@/lib/i18n';

type Method = {
    label: string;
    number?: string;
    type?: string;
    image?: string | null;
    dynamic?: boolean;
    merchant?: string;
    bank?: string;
    branch?: string;
    account_name?: string;
    account_number?: string;
    routing?: string;
};

type State = {
    status: string;
    total: number;
    paid: number;
    due: number;
    payable: boolean;
    auto: boolean;
    allow_partial: boolean;
    charges?: Record<string, number>;
    session: {
        method: string;
        amount: number;
        charge?: number;
        qr: string | null;
        sender: string | null;
        extra: number;
        seconds_left: number;
    } | null;
    checking: boolean;
    payments: {
        id: number;
        method: string;
        amount: number;
        status: string;
        reference: string | null;
        at: string;
    }[];
    renewal?: {
        declined: boolean;
        days?: number | null;
        budget?: number | null;
        rate?: number | null;
        campaign?: string | null;
        stops?: string | null;
    } | null;
};

// The client's invoice: read it, print it, pay it. A wallet payment opens a
// short session for an exact amount; the page asks the server every few
// seconds and turns green by itself when the wallet's SMS arrives.
const props = defineProps<{
    token: string;
    business: Business;
    invoice: InvoiceDocument;
    methods: Record<string, Method>;
    state: State;
}>();

const state = ref<State>(props.state);
// Paid or cancelled: nothing left to pay.
const done = computed(() => ['paid', 'void'].includes(state.value.status));
const base = `/i/${props.token}`;
const bdt = (poisha: number) => money(poisha, 'bdt', { round: false });

const doc = computed<InvoiceDocument>(() => ({
    ...props.invoice,
    status: state.value.status,
    paid: state.value.paid,
    due: state.value.due,
}));

const keys = computed(() => Object.keys(props.methods));
const wallets = computed(() => keys.value.filter((k) => k !== 'bank'));
const chosen = ref<string | null>(props.state.session?.method ?? null);
// What was sent, as most will have: the due plus this way's payment charge.
const claimPlaceholder = computed(() =>
    String(
        (state.value.due +
            (chosen.value ? (state.value.charges?.[chosen.value] ?? 0) : 0)) /
            100,
    ),
);
const method = computed(() =>
    chosen.value ? (props.methods[chosen.value] ?? null) : null,
);
const busy = ref(false);
const error = ref<string | null>(null);
const justPaid = ref(false);
// The thank-you over the page, with paper falling, the moment the money is confirmed.
const celebrate = ref(false);
const CONFETTI_COLORS = [
    '#14b8a6',
    '#2dd4bf',
    '#f59e0b',
    '#ef4444',
    '#8b5cf6',
    '#3b82f6',
    '#ec4899',
    '#22c55e',
];
/** The payment that just paid it, for the thank-you's details. */
const lastPayment = computed(
    () =>
        [...state.value.payments]
            .filter((p) => p.status === 'verified')
            .sort((a, b) => b.at.localeCompare(a.at))[0] ?? null,
);
function viewReceipt(): void {
    celebrate.value = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function printReceipt(): void {
    celebrate.value = false;
    window.setTimeout(() => window.print(), 250);
}
const confetti = Array.from({ length: 90 }, (_, i) => ({
    left: Math.random() * 100,
    delay: Math.random() * 1.8,
    duration: 2.6 + Math.random() * 2.2,
    size: 6 + Math.random() * 7,
    color: CONFETTI_COLORS[i % CONFETTI_COLORS.length],
    round: i % 3 === 0,
    drift: (Math.random() - 0.5) * 140,
    spin: (Math.random() < 0.5 ? -1 : 1) * (360 + Math.random() * 720),
}));

watch(
    () => state.value.status,
    (now, before) => {
        if (now === 'paid' && before !== 'paid') {
            justPaid.value = true;
            celebrate.value = true;
        }
    },
);

// Countdown of the open session.
const left = ref(props.state.session?.seconds_left ?? 0);
watch(
    () => state.value.session?.seconds_left,
    (value) => (left.value = value ?? 0),
);
const clock = computed(
    () =>
        `${Math.floor(left.value / 60)}:${String(left.value % 60).padStart(2, '0')}`,
);

let tick: number | undefined;
let poll: number | undefined;
// Every tap (pay, another way, claim) moves this on: a poll that left before it answers with
// the old state, and is thrown away instead of bringing the old payment back.
let acted = 0;

async function refresh(): Promise<void> {
    const at = acted;
    try {
        const fresh = await getJson<State>(`${base}/status`);
        if (at === acted) {
            state.value = fresh;
        }
    } catch {
        // A missed poll is fine; the next one catches up.
    }
}

onMounted(() => {
    tick = window.setInterval(() => {
        left.value = Math.max(0, left.value - 1);
    }, 1000);
    poll = window.setInterval(() => {
        if (!document.hidden && (state.value.session || state.value.checking)) {
            void refresh();
        }
    }, 5000);
});

onBeforeUnmount(() => {
    window.clearInterval(tick);
    window.clearInterval(poll);
});

const payer = ref('');
const needsPayer = (key: string | null) =>
    key === 'bkash' || key === 'nagad' || key === 'rocket';
/** One line under each way of paying, so the choice is clear before tapping. */
function howTo(key: string): string {
    if (key === 'qr') {
        return t('যেকোনো bank বা wallet app দিয়ে scan');
    }
    if (key === 'bank') {
        return 'NPSB / BEFTN / RTGS';
    }
    const m = props.methods[key];
    return m?.number ? `Send Money · ${m.number}` : 'Send Money';
}
const payerError = ref<string | null>(null);
// The client typed our wallet number (copied from the page) instead of their own.
const digits = (n: string | null | undefined) =>
    (n ?? '').replace(/\D+/g, '').slice(-10);
const payerIsOurs = computed(
    () =>
        digits(payer.value).length === 10 &&
        digits(payer.value) === digits(method.value?.number),
);
// An invoice that allows part payments: how much to pay now (taka; empty = all that is due).
const payNow = ref('');
// The taka sign, from its code (the file keeps Bangla text only inside t()).
const takaSign = String.fromCharCode(0x09f3);
const payNowError = ref<string | null>(null);

// A renewal's invoice: the client picks the days here; the total follows at this invoice's rate.
const dayChoices = [7, 15, 30];
const daysBusy = ref(false);
const daysError = ref<string | null>(null);
const pickDays = ref<number | null>(null);
const otherOpen = ref(false);
const daysPrice = (d: number) =>
    state.value.renewal?.budget && state.value.renewal.rate
        ? Math.round(state.value.renewal.budget * d * state.value.renewal.rate)
        : null;

const errorText = (e: unknown) =>
    e instanceof HttpError
        ? (Object.values(e.errors)[0]?.[0] ?? t('হলো না — আবার চেষ্টা করুন।'))
        : t('হলো না — আবার চেষ্টা করুন।');

// Signed in (a client in the app, or the team): a way back to their own home.
const signedIn = computed(() => Boolean(usePage().props.auth?.user));
const isClient = computed(() => usePage().props.auth?.user?.role === 'Client');

async function setDays(d: number): Promise<void> {
    const days = Math.min(90, Math.max(1, Math.round(d)));
    if (days === state.value.renewal?.days) {
        return;
    }
    acted++;
    daysBusy.value = true;
    daysError.value = null;
    try {
        state.value = await postJson<State>(`${base}/days`, { days });
        chosen.value = null;
        // The invoice's lines and total changed: the page shows the new ones.
        router.reload({ only: ['invoice'] });
    } catch (e) {
        daysError.value = errorText(e);
    } finally {
        daysBusy.value = false;
    }
}

async function stopRenewal(): Promise<void> {
    if (
        !(await confirmAction({
            title: t('নিশ্চিতভাবে campaign বন্ধ করবেন?'),
            body: t(
                'এই invoice বাতিল হবে, আর campaign তার শেষ দিনে বন্ধ হয়ে যাবে — আর বাড়ানো হবে না। নিশ্চিত হলে নিচে চাপুন; আপনাকে email-এও জানানো হবে।',
            ),
            confirm: t('হ্যাঁ, বন্ধ করুন'),
            danger: true,
        }))
    ) {
        return;
    }
    acted++;
    try {
        state.value = await postJson<State>(`${base}/stop`, {});
        router.reload({ only: ['invoice'] });
    } catch (e) {
        daysError.value = errorText(e);
    }
}

async function start(key: string): Promise<void> {
    acted++;
    chosen.value = key;
    error.value = null;

    if (key === 'bank') {
        return;
    }

    // bKash, Nagad, Rocket: the paying number is step 2 — asked for, not an error.
    if (needsPayer(key) && payer.value.trim() === '') {
        payerError.value = null;
        return;
    }

    busy.value = true;
    try {
        payNowError.value = null;
        payerError.value = null;
        state.value = await postJson<State>(`${base}/pay`, {
            method: key,
            payer: payer.value || null,
            amount:
                state.value.allow_partial && payNow.value.trim() !== ''
                    ? payNow.value.replace(/,/g, '')
                    : null,
        });
    } catch (e) {
        if (e instanceof HttpError && e.errors.amount) {
            payNowError.value = e.errors.amount[0];
            chosen.value = null;
        }
        if (e instanceof HttpError && e.errors.payer) {
            payerError.value = e.errors.payer[0];
            chosen.value = null;
        }
        error.value =
            e instanceof HttpError && (e.errors.payer || e.errors.amount)
                ? null
                : t('এখন শুরু করা গেল না — একটু পরে আবার চেষ্টা করুন।');
    } finally {
        busy.value = false;
    }
}

async function cancel(): Promise<void> {
    acted++;
    chosen.value = null;
    state.value = { ...state.value, session: null };
    state.value = await postJson<State>(`${base}/cancel`);
}

const claim = reactive({ reference: '', sender: '', amount: '' });
// The same mistake on the TrxID form: our number typed as the one it was sent from.
const senderIsOurs = computed(() =>
    Object.values(props.methods).some(
        (m) =>
            digits(claim.sender).length === 10 &&
            digits(claim.sender) === digits(m.number),
    ),
);
const claimErrors = ref<Record<string, string[]>>({});
const claimOpen = ref(false);

async function sendClaim(): Promise<void> {
    if (!chosen.value) {
        return;
    }
    acted++;

    busy.value = true;
    claimErrors.value = {};
    try {
        state.value = await postJson<State>(`${base}/claim`, {
            method: chosen.value,
            ...claim,
        });
        claim.reference = '';
        claim.amount = '';
    } catch (e) {
        claimErrors.value =
            e instanceof HttpError
                ? e.errors
                : { reference: [t('পাঠানো গেল না — আবার চেষ্টা করুন।')] };
    } finally {
        busy.value = false;
    }
}

const copied = ref<string | null>(null);
function copy(what: string, text: string): void {
    void navigator.clipboard?.writeText(text);
    copied.value = what;
    window.setTimeout(() => (copied.value = null), 1500);
}

const sessionMethod = computed(() =>
    state.value.session ? props.methods[state.value.session.method] : null,
);
const typeLabel = (type?: string) =>
    type === 'merchant'
        ? t('Merchant নম্বর')
        : type === 'agent'
          ? t('Agent নম্বর')
          : t('Personal নম্বর');
const sendWord = (type?: string) =>
    type === 'merchant'
        ? t('“Payment”')
        : type === 'agent'
          ? t('“Cash In” (agent-এর কাছে)')
          : t('“Send Money”');

function print(): void {
    window.print();
}

const otherLanguage = computed(() => (locale.value === 'en' ? 'বাং' : 'EN')); // i18n

const STATUS_WORD: Record<string, string> = {
    verified: 'পাওয়া গেছে', // i18n
    pending: 'যাচাই হচ্ছে', // i18n
};
</script>

<template>
    <Head :title="`Invoice ${invoice.number}`" />
    <!-- A client in the app sees it inside their portal (its bar and tabs); others, the page alone. -->
    <component
        :is="isClient ? PortalLayout : 'div'"
        :class="isClient ? '' : 'min-h-dvh bg-paper text-ink'"
    >
        <OpenInApp v-if="!isClient" />
        <div
            class="no-print mx-auto flex max-w-[1080px] items-center justify-between gap-3"
            :class="isClient ? '' : 'px-4 pt-5 sm:px-6'"
        >
            <!-- In the app or signed in: back to their own home (the portal, or the books). -->
            <Link
                v-if="signedIn"
                :href="isClient ? '/portal/invoices' : '/'"
                class="flex min-w-0 items-center gap-1.5 rounded-lg py-1 pr-2 text-[13.5px] font-semibold text-brand hover:bg-sunken"
                ><KIcon name="back" :size="18" />{{
                    isClient ? t('সব invoice') : t('হোম')
                }}</Link
            >
            <p v-else class="truncate text-[13px] font-semibold text-ink-2">
                {{ business.name }}
            </p>
            <div class="flex items-center gap-1">
                <KButton size="sm" variant="quiet" icon="print" @click="print">
                    {{ t('Print / PDF') }}
                </KButton>
                <button
                    type="button"
                    class="h-8 rounded-lg px-2.5 text-[12.5px] font-bold text-ink-2 hover:bg-sunken"
                    @click="switchLocale(locale === 'en' ? 'bn' : 'en')"
                >
                    {{ otherLanguage }}
                </button>
            </div>
        </div>

        <!-- Paid or cancelled: nothing to pay, so one column; the result sits above the invoice. -->
        <main
            class="mx-auto grid grid-cols-1 gap-5 px-4 py-5 sm:px-6"
            :class="
                done
                    ? 'max-w-[860px]'
                    : 'max-w-[1080px] lg:grid-cols-[minmax(0,1fr)_360px] lg:items-stretch'
            "
        >
            <InvoiceDoc
                :invoice="doc"
                :business="business"
                :home="signedIn ? '/' : null"
            />

            <aside
                class="no-print gap-4"
                :class="
                    done
                        ? [
                              'order-first grid grid-cols-1',
                              state.payments.length ? 'sm:grid-cols-2' : '',
                          ]
                        : 'flex flex-col lg:sticky lg:top-5'
                "
            >
                <!-- A renewal: how many more days (the amount follows), or stop. -->
                <section
                    v-if="
                        state.renewal &&
                        !state.renewal.declined &&
                        state.payable &&
                        !state.session
                    "
                    class="panel mb-4 p-5"
                >
                    <h2 class="text-[16px] font-bold">
                        {{ t('কত দিন চালু রাখবেন?') }}
                    </h2>
                    <p class="mt-1 text-[13px] text-ink-3">
                        {{ state.renewal.campaign }} ·
                        {{ state.renewal.stops }}
                    </p>
                    <div class="mt-3 grid grid-cols-4 gap-2">
                        <button
                            v-for="d in dayChoices"
                            :key="d"
                            type="button"
                            class="flex h-14 flex-col items-center justify-center rounded-xl border-2 text-[14.5px] font-semibold transition-colors"
                            :class="
                                state.renewal.days === d
                                    ? 'border-brand bg-brand-soft text-brand'
                                    : 'border-line-2'
                            "
                            :disabled="daysBusy"
                            @click="setDays(d)"
                        >
                            {{ t('{n} দিন', { n: d }) }}
                            <span
                                v-if="daysPrice(d)"
                                class="num text-[11px] font-medium text-ink-3"
                                >{{ bdt(daysPrice(d) ?? 0) }}</span
                            >
                        </button>
                        <!-- Any other number of days: a choice like the rest, then a box to type it. -->
                        <button
                            type="button"
                            class="flex h-14 flex-col items-center justify-center rounded-xl border-2 text-[14.5px] font-semibold transition-colors"
                            :class="
                                otherOpen ||
                                (state.renewal.days &&
                                    !dayChoices.includes(state.renewal.days))
                                    ? 'border-brand bg-brand-soft text-brand'
                                    : 'border-line-2'
                            "
                            @click="otherOpen = true"
                        >
                            {{
                                state.renewal.days &&
                                !dayChoices.includes(state.renewal.days)
                                    ? t('{n} দিন', { n: state.renewal.days })
                                    : t('অন্য')
                            }}
                            <span class="text-[11px] font-medium text-ink-3">{{
                                t('নিজে লিখুন')
                            }}</span>
                        </button>
                    </div>
                    <div v-if="otherOpen" class="mt-3 flex gap-2">
                        <input
                            v-model.number="pickDays"
                            type="number"
                            min="1"
                            max="90"
                            inputmode="numeric"
                            :placeholder="t('কত দিন? (১–৯০)')"
                            class="h-12 min-w-0 flex-1 rounded-xl border-2 border-brand bg-transparent px-4 text-[15px] font-semibold"
                            @keyup.enter="pickDays && setDays(pickDays)"
                        />
                        <KButton
                            variant="primary"
                            :loading="daysBusy"
                            :disabled="!pickDays"
                            @click="
                                pickDays &&
                                setDays(pickDays).then(
                                    () => (otherOpen = false),
                                )
                            "
                            >{{ t('ঠিক আছে') }}</KButton
                        >
                    </div>
                    <p class="mt-2 text-[12.5px] text-ink-3">
                        {{
                            t(
                                'দিন বদলালে নিচের মোট টাকাও বদলাবে। Pay হলেই campaign নিজে বেড়ে যাবে।',
                            )
                        }}
                    </p>
                    <p v-if="daysError" class="mt-2 text-[13px] text-out">
                        {{ daysError }}
                    </p>
                    <div class="mt-4 border-t border-line pt-4">
                        <p class="text-[13px] text-ink-3">
                            {{ t('চালু রাখতে না চাইলে:') }}
                        </p>
                        <KButton
                            variant="danger"
                            block
                            icon="close"
                            class="mt-2"
                            @click="stopRenewal"
                            >{{ t('আর চালাবো না — campaign থামুক') }}</KButton
                        >
                    </div>
                </section>
                <section
                    v-if="state.renewal?.declined"
                    class="panel mb-4 p-5 text-[14px] text-ink-2"
                >
                    {{
                        t(
                            'আপনি জানিয়েছেন আর চালাবেন না — {stops}। মত বদলালে Klok Ads-কে জানান।',
                            {
                                stops: state.renewal.stops ?? '',
                            },
                        )
                    }}
                </section>

                <!-- Paid -->
                <section
                    v-if="state.status === 'paid'"
                    class="panel flex items-center gap-4 p-5"
                    :class="justPaid ? 'k-rise' : ''"
                >
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-full bg-in-soft text-in"
                        ><KIcon name="check" :size="26"
                    /></span>
                    <span class="min-w-0">
                        <span class="block text-[17px] font-bold">
                            {{ t('পেমেন্ট পাওয়া গেছে ✓') }}
                        </span>
                        <span class="block text-[13px] text-ink-3">
                            {{ t('ধন্যবাদ! এই invoice পুরো পরিশোধ হয়েছে।') }}
                        </span>
                    </span>
                </section>

                <section
                    v-else-if="state.status === 'void'"
                    class="panel p-6 text-center text-[14px] text-ink-2"
                >
                    {{ t('এই invoice বাতিল করা হয়েছে।') }}
                </section>

                <!-- Open session -->
                <section
                    v-else-if="state.session && sessionMethod"
                    class="panel flex flex-col p-5 lg:flex-1"
                    role="region"
                    :aria-label="t('পেমেন্ট')"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="flex items-center gap-2 text-[16px] font-bold"
                        >
                            <KIcon name="wallet" class="text-brand" />{{
                                t('{wallet}-এ পেমেন্ট', {
                                    wallet: sessionMethod.label,
                                })
                            }}
                        </h2>
                        <!-- A clear way back to the other ways to pay. -->
                        <button
                            type="button"
                            class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-brand/40 bg-brand-soft px-3.5 text-[13px] font-semibold text-brand transition-colors hover:bg-brand hover:text-white"
                            @click="cancel"
                        >
                            <KIcon name="swap" :size="15" />{{
                                t('অন্য উপায়')
                            }}
                        </button>
                    </div>

                    <div
                        v-if="!state.session.qr"
                        class="mt-4 rounded-xl border border-brand/30 bg-brand-soft p-4 text-center"
                    >
                        <p class="text-[12px] text-ink-2">
                            {{ t('ঠিক এই পরিমাণ পাঠান') }}
                        </p>
                        <div
                            class="mt-1 flex items-center justify-center gap-3"
                        >
                            <p
                                class="num text-[30px] leading-none font-semibold"
                            >
                                {{ bdt(state.session.amount) }}
                            </p>
                            <KButton
                                size="sm"
                                @click="
                                    copy(
                                        'amount',
                                        String(state.session.amount / 100),
                                    )
                                "
                                >{{
                                    copied === 'amount'
                                        ? t('Copy হয়েছে')
                                        : 'Copy'
                                }}</KButton
                            >
                        </div>
                        <p
                            v-if="state.session.charge"
                            class="mt-2 text-[12px] text-ink-3"
                        >
                            {{
                                t('এর মধ্যে {charge} payment charge।', {
                                    charge: bdt(state.session.charge),
                                })
                            }}
                        </p>
                        <p
                            v-if="state.session.extra > 0"
                            class="mt-2 text-[12px] text-wait"
                        >
                            {{
                                t(
                                    '{extra} বেশি — যাতে আপনার পেমেন্ট সঙ্গে সঙ্গে চেনা যায়।',
                                    { extra: bdt(state.session.extra) },
                                )
                            }}
                        </p>
                    </div>

                    <p
                        v-if="sessionMethod.number && state.session.sender"
                        class="mt-3 flex items-center gap-2 rounded-xl bg-wait-soft px-4 py-3 text-[13px] text-wait"
                    >
                        <KIcon name="warn" :size="15" class="shrink-0" />{{
                            t(
                                'ঠিক {number} থেকেই পাঠান — অন্য নম্বর থেকে পাঠালে নিজে থেকে মিলবে না।',
                                { number: state.session.sender },
                            )
                        }}
                    </p>
                    <div
                        v-if="sessionMethod.number"
                        class="mt-3 flex items-center justify-between gap-3 rounded-xl border border-line p-4"
                    >
                        <div class="min-w-0">
                            <p class="text-[12px] text-ink-3">
                                {{ typeLabel(sessionMethod.type) }}
                            </p>
                            <p class="num mt-0.5 text-[22px] font-semibold">
                                {{ sessionMethod.number }}
                            </p>
                        </div>
                        <KButton
                            size="sm"
                            @click="copy('number', sessionMethod.number ?? '')"
                            >{{
                                copied === 'number' ? t('Copy হয়েছে') : 'Copy'
                            }}</KButton
                        >
                    </div>
                    <div
                        v-else-if="state.session.qr || sessionMethod.image"
                        class="mt-4 rounded-xl border border-line p-4 text-center"
                        :class="state.session.qr && 'bg-sunken'"
                    >
                        <div
                            v-if="state.session.qr"
                            class="mb-3 flex items-center justify-between gap-3 text-left"
                        >
                            <div>
                                <p class="text-[12px] text-ink-2">
                                    {{ t('ঠিক এই পরিমাণ') }}
                                </p>
                                <p
                                    class="num text-[24px] leading-tight font-semibold"
                                >
                                    {{ bdt(state.session.amount) }}
                                </p>
                            </div>
                            <KButton
                                size="sm"
                                @click="
                                    copy(
                                        'amount',
                                        String(state.session.amount / 100),
                                    )
                                "
                                >{{
                                    copied === 'amount'
                                        ? t('Copy হয়েছে')
                                        : 'Copy'
                                }}</KButton
                            >
                        </div>
                        <!-- Made for this payment: the amount is in the QR and the payer's app locks it. -->
                        <img
                            :src="state.session.qr ?? sessionMethod.image ?? ''"
                            alt="Bangla QR"
                            class="mx-auto w-full max-w-[220px] rounded-xl bg-white"
                            :class="state.session.qr ? '' : 'p-2'"
                        />
                        <p
                            v-if="state.session.qr"
                            class="mt-2 flex items-center justify-center gap-1.5 text-[12.5px] font-semibold text-brand-ink"
                        >
                            <KIcon name="lock" :size="13" />{{
                                t(
                                    'QR-এ {amount} বসানো আছে — scan করলে নিজে থেকে আসবে',
                                    {
                                        amount: bdt(state.session.amount),
                                    },
                                )
                            }}
                        </p>
                        <p
                            v-if="state.session.qr && state.session.charge"
                            class="mt-1 text-[12px] text-ink-3"
                        >
                            {{
                                t('এর মধ্যে {charge} payment charge।', {
                                    charge: bdt(state.session.charge),
                                })
                            }}
                        </p>
                        <p
                            v-if="state.session.qr && state.session.extra > 0"
                            class="mt-1 text-[12px] text-wait"
                        >
                            {{
                                t(
                                    '{extra} বেশি — যাতে আপনার পেমেন্ট সঙ্গে সঙ্গে চেনা যায়।',
                                    { extra: bdt(state.session.extra) },
                                )
                            }}
                        </p>
                        <p
                            v-if="sessionMethod.merchant"
                            class="mt-2 text-[12.5px] text-ink-2"
                        >
                            {{ sessionMethod.merchant }}
                        </p>
                    </div>

                    <ol class="mt-4 space-y-2 text-[13px] text-ink-2">
                        <template v-if="state.session.method === 'qr'">
                            <li class="flex gap-2.5">
                                <span
                                    class="num grid size-5 shrink-0 place-items-center rounded-full bg-sunken text-[11px]"
                                    >1</span
                                >
                                {{
                                    t(
                                        'bKash, Nagad, Rocket বা ব্যাংকের app খুলে “Scan QR” বেছে নিন।',
                                    )
                                }}
                            </li>
                            <li class="flex gap-2.5">
                                <span
                                    class="num grid size-5 shrink-0 place-items-center rounded-full bg-sunken text-[11px]"
                                    >2</span
                                >
                                {{
                                    t('উপরের QR scan করে ঠিক {amount} দিন।', {
                                        amount: bdt(state.session.amount),
                                    })
                                }}
                            </li>
                        </template>
                        <template v-else>
                            <li class="flex gap-2.5">
                                <span
                                    class="num grid size-5 shrink-0 place-items-center rounded-full bg-sunken text-[11px]"
                                    >1</span
                                >
                                {{
                                    t(
                                        '{wallet} app খুলুন (বা USSD dial করুন)।',
                                        { wallet: sessionMethod.label },
                                    )
                                }}
                            </li>
                            <li class="flex gap-2.5">
                                <span
                                    class="num grid size-5 shrink-0 place-items-center rounded-full bg-sunken text-[11px]"
                                    >2</span
                                >
                                {{
                                    t('{action} বেছে উপরের নম্বরটা দিন।', {
                                        action: sendWord(sessionMethod.type),
                                    })
                                }}
                            </li>
                            <li class="flex gap-2.5">
                                <span
                                    class="num grid size-5 shrink-0 place-items-center rounded-full bg-sunken text-[11px]"
                                    >3</span
                                >
                                {{
                                    t(
                                        'ঠিক {amount} দিয়ে PIN দিয়ে confirm করুন।',
                                        { amount: bdt(state.session.amount) },
                                    )
                                }}
                            </li>
                        </template>
                        <li v-if="state.auto" class="flex gap-2.5 text-ink">
                            <span
                                class="grid size-5 shrink-0 place-items-center rounded-full bg-in-soft text-in"
                                ><KIcon name="check" :size="12"
                            /></span>
                            {{
                                t(
                                    'ব্যস — পাতাটা খোলা রাখুন, টাকা এলে নিজে থেকেই দেখাবে।',
                                )
                            }}
                        </li>
                    </ol>

                    <div
                        v-if="state.auto"
                        class="mt-4 flex items-center justify-between rounded-xl bg-sunken px-4 py-3 text-[13px]"
                    >
                        <span class="flex items-center gap-2.5"
                            ><span class="relative flex size-2.5"
                                ><span
                                    class="absolute inline-flex size-full animate-ping rounded-full bg-brand opacity-60" /><span
                                    class="relative inline-flex size-2.5 rounded-full bg-brand" /></span
                            >{{ t('পেমেন্টের অপেক্ষায়…') }}</span
                        >
                        <span class="num text-[12px] text-ink-3">{{
                            clock
                        }}</span>
                    </div>

                    <details class="mt-4" :open="!state.auto || claimOpen">
                        <summary
                            class="cursor-pointer text-[12.5px] font-semibold text-ink-2 hover:text-ink"
                        >
                            {{
                                state.auto
                                    ? t(
                                          'পাঠিয়েছেন কিন্তু কয়েক মিনিটেও দেখাচ্ছে না? TrxID দিন',
                                      )
                                    : t('পাঠানোর পর TrxID দিন')
                            }}
                        </summary>
                        <form
                            class="mt-3 grid grid-cols-1 gap-3"
                            @submit.prevent="sendClaim"
                        >
                            <KField
                                label="TrxID"
                                :error="claimErrors.reference?.[0]"
                            >
                                <KInput
                                    v-model="claim.reference"
                                    :invalid="!!claimErrors.reference"
                                    placeholder="BJK7Q2M9XZ"
                                />
                            </KField>
                            <KField
                                :label="t('আপনার যে নম্বর থেকে পাঠিয়েছেন')"
                                :error="
                                    (senderIsOurs
                                        ? t(
                                              'এটা Klok Ads-এর নম্বর — এখানে আপনার নিজের নম্বর দিন, যেটা থেকে টাকা পাঠাবেন।',
                                          )
                                        : null) ?? claimErrors.sender?.[0]
                                "
                            >
                                <KInput
                                    v-model="claim.sender"
                                    type="tel"
                                    inputmode="tel"
                                    :invalid="
                                        senderIsOurs || !!claimErrors.sender
                                    "
                                    placeholder="01XXXXXXXXX"
                                />
                            </KField>
                            <KButton
                                type="submit"
                                variant="primary"
                                block
                                :loading="busy"
                                >{{ t('পাঠান') }}</KButton
                            >
                        </form>
                    </details>
                    <!-- Bottom of the card, level with the invoice's own footer. -->
                    <p
                        class="mt-5 flex items-center gap-2 border-t border-line pt-4 text-[12px] text-ink-3 lg:mt-auto"
                    >
                        <KIcon
                            name="lock"
                            :size="14"
                            class="shrink-0 text-brand"
                        />{{
                            t(
                                'টাকা সরাসরি {name}-এর account-এ যায় · পেমেন্ট নিজে থেকে মিলে যায়, রসিদ এই পাতাতেই।',
                                { name: business.name },
                            )
                        }}
                    </p>
                </section>

                <!-- Choose a way to pay -->
                <section
                    v-else-if="state.payable"
                    class="panel flex flex-col p-5 lg:flex-1"
                >
                    <h2 class="text-[16px] font-bold">
                        {{
                            t('{amount} পরিশোধ করুন', {
                                amount: bdt(state.due),
                            })
                        }}
                    </h2>
                    <ol
                        v-if="keys.length"
                        class="mt-3 grid grid-cols-3 gap-2 text-center text-[11.5px] leading-tight text-ink-3"
                    >
                        <li
                            v-for="(step, i) in [
                                t('উপায় বাছুন'),
                                t('টাকা পাঠান'),
                                t('নিজে থেকে মিলে যাবে'),
                            ]"
                            :key="i"
                            class="flex flex-col items-center gap-1"
                        >
                            <span
                                class="grid size-6 place-items-center rounded-full text-[12px] font-bold"
                                :class="
                                    i === 0 || (i === 1 && chosen)
                                        ? 'bg-brand text-white'
                                        : 'bg-sunken text-ink-3'
                                "
                                >{{ i + 1 }}</span
                            >{{ step }}
                        </li>
                    </ol>

                    <p
                        v-if="state.checking"
                        class="mt-3 flex items-center gap-2 rounded-lg bg-wait-soft px-3 py-2 text-[12.5px] text-wait"
                    >
                        <KIcon name="clock" :size="15" />{{
                            t(
                                'আপনার দেওয়া তথ্য যাচাই হচ্ছে — এই পাতা নিজে থেকে বদলাবে।',
                            )
                        }}
                    </p>

                    <p v-if="!keys.length" class="mt-3 text-[13px] text-ink-2">
                        {{
                            t(
                                'পেমেন্টের উপায় জানতে {name}-এর সাথে যোগাযোগ করুন।',
                                { name: business.name },
                            )
                        }}
                    </p>

                    <!-- Part payment, when the invoice allows it: the client says how much first. -->
                    <div v-if="state.allow_partial && keys.length" class="mt-4">
                        <KField
                            :label="t('এখন কত দেবেন?')"
                            :hint="
                                t('খালি রাখলে পুরো বাকি {due}। কমপক্ষে ৳10।', {
                                    due: bdt(state.due),
                                })
                            "
                            :error="payNowError ?? undefined"
                        >
                            <KInput
                                v-model="payNow"
                                inputmode="decimal"
                                :placeholder="
                                    String(Math.ceil(state.due / 100))
                                "
                                :prefix="takaSign"
                            />
                        </KField>
                    </div>

                    <p
                        v-if="keys.length"
                        class="mt-5 mb-2 text-[13px] font-semibold text-ink"
                    >
                        {{ t('১. কীভাবে দেবেন?') }}
                    </p>
                    <div
                        v-if="keys.length"
                        class="grid grid-cols-1 gap-2 sm:grid-cols-2"
                    >
                        <button
                            v-for="key in [
                                ...wallets,
                                ...(methods.bank ? ['bank'] : []),
                            ]"
                            :key="key"
                            type="button"
                            class="flex min-h-16 items-center gap-3 rounded-xl border-2 px-3.5 py-3 text-left text-[14.5px] font-semibold transition-colors"
                            :class="
                                chosen === key
                                    ? 'border-brand bg-brand-soft'
                                    : 'border-line-2 hover:border-ink-3'
                            "
                            :disabled="busy"
                            @click="start(key)"
                        >
                            <KIcon
                                :name="
                                    key === 'bank'
                                        ? 'scale'
                                        : key === 'qr'
                                          ? 'list'
                                          : 'wallet'
                                "
                                :size="22"
                                class="shrink-0 text-brand"
                            /><span
                                class="flex min-w-0 flex-1 flex-col leading-tight"
                                >{{ methods[key].label
                                }}<span
                                    class="mt-0.5 truncate text-[12px] font-medium text-ink-3"
                                    >{{ howTo(key) }}</span
                                ></span
                            ><span
                                v-if="state.charges?.[key]"
                                class="shrink-0 rounded-full bg-wait-soft px-2 py-0.5 text-[11px] font-semibold text-wait"
                                >+{{ bdt(state.charges[key]) }}</span
                            >
                        </button>
                    </div>

                    <!-- 2. bKash, Nagad, Rocket: the client's own number, the one the money leaves from. -->
                    <div
                        v-if="needsPayer(chosen) && !state.session"
                        class="mt-5 rounded-2xl border border-brand/40 bg-brand-soft p-4"
                    >
                        <p class="text-[14px] font-bold text-ink">
                            {{
                                t('২. আপনার {wallet} নম্বর দিন', {
                                    wallet: method?.label ?? '',
                                })
                            }}
                        </p>
                        <p class="mt-0.5 text-[12.5px] text-ink-2">
                            {{
                                t(
                                    'যে নম্বর থেকে আপনি টাকা পাঠাবেন — আপনার নিজের নম্বর। এটা দিলে টাকা পাঠানোর সাথে সাথে নিজে থেকে মিলে যাবে।',
                                )
                            }}
                        </p>

                        <!-- Which way the money goes: from your number, to ours. -->
                        <div
                            class="mt-3 flex items-center gap-2 rounded-xl bg-surface p-2.5 text-[12px]"
                        >
                            <span
                                class="min-w-0 flex-1 rounded-lg border-2 border-brand bg-brand-soft px-2.5 py-1.5"
                            >
                                <span class="block font-semibold text-brand">{{
                                    t('আপনার নম্বর')
                                }}</span>
                                <span
                                    class="num block truncate font-bold text-ink"
                                    >{{ payer.trim() || '01XXXXXXXXX' }}</span
                                >
                            </span>
                            <KIcon
                                name="arrow"
                                :size="18"
                                class="shrink-0 text-ink-3"
                            />
                            <span
                                class="min-w-0 flex-1 rounded-lg border border-line px-2.5 py-1.5 opacity-80"
                            >
                                <span class="block font-semibold text-ink-3"
                                    >Klok Ads</span
                                >
                                <span class="num block truncate text-ink-2">{{
                                    method?.number ?? '—'
                                }}</span>
                            </span>
                        </div>

                        <KField
                            class="mt-3"
                            :label="t('আপনার নম্বর (যেটা থেকে পাঠাবেন)')"
                            :error="
                                (payerIsOurs
                                    ? t(
                                          'এটা Klok Ads-এর নম্বর — এখানে আপনার নিজের নম্বর দিন, যেটা থেকে টাকা পাঠাবেন।',
                                      )
                                    : null) ??
                                payerError ??
                                undefined
                            "
                        >
                            <KInput
                                v-model="payer"
                                type="tel"
                                inputmode="tel"
                                prefix="📱"
                                :invalid="payerIsOurs || !!payerError"
                                :placeholder="t('যেমন 01712345678')"
                            />
                        </KField>
                        <KButton
                            variant="primary"
                            block
                            class="mt-3"
                            :loading="busy"
                            :disabled="payerIsOurs"
                            @click="chosen && start(chosen)"
                            >{{ t('এগিয়ে যান') }}</KButton
                        >
                    </div>

                    <p v-if="error" class="mt-3 text-[12.5px] text-out">
                        {{ error }}
                    </p>

                    <!-- Bank / NPSB -->
                    <div
                        v-if="chosen === 'bank' && method"
                        class="mt-5 border-t border-line pt-4"
                    >
                        <p class="text-[13px] text-ink-2">
                            {{
                                t(
                                    '{amount} এই account-এ পাঠান (NPSB / BEFTN / RTGS):',
                                    {
                                        amount: bdt(
                                            state.due +
                                                (state.charges?.bank ?? 0),
                                        ),
                                    },
                                )
                            }}
                        </p>
                        <p
                            v-if="state.charges?.bank"
                            class="mt-1 text-[12px] text-ink-3"
                        >
                            {{
                                t('বাকি {due} + {charge} payment charge।', {
                                    due: bdt(state.due),
                                    charge: bdt(state.charges.bank),
                                })
                            }}
                        </p>
                        <dl
                            class="mt-3 grid grid-cols-1 gap-2 rounded-xl border border-line p-4 text-[13px]"
                        >
                            <div
                                v-for="[label, value] in [
                                    [t('ব্যাংক'), method.bank],
                                    [t('শাখা'), method.branch],
                                    [t('Account-এর নাম'), method.account_name],
                                    [t('Account নম্বর'), method.account_number],
                                    ['Routing', method.routing],
                                ].filter((r) => r[1])"
                                :key="label"
                                class="flex justify-between gap-3"
                            >
                                <dt class="text-ink-3">{{ label }}</dt>
                                <dd
                                    class="num text-right font-medium break-all"
                                >
                                    {{ value }}
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-[12px] text-ink-3">
                            {{
                                t('Reference-এ লিখুন: {number}', {
                                    number: invoice.number,
                                })
                            }}
                        </p>

                        <form
                            class="mt-4 grid grid-cols-1 gap-3"
                            @submit.prevent="sendClaim"
                        >
                            <p class="text-[13px] font-semibold">
                                {{ t('পাঠানোর পর জানান') }}
                            </p>
                            <KField
                                :label="t('Transaction / reference নম্বর')"
                                :error="claimErrors.reference?.[0]"
                            >
                                <KInput
                                    v-model="claim.reference"
                                    :invalid="!!claimErrors.reference"
                                />
                            </KField>
                            <KField
                                :label="t('কোন account / ব্যাংক থেকে')"
                                :error="claimErrors.sender?.[0]"
                            >
                                <KInput
                                    v-model="claim.sender"
                                    :invalid="!!claimErrors.sender"
                                />
                            </KField>
                            <KField
                                :label="t('কত পাঠিয়েছেন')"
                                optional
                                :error="claimErrors.amount?.[0]"
                            >
                                <KInput
                                    v-model="claim.amount"
                                    inputmode="decimal"
                                    :placeholder="claimPlaceholder"
                                />
                            </KField>
                            <KButton
                                type="submit"
                                variant="primary"
                                block
                                :loading="busy"
                                >{{ t('পাঠান') }}</KButton
                            >
                        </form>
                    </div>
                    <!-- Bottom of the card, level with the invoice's own footer. -->
                    <p
                        class="mt-5 flex items-center gap-2 border-t border-line pt-4 text-[12px] text-ink-3 lg:mt-auto"
                    >
                        <KIcon
                            name="lock"
                            :size="14"
                            class="shrink-0 text-brand"
                        />{{
                            t(
                                'টাকা সরাসরি {name}-এর account-এ যায় · পেমেন্ট নিজে থেকে মিলে যায়, রসিদ এই পাতাতেই।',
                                { name: business.name },
                            )
                        }}
                    </p>
                </section>

                <!-- History -->
                <section v-if="state.payments.length" class="panel p-5">
                    <h3 class="text-[14px] font-bold">{{ t('পেমেন্ট') }}</h3>
                    <ul
                        class="mt-2 divide-y divide-dashed divide-line text-[13px]"
                    >
                        <li
                            v-for="p in state.payments"
                            :key="p.id"
                            class="flex items-center justify-between gap-3 py-2.5"
                        >
                            <span class="min-w-0">
                                <span class="font-medium">{{ p.method }}</span>
                                <span
                                    class="num block truncate text-[11.5px] text-ink-3"
                                    >{{ niceDate(p.at.slice(0, 10))
                                    }}<template v-if="p.reference">
                                        · {{ p.reference }}</template
                                    ></span
                                >
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="num block">{{
                                    bdt(p.amount)
                                }}</span>
                                <span
                                    class="block text-[11.5px]"
                                    :class="
                                        p.status === 'verified'
                                            ? 'text-in'
                                            : 'text-wait'
                                    "
                                    >{{
                                        t(STATUS_WORD[p.status] ?? p.status)
                                    }}</span
                                >
                            </span>
                        </li>
                    </ul>
                </section>
            </aside>
        </main>

        <footer
            class="no-print mx-auto max-w-[1080px] px-4 pb-10 text-[11.5px] text-ink-3 sm:px-6"
        >
            {{
                t('প্রশ্ন থাকলে {name}-এর সাথে যোগাযোগ করুন', {
                    name: business.name,
                })
            }}<template v-if="business.phone"> · {{ business.phone }}</template>
        </footer>

        <Teleport to="body">
            <div
                v-if="celebrate"
                class="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4"
                role="dialog"
                aria-modal="true"
                @click.self="celebrate = false"
            >
                <div
                    class="pointer-events-none absolute inset-0 overflow-hidden"
                    aria-hidden="true"
                >
                    <span
                        v-for="(c, i) in confetti"
                        :key="i"
                        class="confetti"
                        :style="{
                            left: c.left + '%',
                            width: c.size + 'px',
                            height: (c.round ? c.size : c.size * 0.45) + 'px',
                            background: c.color,
                            borderRadius: c.round ? '50%' : '2px',
                            animationDelay: c.delay + 's',
                            animationDuration: c.duration + 's',
                            '--drift': c.drift + 'px',
                            '--spin': c.spin + 'deg',
                        }"
                    />
                </div>
                <div
                    class="panel k-rise relative w-full max-w-sm overflow-hidden text-center shadow-2xl"
                >
                    <div class="bg-brand-soft px-7 pt-7 pb-5">
                        <span
                            class="pop mx-auto grid size-16 place-items-center rounded-full bg-in text-white"
                            ><KIcon name="check" :size="34"
                        /></span>
                        <h2 class="mt-4 text-[22px] font-bold">
                            {{ t('অভিনন্দন!') }}
                        </h2>
                        <p class="mt-1 text-[14.5px] text-ink-2">
                            {{ t('আপনার পুরো পেমেন্ট আমরা পেয়েছি।') }}
                        </p>
                        <p class="num mt-3 text-[32px] font-bold text-ink">
                            {{ bdt(state.paid) }}
                        </p>
                    </div>
                    <dl
                        class="grid grid-cols-1 gap-2 px-7 py-5 text-left text-[13px]"
                    >
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-3">Invoice</dt>
                            <dd class="num font-semibold">
                                {{ invoice.number }}
                            </dd>
                        </div>
                        <div
                            v-if="lastPayment"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-ink-3">{{ t('কীভাবে') }}</dt>
                            <dd class="font-semibold">
                                {{
                                    methods[lastPayment.method]?.label ??
                                    lastPayment.method
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="lastPayment?.reference"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-ink-3">TrxID</dt>
                            <dd class="num font-semibold">
                                {{ lastPayment.reference }}
                            </dd>
                        </div>
                        <div
                            v-if="lastPayment"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-ink-3">{{ t('তারিখ') }}</dt>
                            <dd class="num font-semibold">
                                {{ new Date(lastPayment.at).toLocaleString() }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-3">{{ t('অবস্থা') }}</dt>
                            <dd class="font-semibold text-in">
                                {{ t('পুরো পরিশোধ') }}
                            </dd>
                        </div>
                    </dl>
                    <div class="grid grid-cols-1 gap-2 px-7 pb-6">
                        <KButton variant="primary" block @click="viewReceipt">{{
                            t('রসিদ দেখুন')
                        }}</KButton>
                        <KButton block icon="print" @click="printReceipt">{{
                            t('Download / Print')
                        }}</KButton>
                        <p class="mt-2 text-[12px] text-ink-3">
                            {{
                                t('{name}-এর সাথে থাকার জন্য ধন্যবাদ।', {
                                    name: business.name,
                                })
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </Teleport>
    </component>
</template>

<style scoped>
.confetti {
    position: absolute;
    top: -16px;
    opacity: 0;
    animation-name: confetti-fall;
    animation-timing-function: cubic-bezier(0.25, 0.4, 0.55, 1);
    animation-fill-mode: forwards;
}

@keyframes confetti-fall {
    0% {
        opacity: 1;
        transform: translate3d(0, 0, 0) rotate(0deg);
    }
    85% {
        opacity: 1;
    }
    100% {
        opacity: 0;
        transform: translate3d(var(--drift), 105vh, 0) rotate(var(--spin));
    }
}

.pop {
    animation: pop 0.55s cubic-bezier(0.3, 1.6, 0.5, 1) both;
}

@keyframes pop {
    from {
        transform: scale(0.2);
    }
    to {
        transform: scale(1);
    }
}

/* Paper only for those who like motion. */
@media (prefers-reduced-motion: reduce) {
    .confetti {
        display: none;
    }
    .pop {
        animation: none;
    }
}
</style>
