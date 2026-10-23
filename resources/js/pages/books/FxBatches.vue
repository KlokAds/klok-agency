<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
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
import KTextarea from '@/components/k/KTextarea.vue';
import { confirmAction } from '@/lib/feedback';
import { t } from '@/lib/i18n';
import { money, niceDate, toInput } from '@/lib/format';
import type { Paginated } from '@/types';

type Batch = {
    id: number;
    number: number;
    date: string | null;
    description: string;
    usd: number;
    bdt: number;
    rate: number;
    used: number;
    left: number;
    notes: string | null;
};

defineProps<{
    batches: Paginated<Batch>;
    pool: Batch[];
    totals: {
        usd: number;
        bdt: number;
        avg_rate: number;
        consumed: number;
        left: number;
        overdrawn: boolean;
    };
}>();

const can = computed(() => usePage().props.auth.can);
const editing = ref<Batch | null>(null);
const form = useForm({
    entry_date: null as string | null,
    description: '',
    usd: '' as string | null,
    bdt: '' as string | null,
    notes: '' as string | null,
});

function edit(batch: Batch): void {
    editing.value = batch;
    form.clearErrors();
    Object.assign(form, {
        entry_date: batch.date,
        description: batch.description,
        usd: toInput(batch.usd),
        bdt: toInput(batch.bdt),
        notes: batch.notes ?? '',
    });
}

const open = computed({
    get: () => editing.value !== null,
    set: (v) => !v && (editing.value = null),
});

const previewRate = computed(() => {
    const usd = parseFloat(form.usd || '0');
    const bdt = parseFloat(form.bdt || '0');

    return usd > 0 ? (bdt / usd).toFixed(2) : '—';
});

function save(): void {
    form.put(`/fx/${editing.value!.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

async function remove(batch: Batch): Promise<void> {
    const ok = await confirmAction({
        title: t('Batch {n} মুছবেন?', { n: batch.number }),
        body: t('USD খাতার deposit আর BDT খাতার কেনার row-ও Trash-এ যাবে।'),
        confirm: t('Trash-এ পাঠান'),
        danger: true,
    });

    if (ok) {
        router.delete(`/fx/${batch.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <SectionTabs section="dollars" />
    <PageHeader
        :eyebrow="t('খাতা')"
        title="FX Batch"
        :lead="
            t(
                'প্রতিবার USD কেনা একটা batch। খরচের সময় পুরনো batch আগে শেষ হয় (FIFO), তাই প্রতিটা খরচের আসল BDT দাম জানা যায়।',
            )
        "
    />

    <div
        class="mb-6 grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-4"
    >
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('মোট কেনা') }}</p>
            <p class="num mt-2 text-[22px]">{{ money(totals.usd, 'usd') }}</p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('মোট দাম') }}</p>
            <p class="num mt-2 text-[22px]">{{ money(totals.bdt, 'bdt') }}</p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('গড় rate') }}</p>
            <p class="num mt-2 text-[22px]">&#2547;{{ totals.avg_rate }}</p>
        </div>
        <div class="bg-surface p-5">
            <p class="eyebrow">{{ t('এখনো বাকি') }}</p>
            <p
                class="num mt-2 text-[22px]"
                :class="totals.overdrawn && 'text-out'"
            >
                {{ money(totals.left, 'usd') }}
            </p>
        </div>
    </div>

    <p
        v-if="totals.overdrawn"
        class="mb-4 flex items-center gap-2 rounded-xl bg-out-soft px-4 py-3 text-[13.5px] font-medium text-out"
    >
        <KIcon name="warn" :size="16" />
        {{
            t(
                'যত USD কেনা হয়েছে তার চেয়ে বেশি খরচ লেখা আছে — বাড়তি অংশ শেষ batch-এর rate-এ ধরা হচ্ছে। একটা USD কেনা বাদ পড়েছে কিনা দেখুন।',
            )
        }}
    </p>

    <!-- The pool, drawn as one bar -->
    <section class="panel mb-6 p-5 sm:p-6">
        <div class="mb-3 flex items-baseline justify-between">
            <h2 class="text-[15px] font-bold">FIFO pool</h2>
            <span class="text-[12.5px] text-ink-3"
                >{{ t('খরচ হয়েছে') }}
                <b class="num text-ink">{{
                    money(totals.consumed, 'usd')
                }}</b></span
            >
        </div>
        <div class="flex h-10 gap-[2px] overflow-hidden rounded-lg">
            <div
                v-for="b in pool"
                :key="b.id"
                class="relative flex bg-sunken"
                :style="{ flexGrow: b.usd }"
                :title="
                    t('Batch {n}: {used} খরচ, {left} বাকি', {
                        n: b.number,
                        used: money(b.used, 'usd'),
                        left: money(b.left, 'usd'),
                    })
                "
            >
                <div
                    class="h-full bg-ink-2/70"
                    :style="{ width: `${(b.used / b.usd) * 100}%` }"
                />
                <span
                    class="num absolute inset-0 grid place-items-center text-[11.5px] font-semibold"
                    :class="b.used / b.usd > 0.5 ? 'text-paper' : 'text-ink'"
                    >#{{ b.number }} · &#2547;{{ b.rate }}</span
                >
            </div>
        </div>
        <div class="mt-2 flex gap-4 text-[12px] text-ink-3">
            <span class="flex items-center gap-1.5"
                ><span class="size-2.5 rounded-[3px] bg-ink-2/70" />
                {{ t('খরচ হয়ে গেছে') }}</span
            >
            <span class="flex items-center gap-1.5"
                ><span
                    class="size-2.5 rounded-[3px] bg-sunken ring-1 ring-line-2"
                />
                {{ t('বাকি') }}</span
            >
        </div>
    </section>

    <section class="panel overflow-x-auto">
        <table class="ledger">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ t('তারিখ') }}</th>
                    <th>{{ t('বিবরণ') }}</th>
                    <th class="r">USD</th>
                    <th class="r">BDT</th>
                    <th class="r">Rate</th>
                    <th class="r">{{ t('খরচ / বাকি') }}</th>
                    <th />
                </tr>
            </thead>
            <tbody>
                <tr v-for="b in batches.data" :key="b.id" class="group">
                    <td class="num font-semibold">{{ b.number }}</td>
                    <td class="num text-[12.5px] text-ink-2">
                        {{ niceDate(b.date) }}
                    </td>
                    <td>
                        <p class="font-medium">{{ b.description }}</p>
                        <p v-if="b.notes" class="text-[12px] text-ink-3">
                            {{ b.notes }}
                        </p>
                    </td>
                    <td class="r num">{{ money(b.usd, 'usd') }}</td>
                    <td class="r num">{{ money(b.bdt, 'bdt') }}</td>
                    <td class="r num font-semibold">{{ b.rate }}</td>
                    <td class="r">
                        <span class="num">{{ money(b.used, 'usd') }}</span>
                        <span class="num block text-[11.5px] text-ink-3">{{
                            t('{amount} বাকি', { amount: money(b.left, 'usd') })
                        }}</span>
                    </td>
                    <td class="r whitespace-nowrap">
                        <button
                            v-if="can['fx.edit']"
                            type="button"
                            class="rounded-md p-1.5 text-ink-3 opacity-0 group-hover:opacity-100 hover:bg-sunken hover:text-ink focus:opacity-100"
                            :aria-label="t('বদলান')"
                            @click="edit(b)"
                        >
                            <KIcon name="pen" :size="16" />
                        </button>
                        <button
                            v-if="can['fx.delete'] && b.used === 0"
                            type="button"
                            class="rounded-md p-1.5 text-ink-3 opacity-0 group-hover:opacity-100 hover:bg-out-soft hover:text-out focus:opacity-100"
                            :aria-label="t('মুছুন')"
                            @click="remove(b)"
                        >
                            <KIcon name="bin" :size="16" />
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
        <KPager :page="batches" :sizes="[10, 25, 50, 100]" />
    </section>

    <KModal
        v-model="open"
        :title="t('Batch {n} বদলান', { n: editing?.number })"
        :subtitle="
            t(
                'বদলালে এই batch থেকে নেওয়া সব খরচের FIFO cost নতুন করে হিসাব হবে।',
            )
        "
    >
        <form
            id="fx-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField :label="t('তারিখ')" :error="form.errors.entry_date">
                <KDate v-model="form.entry_date" clearable />
            </KField>
            <KField :label="t('নাম')" :error="form.errors.description">
                <KInput v-model="form.description" />
            </KField>
            <KField label="USD" :error="form.errors.usd">
                <KMoney
                    v-model="form.usd"
                    currency="usd"
                    :invalid="!!form.errors.usd"
                />
            </KField>
            <KField label="BDT" :error="form.errors.bdt">
                <KMoney
                    v-model="form.bdt"
                    currency="bdt"
                    :invalid="!!form.errors.bdt"
                />
            </KField>
            <p
                class="rounded-xl bg-brand-soft px-4 py-2.5 text-[13.5px] text-brand-ink sm:col-span-2"
            >
                Rate: <b class="num">&#2547;{{ previewRate }}</b> / USD
            </p>
            <KField label="Note" optional class="sm:col-span-2">
                <KTextarea v-model="form.notes" />
            </KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="editing = null">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="fx-form"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>
</template>
