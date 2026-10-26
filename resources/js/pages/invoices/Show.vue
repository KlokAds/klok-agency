<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InvoiceDoc from '@/components/InvoiceDoc.vue';
import type { Business, InvoiceDocument } from '@/components/InvoiceDoc.vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KMoney from '@/components/k/KMoney.vue';
import KSelect from '@/components/k/KSelect.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTag from '@/components/k/KTag.vue';
import { confirmAction, toast } from '@/lib/feedback';
import { ago, money, niceDate, toInput, today } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type Payment = {
    id: number;
    method: string;
    method_label: string;
    amount: number;
    status: string;
    reference: string | null;
    sender: string | null;
    session: boolean;
    expires_at: string | null;
    paid_on: string | null;
    wallet: string | null;
    usd_credit: number | null;
    bdt_entry_id: number | null;
    fee_entry_id: number | null;
    note: string | null;
    verified_by: string | null;
    auto: boolean;
    created_at: string;
    verified_at: string | null;
};

const props = defineProps<{
    business: Business;
    invoice: InvoiceDocument & {
        id: number;
        uid: string;
        client_id: number;
        url: string;
        editable: boolean;
        payable: boolean;
        qr_fee?: number;
        allow_partial: boolean;
        created_at: string | null;
        sent_at: string | null;
        voided_at: string | null;
        paid_at: string | null;
        author: string | null;
        client_phone: string | null;
        client_email: string | null;
    };
    payments: Payment[];
    earlier_payments?: {
        id: number;
        date: string;
        amount: number;
        usd: number | null;
        description: string;
        wallet: string | null;
    }[];
    share: { text: string; whatsapp: string; sms: string; mail: boolean };
    options: { methods: (Option & { wallet: string })[]; wallets: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);
const bdt = (poisha: number) => money(poisha, 'bdt', { round: false });
const isVoid = computed(() => props.invoice.status === 'void');

// Sharing: any of these means the invoice is now with the client.
const shareOpen = ref(false);

function markSent(): void {
    if (!props.invoice.sent_at) {
        router.post(
            `/invoices/${props.invoice.uid}/send`,
            {},
            { preserveScroll: true },
        );
    }
}

function copyLink(): void {
    void navigator.clipboard?.writeText(props.invoice.url);
    toast(t('Link copy হয়েছে।'), 'info');
    markSent();
}

function email(): void {
    router.post(
        `/invoices/${props.invoice.uid}/email`,
        {},
        { preserveScroll: true, onSuccess: () => (shareOpen.value = false) },
    );
}

// Money taken by hand, or a client's claim checked against the statement.
const payOpen = ref(false);
const pay = useForm({
    payment_id: null as number | null,
    method: 'bkash' as string | number | null,
    wallet: 'bkash' as string | number | null,
    paid_on: today() as string | null,
    amount: '',
    reference: '',
    existing_entry_id: null as number | null,
});

watch(
    () => pay.method,
    (method) => {
        pay.wallet =
            props.options.methods.find((m) => m.value === method)?.wallet ??
            'bank';
    },
);

function openPay(claim: Payment | null = null): void {
    pay.clearErrors();
    pay.payment_id = claim?.id ?? null;
    pay.method = claim?.method ?? 'bkash';
    pay.wallet =
        props.options.methods.find((m) => m.value === pay.method)?.wallet ??
        'bank';
    pay.paid_on = today();
    pay.amount = toInput(claim?.amount ?? props.invoice.due);
    pay.reference = claim?.reference ?? '';
    pay.existing_entry_id = null;
    payOpen.value = true;
}

function savePay(): void {
    pay.post(`/invoices/${props.invoice.uid}/pay`, {
        preserveScroll: true,
        onSuccess: () => (payOpen.value = false),
    });
}

async function reject(payment: Payment): Promise<void> {
    if (
        await confirmAction({
            title: t('এই দাবি বাতিল করবেন?'),
            body: t('Client আবার TrxID দিতে পারবেন।'),
            danger: true,
        })
    ) {
        router.post(
            `/invoices/${props.invoice.uid}/payments/${payment.id}/reject`,
            {},
            { preserveScroll: true },
        );
    }
}

async function voidInvoice(): Promise<void> {
    if (
        await confirmAction({
            title: t('Invoice বাতিল করবেন?'),
            body: t(
                'Client-এর link-এ বাতিল দেখাবে, আর পেমেন্ট নেওয়া যাবে না।',
            ),
            danger: true,
        })
    ) {
        router.post(
            `/invoices/${props.invoice.uid}/void`,
            {},
            { preserveScroll: true },
        );
    }
}

const claims = computed(() =>
    props.payments.filter((p) => p.status === 'pending' && !p.session),
);
const openSession = computed(() =>
    props.payments.find((p) => p.status === 'pending' && p.session),
);

type Step = { at: string; label: string; detail?: string; tone: string };

const timeline = computed<Step[]>(() => {
    const steps: Step[] = [];

    if (props.invoice.created_at) {
        steps.push({
            at: props.invoice.created_at,
            label: t('তৈরি হয়েছে'),
            detail: props.invoice.author ?? undefined,
            tone: 'bg-line-2',
        });
    }

    if (props.invoice.sent_at) {
        steps.push({
            at: props.invoice.sent_at,
            label: t('Client-কে পাঠানো হয়েছে'),
            tone: 'bg-brand',
        });
    }

    for (const p of props.payments) {
        if (p.status === 'verified' && p.verified_at) {
            steps.push({
                at: p.verified_at,
                label: t('{amount} পাওয়া গেছে', { amount: bdt(p.amount) }),
                detail: `${p.method_label}${p.reference ? ' · ' + p.reference : ''} · ${p.auto ? t('SMS দেখে নিজে থেকে') : (p.verified_by ?? '')}`,
                tone: 'bg-in',
            });
        } else if (p.status === 'pending' && !p.session) {
            steps.push({
                at: p.created_at,
                label: t('Client পেমেন্টের তথ্য দিয়েছেন'),
                detail: `${p.method_label} · ${p.reference ?? ''}`,
                tone: 'bg-wait',
            });
        }
    }

    if (props.invoice.voided_at) {
        steps.push({
            at: props.invoice.voided_at,
            label: t('বাতিল'),
            tone: 'bg-out',
        });
    }

    return steps.sort((a, b) => a.at.localeCompare(b.at));
});

const PAY_STATUS: Record<
    string,
    { label: string; tone: 'in' | 'wait' | 'out' | 'neutral' }
> = {
    verified: { label: 'পাওয়া গেছে', tone: 'in' }, // i18n
    pending: { label: 'যাচাই বাকি', tone: 'wait' }, // i18n
    rejected: { label: 'বাতিল', tone: 'out' }, // i18n
    expired: { label: 'সময় শেষ', tone: 'neutral' }, // i18n
};
</script>

<template>
    <PageHeader
        eyebrow="Invoice"
        :title="invoice.number"
        :lead="invoice.client ?? undefined"
    >
        <!-- Up to four: a 2×2 grid on a phone, one row when there is room. -->
        <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
            <KButton href="/invoices" icon="back">{{
                t('সব invoice')
            }}</KButton>
            <KButton
                v-if="invoice.editable && can['clients.edit']"
                variant="soft"
                icon="pen"
                :href="`/invoices/${invoice.uid}/edit`"
                >{{ t('বদলান') }}</KButton
            >
            <KButton
                v-if="!isVoid && can['clients.edit']"
                icon="chat"
                :variant="invoice.sent_at ? 'plain' : 'primary'"
                @click="shareOpen = true"
                >{{ t('পাঠান') }}</KButton
            >
            <KButton
                v-if="!isVoid && invoice.due > 0 && can['bdt.create']"
                icon="check"
                :variant="invoice.sent_at ? 'primary' : 'plain'"
                @click="openPay()"
                >{{ t('পেমেন্ট লিখুন') }}</KButton
            >
        </div>
    </PageHeader>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0">
            <InvoiceDoc :invoice="invoice" :business="business" />
        </div>

        <aside class="flex flex-col gap-4">
            <section v-if="claims.length" class="panel border-wait/40 p-5">
                <h2
                    class="flex items-center gap-2 text-[16px] font-bold text-wait"
                >
                    <KIcon name="warn" :size="17" />{{ t('যাচাই করুন') }}
                </h2>
                <ul class="mt-3 grid grid-cols-1 gap-3">
                    <li
                        v-for="c in claims"
                        :key="c.id"
                        class="rounded-xl border border-line p-3 text-[14px]"
                    >
                        <p class="flex justify-between gap-2">
                            <span class="font-semibold">{{
                                c.method_label
                            }}</span>
                            <span class="num">{{ bdt(c.amount) }}</span>
                        </p>
                        <p class="num mt-0.5 text-[13px] break-all text-ink-3">
                            {{ c.reference }} · {{ c.sender }} ·
                            {{ ago(c.created_at) }}
                        </p>
                        <p v-if="c.note" class="mt-1 text-[13px] text-wait">
                            {{ c.note }}
                        </p>
                        <div
                            v-if="can['bdt.create']"
                            class="mt-2 flex justify-end gap-2"
                        >
                            <KButton size="sm" @click="reject(c)">{{
                                t('বাতিল')
                            }}</KButton>
                            <KButton
                                size="sm"
                                variant="primary"
                                @click="openPay(c)"
                                >{{ t('টাকা এসেছে') }}</KButton
                            >
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="openSession" class="panel p-5 text-[14px]">
                <p class="flex items-center gap-2 font-semibold">
                    <span class="size-2 animate-pulse rounded-full bg-brand" />
                    {{
                        t('Client এখন {wallet}-এ {amount} পাঠাচ্ছেন', {
                            wallet: openSession.method_label,
                            amount: bdt(openSession.amount),
                        })
                    }}
                </p>
            </section>

            <section class="panel p-5">
                <h2 class="text-[16px] font-bold">{{ t('কী কী হয়েছে') }}</h2>
                <ol class="mt-3 space-y-3">
                    <li v-for="(s, i) in timeline" :key="i" class="flex gap-3">
                        <span
                            class="mt-1.5 size-2.5 shrink-0 rounded-full"
                            :class="s.tone"
                        />
                        <div class="min-w-0 text-[14px]">
                            <p class="font-medium">{{ s.label }}</p>
                            <p class="text-[13px] break-words text-ink-3">
                                {{ ago(s.at)
                                }}<template v-if="s.detail">
                                    · {{ s.detail }}</template
                                >
                            </p>
                        </div>
                    </li>
                </ol>
            </section>

            <section v-if="payments.length" class="panel p-5">
                <h2 class="text-[16px] font-bold">{{ t('পেমেন্ট') }}</h2>
                <ul class="mt-2 divide-y divide-dashed divide-line text-[14px]">
                    <li v-for="p in payments" :key="p.id" class="py-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{
                                p.method_label
                            }}</span>
                            <span class="num">{{ bdt(p.amount) }}</span>
                        </div>
                        <div
                            class="mt-0.5 flex items-center justify-between gap-2 text-[13px] text-ink-3"
                        >
                            <span class="num truncate"
                                >{{
                                    p.paid_on
                                        ? niceDate(p.paid_on)
                                        : ago(p.created_at)
                                }}<template v-if="p.reference">
                                    · {{ p.reference }}</template
                                ></span
                            >
                            <KTag
                                :tone="PAY_STATUS[p.status]?.tone ?? 'neutral'"
                                >{{
                                    t(PAY_STATUS[p.status]?.label ?? p.status)
                                }}</KTag
                            >
                        </div>
                        <p
                            v-if="p.usd_credit"
                            class="mt-0.5 text-[13px] text-ink-3"
                        >
                            {{
                                t('Ad credit {usd}', {
                                    usd: money(p.usd_credit, 'usd'),
                                })
                            }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                v-if="invoice.payable && can['clients.edit']"
                class="panel p-5 text-[14px]"
            >
                <KSwitch
                    :model-value="invoice.allow_partial"
                    :label="t('Client অল্প অল্প করে দিতে পারবেন')"
                    :hint="
                        invoice.allow_partial
                            ? t(
                                  'খোলা: client নিজে ঠিক করবেন এখন কত দেবেন (কমপক্ষে ৳10)। QR-এ সেই অঙ্কই বসবে।',
                              )
                            : t(
                                  'লক: পুরো বাকি একবারে দিতে হবে। QR-এ পুরো অঙ্ক বসানো, বদলানো যায় না।',
                              )
                    "
                    @update:model-value="
                        (on: boolean) =>
                            router.post(
                                `/invoices/${invoice.uid}/partial`,
                                { allow_partial: on },
                                { preserveScroll: true },
                            )
                    "
                />
                <p
                    v-if="invoice.qr_fee"
                    class="mt-4 rounded-lg bg-wait-soft px-3 py-2 text-[13.5px] text-ink-2"
                >
                    {{
                        t(
                            'Bangla QR-এ এলে bank {fee} fee কাটবে — আমাদের জমা হবে প্রায় {landed}। Client-এর QR-এ পুরো {due}-ই থাকে।',
                            {
                                fee: bdt(invoice.qr_fee),
                                landed: bdt(invoice.due - invoice.qr_fee),
                                due: bdt(invoice.due),
                            },
                        )
                    }}
                </p>
            </section>

            <section class="panel p-5 text-[14px]">
                <p class="eyebrow">{{ t('Client-এর link') }}</p>
                <p
                    class="num mt-2 truncate rounded-lg bg-sunken px-3 py-2 text-[13px]"
                >
                    {{ invoice.url }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <KButton size="sm" :href="invoice.url" external>{{
                        t('খুলে দেখুন')
                    }}</KButton>
                    <KButton
                        v-if="
                            !isVoid && invoice.paid === 0 && can['clients.edit']
                        "
                        size="sm"
                        variant="danger"
                        @click="voidInvoice"
                        >{{ t('বাতিল করুন') }}</KButton
                    >
                </div>
            </section>
        </aside>
    </div>

    <KModal
        v-model="shareOpen"
        :title="t('Invoice পাঠান')"
        :subtitle="invoice.client ?? undefined"
    >
        <p
            class="rounded-xl bg-sunken p-3 text-[14px] wrap-anywhere whitespace-pre-line text-ink-2"
        >
            {{ share.text }}
        </p>
        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <a
                :href="share.whatsapp"
                target="_blank"
                rel="noopener"
                class="flex h-11 items-center justify-center gap-2 rounded-[10px] bg-[#25d366] px-4 text-[14.5px] font-semibold text-white"
                @click="markSent"
            >
                <KIcon name="chat" :size="17" />WhatsApp
            </a>
            <a
                :href="share.sms"
                class="flex h-11 items-center justify-center gap-2 rounded-[10px] border border-line-2 px-4 text-[14.5px] font-semibold"
                @click="markSent"
            >
                <KIcon name="chat" :size="17" />SMS
            </a>
            <KButton icon="list" @click="copyLink">{{
                t('Link copy করুন')
            }}</KButton>
            <KButton v-if="share.mail" icon="arrow" @click="email">{{
                t('Email পাঠান')
            }}</KButton>
        </div>
        <p v-if="!invoice.client_phone" class="mt-3 text-[13px] text-ink-3">
            {{
                t(
                    'Client-এর ফোন নম্বর নেই — WhatsApp-এ কাকে পাঠাবেন বেছে নিতে হবে।',
                )
            }}
        </p>
    </KModal>

    <KModal
        v-model="payOpen"
        :title="pay.payment_id ? t('দাবি মিলিয়ে নিন') : t('পেমেন্ট লিখুন')"
        :subtitle="invoice.number"
    >
        <!-- Already written by hand before this invoice: use it, so the money is not counted twice. -->
        <div
            v-if="!pay.payment_id && earlier_payments?.length"
            class="mb-4 rounded-xl border border-wait/40 bg-wait/10 p-3"
        >
            <p class="text-[14px] font-semibold">
                {{
                    t(
                        'এই client-এর আগে খাতায় লেখা পেমেন্ট — কোনো invoice-এ নেই',
                    )
                }}
            </p>
            <p class="mt-0.5 text-[13px] text-ink-3">
                {{
                    t(
                        'এই invoice-এর টাকা আগেই খাতায় তুলে থাকলে সেটা বাছুন — দ্বিতীয়বার যোগ হবে না।',
                    )
                }}
            </p>
            <!-- Our own radio dot: the browser's is reset away by the base styles. -->
            <div class="mt-2 flex flex-col gap-1">
                <label
                    class="flex min-h-9 cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-[14px] hover:bg-surface"
                >
                    <input
                        v-model="pay.existing_entry_id"
                        type="radio"
                        :value="null"
                        class="sr-only"
                    />
                    <span
                        class="grid size-[18px] shrink-0 place-items-center rounded-full border-[1.5px]"
                        :class="
                            pay.existing_entry_id === null
                                ? 'border-brand'
                                : 'border-line-2'
                        "
                        ><span
                            v-if="pay.existing_entry_id === null"
                            class="size-2.5 rounded-full bg-brand"
                    /></span>
                    <span class="font-medium">{{
                        t('না, এটা নতুন পেমেন্ট')
                    }}</span>
                </label>
                <label
                    v-for="e in earlier_payments"
                    :key="e.id"
                    class="flex min-h-9 cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-[14px] hover:bg-surface"
                >
                    <input
                        v-model="pay.existing_entry_id"
                        type="radio"
                        :value="e.id"
                        class="sr-only"
                    />
                    <span
                        class="grid size-[18px] shrink-0 place-items-center rounded-full border-[1.5px]"
                        :class="
                            pay.existing_entry_id === e.id
                                ? 'border-brand'
                                : 'border-line-2'
                        "
                        ><span
                            v-if="pay.existing_entry_id === e.id"
                            class="size-2.5 rounded-full bg-brand"
                    /></span>
                    <span class="min-w-0 flex-1 truncate"
                        >{{ niceDate(e.date) }} ·
                        <b class="num">{{ money(e.amount, 'bdt') }}</b>
                        <template v-if="e.usd">
                            · {{ money(e.usd, 'usd') }} credit</template
                        >
                        <span class="text-ink-3">
                            · {{ e.description }}</span
                        ></span
                    >
                </label>
            </div>
            <p
                v-if="pay.errors.existing_entry_id"
                class="mt-1 text-[13px] text-out"
            >
                {{ pay.errors.existing_entry_id }}
            </p>
        </div>
        <div
            v-if="!pay.existing_entry_id"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
        >
            <KField :label="t('কীভাবে')" :error="pay.errors.method">
                <KSelect v-model="pay.method" :options="options.methods" />
            </KField>
            <KField :label="t('কোথায় এলো')" :error="pay.errors.wallet">
                <KSelect v-model="pay.wallet" :options="options.wallets" />
            </KField>
            <KField :label="t('তারিখ')" :error="pay.errors.paid_on">
                <KDate v-model="pay.paid_on" :max="today()" />
            </KField>
            <KField :label="t('পরিমাণ')" :error="pay.errors.amount">
                <KMoney
                    v-model="pay.amount"
                    currency="bdt"
                    :invalid="!!pay.errors.amount"
                />
            </KField>
            <KField
                label="TrxID / reference"
                optional
                class="sm:col-span-2"
                :error="pay.errors.reference"
                :hint="
                    t(
                        'TrxID দিলে server-এর SMS-এর সাথে মিলিয়ে দেখা হয় — একই TrxID দুবার নেওয়া যায় না।',
                    )
                "
            >
                <KInput v-model="pay.reference" />
            </KField>
        </div>
        <p class="mt-3 text-[13px] text-ink-3">
            {{
                t(
                    'BDT খাতায় client পেমেন্ট হিসেবে যাবে, invoice-এর ad USD-এর ভাগ client-এর credit হবে। Bangla QR হলে QR fee ব্যাংক থেকে খরচ হিসেবে লেখা হবে।',
                )
            }}
        </p>
        <template #footer>
            <KButton variant="quiet" @click="payOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                :loading="pay.processing"
                @click="savePay"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>
</template>
