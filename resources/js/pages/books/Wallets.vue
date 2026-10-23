<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KMoney from '@/components/k/KMoney.vue';
import KPager from '@/components/k/KPager.vue';
import KSelect from '@/components/k/KSelect.vue';
import { confirmAction } from '@/lib/feedback';
import { money, niceDate, today } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Place = {
    id: number;
    key: string;
    name: string;
    type: string;
    detail: string | null;
    opening_balance: number;
    is_active: boolean;
    used: boolean;
};

type Move = {
    id: number;
    date: string;
    from: string;
    to: string;
    amount: number;
    fee: number;
    note: string | null;
};

// Where the BDT is (bank, bKash, cash), what the card holds in USD, and
// the rows that still need a place.
const props = defineProps<{
    wallets: {
        opening_on: string | null;
        places: {
            key: string;
            label: string;
            type: string;
            detail: string | null;
            active: boolean;
            opening: number;
            balance: number;
        }[];
        untagged: number;
        untagged_count: number;
        total: number;
        counted: number;
        unexplained: number;
    };
    list: Place[];
    card: number;
    platform_due: number;
    untagged: Paginated<{
        id: number;
        date: string | null;
        description: string;
        category: string;
        client: string | null;
        amount: number;
    }>;
    moves: Paginated<Move>;
    options: { wallets: Option[]; types: Option[] };
    fees: { mfs_percent: number; bank_fee: number };
}>();

const can = computed(() => usePage().props.auth.can);
const label = (key: string) =>
    props.options.wallets.find((w) => w.value === key)?.label ?? key;

const typeLabel = (type: string) =>
    props.options.types.find((o) => o.value === type)?.label ?? type;

// Places: add, rename, switch off or delete (a place with history is only switched off).
const placesOpen = ref(false);
const editingPlace = ref<Place | null>(null);
const placeForm = useForm({
    name: '' as string | null,
    type: 'bank' as string | null,
    detail: '' as string | null,
    is_active: true,
});

function startPlace(place: Place | null): void {
    editingPlace.value = place;
    placeForm.clearErrors();
    Object.assign(placeForm, {
        name: place?.name ?? '',
        type: place?.type ?? 'bank',
        detail: place?.detail ?? '',
        is_active: place?.is_active ?? true,
    });
}

function savePlace(): void {
    const opts = {
        preserveScroll: true,
        onSuccess: () => startPlace(null),
    };

    if (editingPlace.value) {
        placeForm.put(`/wallets/places/${editingPlace.value.id}`, opts);
    } else {
        placeForm.post('/wallets/places', opts);
    }
}

async function removePlace(place: Place): Promise<void> {
    const ok = await confirmAction({
        title: t('{name} মুছবেন?', { name: place.name }),
        body: place.used
            ? t('এই জায়গায় লেনদেন আছে — মোছা হবে না, শুধু বন্ধ হবে।')
            : t('এই জায়গা তালিকা থেকে চলে যাবে।'),
        confirm: place.used ? t('বন্ধ করুন') : t('মুছুন'),
        danger: true,
    });

    if (ok) {
        router.delete(`/wallets/places/${place.id}`, { preserveScroll: true });
    }
}

// The real balances, counted on a day: places start from there.
const openingOpen = ref(false);
const openingForm = useForm({
    opening_on: today() as string | null,
    balances: {} as Record<string, string | null>,
});

function startOpening(): void {
    openingForm.clearErrors();
    openingForm.opening_on = props.wallets.opening_on ?? today();
    openingForm.balances = Object.fromEntries(
        props.list
            .filter((w) => w.is_active)
            .map((w) => [
                w.key,
                props.wallets.opening_on
                    ? (w.opening_balance / 100).toFixed(2)
                    : '',
            ]),
    );
    openingOpen.value = true;
}

function saveOpening(): void {
    openingForm.put('/wallets/opening', {
        preserveScroll: true,
        onSuccess: () => (openingOpen.value = false),
    });
}

const openingTotal = computed(() =>
    Object.values(openingForm.balances).reduce(
        (sum, v) => sum + (parseFloat(String(v ?? '').replace(/,/g, '')) || 0),
        0,
    ),
);

async function adjust(): Promise<void> {
    const ok = await confirmAction({
        title: t('অমিলটা খাতায় তুলবেন?'),
        body: t(
            'খাতায় যা আছে আর জায়গাগুলোতে আসলে যা আছে, তার পার্থক্য {amount}। এটা {date}-এর একটা adjustment entry হিসেবে লেখা হবে, তাহলে দুটো মিলে যাবে।',
            {
                amount: money(Math.abs(props.wallets.unexplained), 'bdt', {
                    round: false,
                }),
                date: niceDate(props.wallets.opening_on ?? today(), false),
            },
        ),
        confirm: t('খাতায় তুলুন'),
    });

    if (ok) {
        router.post('/wallets/adjust', {}, { preserveScroll: true });
    }
}

// The place button that is being saved, so it cannot be clicked twice.
const busy = ref<string | null>(null);

async function tagRest(wallet: string | number): Promise<void> {
    const ok = await confirmAction({
        title: t('বাকি সব entry {place}-এ রাখবেন?', {
            place: label(String(wallet)),
        }),
        body: t(
            '{n}টা entry-র জায়গা একসাথে বসবে। পরে BDT খাতা থেকে যেকোনোটা বদলানো যায়।',
            {
                n: props.untagged.total,
            },
        ),
        confirm: t('হ্যাঁ, রাখুন'),
    });

    if (ok) {
        busy.value = `rest:${wallet}`;
        router.put(
            '/wallets/tag-rest',
            { wallet },
            { preserveScroll: true, onFinish: () => (busy.value = null) },
        );
    }
}

function tag(id: number, wallet: string | number): void {
    if (busy.value) {
        return;
    }

    busy.value = `${id}:${wallet}`;
    router.put(
        `/wallets/tag/${id}`,
        { wallet },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['wallets', 'untagged'],
            onFinish: () => (busy.value = null),
        },
    );
}

const moveOpen = ref(false);
const form = useForm({
    moved_on: today() as string | null,
    from_wallet: 'bkash' as string | null,
    to_wallet: 'bank' as string | null,
    amount: '' as string | null,
    fee: '' as string | null,
    note: '' as string | null,
});

// The usual fee for a move: cashing out of MFS comes off the amount, a
// bank transfer is charged on top. It can be changed before saving.
const usualFee = computed(() => {
    const amount = parseFloat(form.amount || '0');

    if (form.from_wallet === 'bkash') {
        return { fee: (amount * props.fees.mfs_percent) / 100, inside: true };
    }

    if (form.from_wallet === 'bank') {
        return { fee: props.fees.bank_fee / 100, inside: false };
    }

    return { fee: 0, inside: false };
});
const feeShown = computed(() =>
    form.fee !== '' && form.fee !== null
        ? parseFloat(form.fee || '0')
        : usualFee.value.fee,
);
const arrives = computed(() => {
    const amount = parseFloat(form.amount || '0');

    return usualFee.value.inside ? amount - feeShown.value : amount;
});

function openMove(): void {
    form.reset();
    form.clearErrors();
    moveOpen.value = true;
}

function saveMove(): void {
    form.post('/wallets/moves', {
        preserveScroll: true,
        onSuccess: () => (moveOpen.value = false),
    });
}

async function removeMove(move: Move): Promise<void> {
    const ok = await confirmAction({
        title: t('এই টাকা সরানো মুছবেন?'),
        body: `${label(move.from)} → ${label(move.to)} · ${money(move.amount, 'bdt')}`,
        confirm: t('মুছুন'),
        danger: true,
    });

    if (ok) {
        router.delete(`/wallets/moves/${move.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader
        :eyebrow="t('খাতা')"
        :title="t('টাকা কোথায়')"
        :lead="
            t(
                'কোন জায়গায় কত BDT আছে, card-এ কত USD — আর কোন entry-র জায়গা এখনো বাছা হয়নি।',
            )
        "
    >
        <div class="flex flex-wrap gap-2">
            <KButton
                v-if="can['bdt.edit']"
                icon="pen"
                @click="
                    startPlace(null);
                    placesOpen = true;
                "
                >{{ t('জায়গা') }}</KButton
            >
            <KButton
                v-if="can['bdt.edit']"
                icon="scale"
                @click="startOpening"
                >{{ t('আসল balance বসান') }}</KButton
            >
            <KButton
                v-if="can['bdt.create']"
                variant="ink"
                icon="swap"
                @click="openMove"
                >{{ t('টাকা সরানো') }}</KButton
            >
        </div>
    </PageHeader>

    <section
        class="grid gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-2 xl:grid-cols-4"
    >
        <div
            v-for="p in wallets.places"
            :key="p.key"
            class="bg-surface p-5 sm:p-6"
        >
            <p class="eyebrow">{{ p.label }}</p>
            <p
                class="num mt-3 text-[30px] leading-none font-medium"
                :class="p.balance < 0 ? 'text-out' : ''"
            >
                {{ money(p.balance, 'bdt', { round: false }) }}
            </p>
            <p class="mt-2 truncate text-[12px] text-ink-3">
                {{ typeLabel(p.type)
                }}<template v-if="p.detail"> · {{ p.detail }}</template
                ><template v-if="!p.active"> · {{ t('বন্ধ') }}</template>
            </p>
        </div>
        <div class="bg-rail p-5 text-rail-ink sm:p-6">
            <p class="text-[12.5px] font-medium text-rail-dim">
                {{ t('Card-এ USD') }}
            </p>
            <p class="num mt-3 text-[30px] leading-none font-medium text-white">
                {{ money(card, 'usd') }}
            </p>
            <p class="mt-3 text-[12.5px] text-rail-dim">
                {{ t('Platform আরো কাটবে') }}
                {{ money(platform_due, 'usd') }} ·
                <Link href="/reconcile" class="underline underline-offset-2">{{
                    t('Card মেলান')
                }}</Link>
            </p>
        </div>
    </section>

    <div
        class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-line bg-surface px-5 py-3 text-[13.5px]"
    >
        <span
            >{{ t('খাতায় মোট BDT') }}
            <b class="num">{{
                money(wallets.total, 'bdt', { round: false })
            }}</b></span
        >
        <span v-if="wallets.opening_on" class="text-ink-3">{{
            t('{date} থেকে গোনা', {
                date: niceDate(wallets.opening_on, false),
            })
        }}</span>
        <span
            v-if="wallets.opening_on && wallets.unexplained"
            class="flex flex-wrap items-center gap-2 text-wait"
        >
            <KIcon name="warn" :size="14" />
            {{
                t('খাতার সাথে অমিল {amount}', {
                    amount: money(wallets.unexplained, 'bdt', {
                        sign: true,
                        round: false,
                    }),
                })
            }}
            <button
                v-if="can['bdt.edit']"
                type="button"
                class="font-semibold underline underline-offset-2"
                @click="adjust"
            >
                {{ t('খাতায় তুলুন') }}
            </button>
        </span>
        <span
            v-if="wallets.untagged_count"
            class="flex items-center gap-1.5 text-wait"
        >
            <KIcon name="warn" :size="14" />
            {{
                t('{n}টা entry-র জায়গা বাছা হয়নি ({amount})', {
                    n: wallets.untagged_count,
                    amount: money(wallets.untagged, 'bdt', { round: false }),
                })
            }}
        </span>
        <span v-else class="flex items-center gap-1.5 text-in">
            <KIcon name="check" :size="14" />
            {{ t('সব entry-র জায়গা বাছা আছে') }}
        </span>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <section class="panel overflow-hidden">
            <div class="px-5 pt-5 sm:px-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-[16px] font-bold">
                        {{ t('জায়গা বাছা হয়নি') }}
                    </h2>
                    <div
                        v-if="can['bdt.edit'] && untagged.total"
                        class="flex items-center gap-2"
                    >
                        <span class="text-[12px] text-ink-3">{{
                            t('বাকি সব:')
                        }}</span>
                        <div class="flex gap-1">
                            <button
                                v-for="w in options.wallets"
                                :key="String(w.value)"
                                type="button"
                                class="h-7 rounded-lg border border-line px-2.5 text-[12px] font-semibold hover:bg-sunken disabled:cursor-default"
                                :class="
                                    busy === `rest:${w.value}` && 'opacity-60'
                                "
                                :disabled="!!busy"
                                @click="tagRest(w.value)"
                            >
                                {{ w.label }}
                            </button>
                        </div>
                    </div>
                </div>
                <p class="text-[12.5px] text-ink-3">
                    {{
                        t(
                            'প্রতিটা entry-র টাকা কোথায় এসেছে বা কোথা থেকে গেছে, বেছে দিন — তাহলে জায়গার balance মিলবে।',
                        )
                    }}
                </p>
            </div>
            <p
                v-if="!untagged.data.length"
                class="px-5 py-8 text-center text-[13px] text-ink-3 sm:px-6"
            >
                {{ t('সব entry-র জায়গা বাছা আছে।') }}
            </p>
            <ul v-else class="mt-3">
                <li
                    v-for="e in untagged.data"
                    :key="e.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-line px-5 py-2.5 text-[13px] sm:flex-nowrap sm:px-6"
                >
                    <span class="num w-20 shrink-0 text-[12px] text-ink-3">{{
                        e.date ? niceDate(e.date, false) : t('তারিখ নেই')
                    }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            e.description
                        }}</span>
                        <span class="text-[12px] text-ink-3"
                            >{{ e.category
                            }}<template v-if="e.client">
                                · {{ e.client }}</template
                            ></span
                        >
                    </span>
                    <span
                        class="num w-24 shrink-0 text-right"
                        :class="e.amount < 0 ? 'text-out' : 'text-in'"
                        >{{
                            money(e.amount, 'bdt', { sign: true, round: false })
                        }}</span
                    >
                    <div
                        v-if="can['bdt.edit']"
                        class="grid w-full shrink-0 grid-cols-3 gap-1 sm:flex sm:w-auto"
                    >
                        <button
                            v-for="w in options.wallets"
                            :key="String(w.value)"
                            type="button"
                            class="h-7 rounded-lg border border-line px-2.5 text-[12px] font-semibold hover:bg-sunken disabled:cursor-default"
                            :class="
                                busy === `${e.id}:${w.value}` && 'opacity-60'
                            "
                            :disabled="!!busy"
                            @click="tag(e.id, w.value)"
                        >
                            {{ w.label }}
                        </button>
                    </div>
                </li>
            </ul>
            <KPager :page="untagged" :sizes="[10, 25, 50, 100]" />
        </section>

        <section class="panel overflow-hidden">
            <div class="px-5 pt-5 sm:px-6">
                <h2 class="text-[16px] font-bold">{{ t('টাকা সরানো') }}</h2>
                <p class="text-[12.5px] text-ink-3">
                    {{
                        t(
                            'নিজেদের এক জায়গা থেকে আরেক জায়গায় — মোট BDT বদলায় না।',
                        )
                    }}
                </p>
            </div>
            <p
                v-if="!moves.data.length"
                class="px-5 py-8 text-center text-[13px] text-ink-3 sm:px-6"
            >
                {{ t('এখনো কিছু সরানো হয়নি।') }}
            </p>
            <ul v-else class="mt-3">
                <li
                    v-for="m in moves.data"
                    :key="m.id"
                    class="flex items-center gap-3 border-t border-line px-5 py-2.5 text-[13px] sm:px-6"
                >
                    <span class="num w-20 shrink-0 text-[12px] text-ink-3">{{
                        niceDate(m.date, false)
                    }}</span>
                    <span class="min-w-0 flex-1 truncate font-medium"
                        >{{ label(m.from) }} → {{ label(m.to) }}
                        <span v-if="m.note" class="font-normal text-ink-3">
                            · {{ m.note }}</span
                        ></span
                    >
                    <span class="num shrink-0 text-right"
                        >{{ money(m.amount, 'bdt', { round: false }) }}
                        <span
                            v-if="m.fee"
                            class="block text-[11.5px] text-ink-3"
                            >fee
                            {{ money(m.fee, 'bdt', { round: false }) }}</span
                        ></span
                    >
                    <button
                        v-if="can['bdt.delete']"
                        type="button"
                        class="grid size-7 place-items-center rounded-lg text-ink-3 hover:bg-out-soft hover:text-out"
                        :aria-label="t('মুছুন')"
                        @click="removeMove(m)"
                    >
                        <KIcon name="bin" :size="14" />
                    </button>
                </li>
            </ul>
            <KPager :page="moves" :sizes="[10, 25, 50, 100]" />
        </section>
    </div>

    <KModal v-model="moveOpen" :title="t('টাকা সরানো')">
        <form
            id="move-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="saveMove"
        >
            <KField :label="t('তারিখ')" :error="form.errors.moved_on">
                <KDate v-model="form.moved_on" />
            </KField>
            <KField :label="t('কত টাকা')" :error="form.errors.amount">
                <KMoney
                    v-model="form.amount"
                    currency="bdt"
                    :invalid="!!form.errors.amount"
                />
            </KField>
            <KField :label="t('কোথা থেকে')" :error="form.errors.from_wallet">
                <KSelect
                    v-model="form.from_wallet"
                    :options="options.wallets"
                    :searchable="false"
                />
            </KField>
            <KField :label="t('কোথায়')" :error="form.errors.to_wallet">
                <KSelect
                    v-model="form.to_wallet"
                    :options="options.wallets"
                    :searchable="false"
                />
            </KField>
            <KField label="Note" optional class="sm:col-span-2">
                <KInput
                    v-model="form.note"
                    :placeholder="t('যেমন bKash থেকে cash out')"
                />
            </KField>
            <KField
                label="Fee"
                optional
                :error="form.errors.fee"
                :hint="
                    form.from_wallet === 'bkash'
                        ? t('MFS থেকে বের করার fee {pct}% — টাকা থেকে কাটবে।', {
                              pct: fees.mfs_percent,
                          })
                        : form.from_wallet === 'bank'
                          ? t('Bank transfer fee — উপরে কাটবে।')
                          : t('Cash সরাতে fee নেই।')
                "
            >
                <KMoney
                    v-model="form.fee"
                    currency="bdt"
                    :placeholder="usualFee.fee.toFixed(2)"
                />
            </KField>
            <div
                v-if="parseFloat(form.amount || '0') > 0"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-sunken px-4 py-3 text-[13px] sm:col-span-2"
            >
                <span
                    >{{ label(form.from_wallet ?? '') }} {{ t('থেকে যাবে') }}
                    <b class="num">{{
                        money(
                            Math.round(
                                (usualFee.inside
                                    ? parseFloat(form.amount || '0')
                                    : parseFloat(form.amount || '0') +
                                      feeShown) * 100,
                            ),
                            'bdt',
                            { round: false },
                        )
                    }}</b></span
                >
                <span
                    >{{ label(form.to_wallet ?? '') }}-{{ t('এ পৌঁছাবে') }}
                    <b class="num">{{
                        money(Math.round(arrives * 100), 'bdt', {
                            round: false,
                        })
                    }}</b></span
                >
                <span v-if="feeShown > 0" class="text-ink-3"
                    >Fee
                    <b class="num">{{
                        money(Math.round(feeShown * 100), 'bdt', {
                            round: false,
                        })
                    }}</b>
                    — {{ t('Bank charge খরচ হিসেবে যাবে') }}</span
                >
            </div>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="moveOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="move-form"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <KModal v-model="placesOpen" :title="t('জায়গা — bank, MFS, cash, hold')">
        <ul class="rounded-xl border border-line">
            <li
                v-for="w in list"
                :key="w.id"
                class="flex items-center gap-3 border-b border-line px-3 py-2.5 text-[13.5px] last:border-0"
            >
                <span class="min-w-0 flex-1">
                    <span
                        class="block truncate font-medium"
                        :class="w.is_active ? '' : 'text-ink-3 line-through'"
                        >{{ w.name }}</span
                    >
                    <span class="text-[12px] text-ink-3"
                        >{{ typeLabel(w.type)
                        }}<template v-if="w.detail">
                            · {{ w.detail }}</template
                        ></span
                    >
                </span>
                <KButton size="sm" variant="quiet" @click="startPlace(w)">{{
                    t('বদলান')
                }}</KButton>
                <button
                    type="button"
                    class="grid size-7 place-items-center rounded-lg text-ink-3 hover:bg-out-soft hover:text-out"
                    :aria-label="t('মুছুন')"
                    @click="removePlace(w)"
                >
                    <KIcon name="bin" :size="14" />
                </button>
            </li>
        </ul>

        <form
            class="mt-5 grid grid-cols-1 gap-4 rounded-xl bg-sunken p-4 sm:grid-cols-2"
            @submit.prevent="savePlace"
        >
            <p class="text-[13px] font-semibold sm:col-span-2">
                {{
                    editingPlace
                        ? t('{name} বদলান', { name: editingPlace.name })
                        : t('নতুন জায়গা')
                }}
            </p>
            <KField :label="t('নাম')" :error="placeForm.errors.name">
                <KInput
                    v-model="placeForm.name"
                    :placeholder="t('যেমন City Bank, bKash 2')"
                />
            </KField>
            <KField :label="t('ধরন')" :error="placeForm.errors.type">
                <KSelect
                    v-model="placeForm.type"
                    :options="options.types"
                    :searchable="false"
                />
            </KField>
            <KField
                :label="t('Account / নম্বর')"
                optional
                class="sm:col-span-2"
            >
                <KInput
                    v-model="placeForm.detail"
                    :placeholder="t('যেমন A/C 1234 বা 017…')"
                />
            </KField>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <KButton
                    v-if="editingPlace"
                    variant="quiet"
                    @click="startPlace(null)"
                    >{{ t('বাতিল') }}</KButton
                >
                <KButton
                    type="submit"
                    variant="primary"
                    :loading="placeForm.processing"
                    >{{ editingPlace ? t('সংরক্ষণ') : t('যোগ করুন') }}</KButton
                >
            </div>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="placesOpen = false">{{
                t('বন্ধ করুন')
            }}</KButton>
        </template>
    </KModal>

    <KModal v-model="openingOpen" :title="t('আসল balance বসান')">
        <p class="text-[13px] text-ink-2">
            {{
                t(
                    'আজ প্রতিটা জায়গায় আসলে কত আছে লিখুন। এই দিন থেকে প্রতিটা জায়গা এখান থেকে গোনা শুরু করবে — আগের entry-র জায়গা আর বাছতে হবে না।',
                )
            }}
        </p>
        <form
            id="opening-form"
            class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="saveOpening"
        >
            <KField
                :label="t('কোন দিনের শেষে গোনা')"
                :error="openingForm.errors.opening_on"
                class="sm:col-span-2"
            >
                <KDate v-model="openingForm.opening_on" :max="today()" />
            </KField>
            <KField
                v-for="w in list.filter((x) => x.is_active)"
                :key="w.key"
                :label="w.name"
            >
                <KMoney v-model="openingForm.balances[w.key]" currency="bdt" />
            </KField>
            <div
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-sunken px-4 py-3 text-[13px] sm:col-span-2"
            >
                <span
                    >{{ t('মোট') }}
                    <b class="num">{{
                        money(Math.round(openingTotal * 100), 'bdt', {
                            round: false,
                        })
                    }}</b></span
                >
                <span class="text-ink-3"
                    >{{ t('খাতায় এখন') }}
                    <b class="num">{{
                        money(wallets.total, 'bdt', { round: false })
                    }}</b></span
                >
            </div>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="openingOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="opening-form"
                variant="primary"
                :loading="openingForm.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>
</template>
