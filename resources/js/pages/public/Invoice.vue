<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import InvoiceDoc from '@/components/InvoiceDoc.vue';
import type { Business, InvoiceDocument } from '@/components/InvoiceDoc.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import { money, niceDate } from '@/lib/format';
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
    session: {
        method: string;
        amount: number;
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
const method = computed(() =>
    chosen.value ? (props.methods[chosen.value] ?? null) : null,
);
const busy = ref(false);
const error = ref<string | null>(null);
const justPaid = ref(false);

watch(
    () => state.value.status,
    (now, before) => {
        if (now === 'paid' && before !== 'paid') {
            justPaid.value = true;
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
const payerError = ref<string | null>(null);
// An invoice that allows part payments: how much to pay now (taka; empty = all that is due).
const payNow = ref('');
// The taka sign, from its code (the file keeps Bangla text only inside t()).
const takaSign = String.fromCharCode(0x09f3);
const payNowError = ref<string | null>(null);

async function start(key: string): Promise<void> {
    acted++;
    chosen.value = key;
    error.value = null;

    if (key === 'bank') {
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
    <div class="min-h-dvh bg-paper text-ink">
        <div
            class="no-print mx-auto flex max-w-[1080px] items-center justify-between gap-3 px-4 pt-5 sm:px-6"
        >
            <p class="truncate text-[13px] font-semibold text-ink-2">
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
            <InvoiceDoc :invoice="doc" :business="business" />

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
                        <KButton size="sm" variant="quiet" @click="cancel">{{
                            t('অন্য উপায়')
                        }}</KButton>
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
                                :label="t('যে নম্বর থেকে পাঠিয়েছেন')"
                                :error="claimErrors.sender?.[0]"
                            >
                                <KInput
                                    v-model="claim.sender"
                                    type="tel"
                                    inputmode="tel"
                                    :invalid="!!claimErrors.sender"
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

                    <!-- bKash, Nagad, Rocket: the number the money comes from (only that SMS can match). -->
                    <div v-if="wallets.some((k) => k !== 'qr')" class="mt-4">
                        <KField
                            :label="t('কোন নম্বর থেকে পাঠাবেন?')"
                            :hint="
                                t(
                                    'যে নম্বর থেকে টাকা পাঠাবেন, ঠিক সেটাই দিন — অন্য নম্বর থেকে পাঠালে নিজে থেকে মিলবে না। শুধু bKash, Nagad, Rocket-এ লাগে।',
                                )
                            "
                            :error="payerError ?? undefined"
                        >
                            <KInput
                                v-model="payer"
                                type="tel"
                                inputmode="tel"
                                placeholder="01XXXXXXXXX"
                            />
                        </KField>
                    </div>

                    <div v-if="keys.length" class="mt-4 grid grid-cols-2 gap-2">
                        <button
                            v-for="key in [
                                ...wallets,
                                ...(methods.bank ? ['bank'] : []),
                            ]"
                            :key="key"
                            type="button"
                            class="flex min-h-12 items-center gap-2 rounded-xl border px-3 py-2.5 text-left text-[13.5px] font-semibold transition-colors"
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
                                :size="17"
                                class="text-brand"
                            />{{ methods[key].label }}
                        </button>
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
                                    { amount: bdt(state.due) },
                                )
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
                                    :placeholder="String(state.due / 100)"
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
    </div>
</template>
