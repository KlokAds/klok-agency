<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KMoney from '@/components/k/KMoney.vue';
import { money, niceDate, today } from '@/lib/format';
import { t } from '@/lib/i18n';

const props = defineProps<{
    card: number;
    platform_due: number;
    business_usd: number;
    history: { id: number; date: string; app: number; system: number; difference: number; note: string | null; by: string | null }[];
}>();

const can = computed(() => usePage().props.auth.can);
const form = useForm({ checked_on: today(), app_balance: '' as string | null, note: '' as string | null });

const typed = computed(() => Math.round(parseFloat(form.app_balance || 'NaN') * 100));
const diff = computed(() => (Number.isNaN(typed.value) ? null : typed.value - props.card));
</script>

<template>
    <PageHeader :eyebrow="t('খাতা')" :title="t('Card মেলানো')" :lead="t('Card-এর app-এ যা balance দেখাচ্ছে, তা লিখুন — খাতার সাথে মিলছে কিনা সাথে সাথে দেখাবে।')" />

    <div class="grid gap-6 lg:grid-cols-[1fr_1.2fr]">
        <section class="panel p-5 sm:p-6">
            <dl class="grid grid-cols-2 gap-4">
                <div>
                    <dt class="eyebrow">{{ t('খাতা অনুযায়ী card') }}</dt>
                    <dd class="num mt-1.5 text-[28px] font-medium">{{ money(card, 'usd') }}</dd>
                </div>
                <div>
                    <dt class="eyebrow">{{ t('Platform আরো কাটবে') }}</dt>
                    <dd class="num mt-1.5 text-[28px] font-medium text-out">{{ money(platform_due, 'usd') }}</dd>
                </div>
            </dl>

            <form v-if="can['reconcile.create']" class="mt-6 flex flex-col gap-4 border-t border-dashed border-line pt-6" @submit.prevent="form.post('/reconcile', { preserveScroll: true, onSuccess: () => form.reset('app_balance', 'note') })">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <KField :label="t('কবে দেখলেন')" :error="form.errors.checked_on">
                        <KDate v-model="form.checked_on" :max="today()" />
                    </KField>
                    <KField :label="t('App-এ balance')" :error="form.errors.app_balance">
                        <KMoney v-model="form.app_balance" currency="usd" :invalid="!!form.errors.app_balance" />
                    </KField>
                </div>

                <div
                    v-if="diff !== null"
                    class="flex items-center gap-3 rounded-xl px-4 py-3 text-[14px] font-medium"
                    :class="diff === 0 ? 'bg-in-soft text-in' : 'bg-out-soft text-out'"
                >
                    <KIcon :name="diff === 0 ? 'check' : 'warn'" />
                    <span v-if="diff === 0">{{ t('মিলেছে — খাতা আর card এক।') }}</span>
                    <span v-else>{{ t('পার্থক্য') }} <b class="num">{{ money(diff, 'usd', { sign: true }) }}</b> — {{ diff > 0 ? t('card-এ বেশি, কোনো খরচ বেশি লেখা হয়েছে বা টাকা ঢোকা বাদ পড়েছে।') : t('card-এ কম, কোনো card charge বাদ পড়েছে।') }}</span>
                </div>

                <KField label="Note" optional>
                    <KInput v-model="form.note" :placeholder="t('যেমন app screenshot সকাল ১০টা')" />
                </KField>
                <div class="flex justify-end">
                    <KButton type="submit" variant="primary" :loading="form.processing">{{ t('মিলানো লিখে রাখুন') }}</KButton>
                </div>
            </form>
        </section>

        <section class="panel overflow-hidden">
            <h2 class="px-5 pt-5 text-[16px] font-bold sm:px-6">{{ t('আগের মিলানো') }}</h2>
            <KEmpty v-if="!history.length" :title="t('এখনো কোনো মিলানো নেই')" :body="t('প্রতি সপ্তাহে একবার মিলিয়ে রাখলে ভুল তাড়াতাড়ি ধরা পড়ে।')" />
            <table v-else class="ledger mt-3">
                <thead>
                    <tr><th>{{ t('তারিখ') }}</th><th class="r">App</th><th class="r">{{ t('খাতা') }}</th><th class="r">{{ t('পার্থক্য') }}</th><th>{{ t('কে') }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="h in history" :key="h.id">
                        <td class="num text-[12.5px]">{{ niceDate(h.date) }}<span v-if="h.note" class="block font-sans text-[12px] text-ink-3">{{ h.note }}</span></td>
                        <td class="r num">{{ money(h.app, 'usd') }}</td>
                        <td class="r num">{{ money(h.system, 'usd') }}</td>
                        <td class="r num font-semibold" :class="h.difference === 0 ? 'text-in' : 'text-out'">{{ h.difference === 0 ? '✓' : money(h.difference, 'usd', { sign: true }) }}</td>
                        <td class="text-[13px] text-ink-2">{{ h.by }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
