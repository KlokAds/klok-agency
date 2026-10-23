<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Business } from '@/components/InvoiceDoc.vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import { money, niceDate } from '@/lib/format';
import { locale, switchLocale, t } from '@/lib/i18n';

type Renewal = {
    status: string;
    client: string | null;
    campaign: string;
    ends_on: string;
    budget: number | null;
    rate: number | null;
    days: number | null;
    usd: number | null;
    new_end: string | null;
    invoice_url: string | null;
    invoice_total: number | null;
    since: string | null;
    spent: number;
    results: number;
    clicks: number;
    choices: number[];
    from: string;
};

// The client's renewal page: how the campaign has done, and a choice —
// more days (an invoice to pay) or end the contract here. Printable.
const props = defineProps<{
    token: string;
    business: Business;
    renewal: Renewal;
}>();

const r = computed(() => props.renewal);
const days = ref<number>(r.value.days ?? r.value.choices[1] ?? 7);
const custom = ref('');
const error = ref<string | null>(null);
const busy = ref(false);
const confirmEnd = ref(false);

const chosenDays = computed(() => {
    const n = Number.parseInt(custom.value, 10);

    return custom.value !== '' && n > 0 ? Math.min(n, 90) : days.value;
});
const usd = computed(() => (r.value.budget ?? 0) * chosenDays.value);
const bdt = computed(() =>
    r.value.rate ? Math.round(usd.value * r.value.rate) : null,
);

function addDays(iso: string, n: number): string {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() + n);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const runsTo = computed(() => addDays(r.value.from, chosenDays.value - 1));

const canChoose = computed(
    () =>
        ['open', 'invoiced', 'declined'].includes(r.value.status) &&
        r.value.budget !== null &&
        r.value.rate !== null,
);

function choose(): void {
    error.value = null;
    router.post(
        `/r/${props.token}/choose`,
        { days: chosenDays.value },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onError: (e) => (error.value = e.days ?? Object.values(e)[0]),
        },
    );
}

function decline(): void {
    router.post(
        `/r/${props.token}/decline`,
        { confirm: 1 },
        { preserveScroll: true, onSuccess: () => (confirmEnd.value = false) },
    );
}

function print(): void {
    window.print();
}

const otherLanguage = computed(() => (locale.value === 'en' ? 'বাং' : 'EN')); // i18n
</script>

<template>
    <Head :title="t('Campaign renewal')" />
    <div class="min-h-dvh bg-paper text-ink">
        <div
            class="no-print mx-auto flex max-w-[880px] items-center justify-between gap-3 px-4 pt-5 sm:px-6"
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

        <main
            class="mx-auto flex max-w-[880px] flex-col gap-5 px-4 py-5 sm:px-6"
        >
            <!-- What it is and how it has done -->
            <section class="panel p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-[13px] text-ink-3">
                            {{ t('Campaign renewal') }} · {{ r.client }}
                        </p>
                        <h1
                            class="mt-1 text-[22px] leading-tight font-semibold tracking-tight"
                        >
                            {{ r.campaign }}
                        </h1>
                    </div>
                    <div class="text-right">
                        <p class="text-[12.5px] text-ink-3">
                            {{ t('শেষ হবে') }}
                        </p>
                        <p class="text-[17px] font-semibold">
                            {{ niceDate(r.ends_on) }}
                        </p>
                    </div>
                </div>

                <dl
                    class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-4"
                >
                    <div class="bg-surface px-4 py-3">
                        <dt class="text-[12px] text-ink-3">
                            {{ t('খরচ হয়েছে') }}
                        </dt>
                        <dd class="num mt-0.5 text-[17px] font-semibold">
                            {{ money(r.spent, 'usd') }}
                        </dd>
                    </div>
                    <div class="bg-surface px-4 py-3">
                        <dt class="text-[12px] text-ink-3">{{ t('ফলাফল') }}</dt>
                        <dd class="num mt-0.5 text-[17px] font-semibold">
                            {{ r.results.toLocaleString('en-US') }}
                        </dd>
                    </div>
                    <div class="bg-surface px-4 py-3">
                        <dt class="text-[12px] text-ink-3">Clicks</dt>
                        <dd class="num mt-0.5 text-[17px] font-semibold">
                            {{ r.clicks.toLocaleString('en-US') }}
                        </dd>
                    </div>
                    <div class="bg-surface px-4 py-3">
                        <dt class="text-[12px] text-ink-3">
                            {{ t('দিনের budget') }}
                        </dt>
                        <dd class="num mt-0.5 text-[17px] font-semibold">
                            {{ money(r.budget, 'usd') }}
                        </dd>
                    </div>
                </dl>
                <p v-if="r.since" class="mt-2 text-[12px] text-ink-3">
                    {{
                        t('{date} থেকে এ পর্যন্ত', { date: niceDate(r.since) })
                    }}
                </p>
            </section>

            <!-- Decided already -->
            <section
                v-if="r.status === 'approved'"
                class="panel p-6 text-center"
            >
                <span
                    class="mx-auto grid size-14 place-items-center rounded-full bg-in-soft text-in"
                    ><KIcon name="check" :size="28"
                /></span>
                <p class="mt-3 text-[17px] font-semibold">
                    {{
                        t('Campaign {date} পর্যন্ত চলবে।', {
                            date: niceDate(r.new_end),
                        })
                    }}
                </p>
                <p class="mt-1 text-[13px] text-ink-3">{{ t('ধন্যবাদ!') }}</p>
            </section>

            <section
                v-else-if="r.status === 'paid'"
                class="panel p-6 text-center"
            >
                <span
                    class="mx-auto grid size-14 place-items-center rounded-full bg-in-soft text-in"
                    ><KIcon name="check" :size="28"
                /></span>
                <p class="mt-3 text-[17px] font-semibold">
                    {{ t('টাকা পেয়েছি — ধন্যবাদ!') }}
                </p>
                <p class="mt-1 text-[13px] text-ink-3">
                    {{
                        t(
                            'আরও {n} দিনের জন্য campaign extend করা হচ্ছে, শেষ হলে জানিয়ে দেব।',
                            { n: r.days ?? 0 },
                        )
                    }}
                </p>
            </section>

            <template v-else>
                <section
                    v-if="r.status === 'declined'"
                    class="panel px-5 py-4 text-[13.5px] text-ink-2"
                >
                    {{
                        t(
                            'আপনি contract এখানেই শেষ করেছেন — campaign {date}-এ বন্ধ হবে। মত বদলালে নিচে দিন বেছে pay করুন।',
                            { date: niceDate(r.ends_on) },
                        )
                    }}
                </section>

                <section
                    v-if="r.status === 'invoiced' && r.invoice_url"
                    class="panel flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                >
                    <p class="text-[13.5px]">
                        {{
                            t('{n} দিনের invoice তৈরি — {amount}', {
                                n: r.days ?? 0,
                                amount: money(r.invoice_total, 'bdt'),
                            })
                        }}
                    </p>
                    <a :href="r.invoice_url" class="no-print">
                        <KButton variant="primary" icon="arrow">{{
                            t('Invoice দেখে pay করুন')
                        }}</KButton>
                    </a>
                </section>

                <!-- The choice -->
                <section v-if="canChoose" class="panel no-print p-6 sm:p-8">
                    <h2 class="text-[16px] font-semibold">
                        {{ t('আরও কত দিন চালাবেন?') }}
                    </h2>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            v-for="n in r.choices"
                            :key="n"
                            type="button"
                            class="h-11 min-w-[76px] rounded-xl border px-4 text-[14px] font-semibold transition-colors"
                            :class="
                                custom === '' && days === n
                                    ? 'border-brand bg-brand-soft text-ink'
                                    : 'border-line hover:border-ink-3'
                            "
                            @click="
                                days = n;
                                custom = '';
                            "
                        >
                            {{ t('{n} দিন', { n }) }}
                        </button>
                        <KInput
                            v-model="custom"
                            inputmode="numeric"
                            :placeholder="t('অন্য')"
                            class="w-[110px]"
                        >
                            <template #trail
                                ><span class="text-[12.5px] text-ink-3">{{
                                    t('দিন')
                                }}</span></template
                            >
                        </KInput>
                    </div>

                    <div
                        class="mt-6 grid gap-3 rounded-xl bg-sunken px-5 py-4 text-[13.5px] sm:grid-cols-3"
                    >
                        <div>
                            <p class="text-[12px] text-ink-3">
                                {{ t('Ad budget') }}
                            </p>
                            <p class="num font-semibold">
                                {{ money(r.budget, 'usd') }} ×
                                {{ chosenDays }} = {{ money(usd, 'usd') }}
                            </p>
                        </div>
                        <div>
                            <p class="text-[12px] text-ink-3">
                                {{ t('চলবে') }}
                            </p>
                            <p class="font-semibold">
                                {{ niceDate(r.from) }} –
                                {{ niceDate(runsTo) }}
                            </p>
                        </div>
                        <div class="sm:text-right">
                            <p class="text-[12px] text-ink-3">
                                {{ t('মোট') }}
                            </p>
                            <p class="num text-[20px] font-semibold">
                                {{ money(bdt, 'bdt') }}
                            </p>
                        </div>
                    </div>

                    <p v-if="error" class="mt-3 text-[13px] text-out">
                        {{ error }}
                    </p>

                    <div
                        class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <button
                            v-if="r.status !== 'declined'"
                            type="button"
                            class="text-[13.5px] font-medium text-ink-3 underline-offset-4 hover:text-out hover:underline"
                            @click="confirmEnd = true"
                        >
                            {{ t('না, contract এখানেই শেষ') }}
                        </button>
                        <span v-else />
                        <KButton
                            variant="primary"
                            icon="arrow"
                            :loading="busy"
                            @click="choose"
                        >
                            {{
                                t('{n} দিনের জন্য pay করুন', {
                                    n: chosenDays,
                                })
                            }}
                        </KButton>
                    </div>
                </section>

                <section
                    v-else-if="r.budget === null || r.rate === null"
                    class="panel px-5 py-4 text-[13.5px] text-ink-2"
                >
                    {{
                        t(
                            'এই campaign-এর দাম এখন হিসাব করা যাচ্ছে না — {name}-এর সাথে যোগাযোগ করুন।',
                            { name: business.name },
                        )
                    }}
                    <span v-if="business.phone">· {{ business.phone }}</span>
                </section>
            </template>

            <p class="text-center text-[12px] text-ink-3">
                {{ business.name }}
                <template v-if="business.phone">
                    · {{ business.phone }}</template
                >
                <template v-if="business.email">
                    · {{ business.email }}</template
                >
            </p>
        </main>

        <KModal v-model="confirmEnd" :title="t('Contract শেষ করবেন?')">
            <p class="text-[13.5px] text-ink-2">
                {{
                    t(
                        'Campaign {date}-এ বন্ধ হবে, আর বাড়ানো হবে না। পরে মত বদলালে এই পাতা থেকেই আবার চালু করতে পারবেন।',
                        { date: niceDate(r.ends_on) },
                    )
                }}
            </p>
            <template #footer>
                <KButton @click="confirmEnd = false">{{
                    t('ফিরে যান')
                }}</KButton>
                <KButton variant="danger" @click="decline">{{
                    t('হ্যাঁ, শেষ করুন')
                }}</KButton>
            </template>
        </KModal>
    </div>
</template>
