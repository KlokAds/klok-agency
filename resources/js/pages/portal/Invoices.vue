<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import { confirmAction } from '@/lib/feedback';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

type Row = {
    number: string;
    issued: string | null;
    due_date: string | null;
    total: number;
    paid: number;
    due: number;
    status: string;
    overdue: boolean;
    url: string;
    uid: string;
    mine: boolean;
    change: 'amount' | 'days' | null;
};

defineProps<{ invoices: Row[] }>();

// One the client made and has not paid is theirs to cancel; ours they only pay.
async function cancel(i: Row): Promise<void> {
    if (
        await confirmAction({
            title: t('{number} বাতিল করবেন?', { number: i.number }),
            body: t(
                'এটা আর pay করা যাবে না। দরকার হলে নতুন একটা বানাতে পারবেন।',
            ),
            danger: true,
        })
    ) {
        router.post(
            `/portal/invoices/${i.uid}/void`,
            {},
            { preserveScroll: true },
        );
    }
}

// A top-up they made: the taka can change before it is paid.
const editing = ref<Row | null>(null);
const form = useForm({ amount: '' });
function openChange(i: Row): void {
    editing.value = i;
    form.amount = String(Math.round(i.total / 100));
    form.clearErrors();
}
function saveChange(): void {
    if (!editing.value) {
        return;
    }

    form.post(`/portal/invoices/${editing.value.uid}/amount`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}
</script>

<template>
    <Head title="Invoice" />

    <h1 class="text-[24px] font-bold">Invoice</h1>
    <p class="mt-1 text-[14.5px] text-ink-3">
        {{ t('খুললে দেখা, pay করা আর রসিদ — সব এক পাতায়।') }}
    </p>

    <p v-if="!invoices.length" class="panel mt-5 p-5 text-[15px] text-ink-3">
        {{ t('এখনো কোনো invoice নেই।') }}
    </p>

    <section class="mt-5 grid grid-cols-1 gap-3">
        <div
            v-for="i in invoices"
            :key="i.number"
            class="panel transition-colors hover:border-brand"
        >
            <a :href="i.url" class="flex items-center gap-4 p-4 sm:p-5">
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="num text-[16px] font-semibold">{{
                            i.number
                        }}</span>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-[13px] font-bold"
                            :class="
                                i.status === 'paid'
                                    ? 'bg-in-soft text-in'
                                    : i.status === 'void'
                                      ? 'bg-sunken text-ink-3'
                                      : i.overdue
                                        ? 'bg-out-soft text-out'
                                        : 'bg-wait-soft text-wait'
                            "
                            >{{
                                i.status === 'paid'
                                    ? t('পরিশোধ')
                                    : i.status === 'void'
                                      ? t('বাতিল')
                                      : i.overdue
                                        ? t('তারিখ পার')
                                        : i.status === 'partial'
                                          ? t('আংশিক')
                                          : t('বাকি')
                            }}</span
                        >
                        <span
                            v-if="i.mine"
                            class="rounded-full bg-sunken px-2.5 py-0.5 text-[13px] font-semibold text-ink-3"
                            >{{ t('আপনার বানানো') }}</span
                        >
                    </span>
                    <span class="mt-1 block text-[13.5px] text-ink-3">
                        {{ i.issued }}
                        <template v-if="i.due_date && i.status !== 'paid'">
                            ·
                            {{
                                t('শেষ তারিখ {date}', { date: i.due_date })
                            }}</template
                        >
                    </span>
                </span>
                <span class="text-right">
                    <span class="num block text-[16px] font-bold">{{
                        money(i.total, 'bdt')
                    }}</span>
                    <span
                        v-if="i.due > 0 && i.status !== 'void'"
                        class="num block text-[13.5px] font-semibold text-wait"
                        >{{
                            t('বাকি {amount}', { amount: money(i.due, 'bdt') })
                        }}</span
                    >
                </span>
            </a>
            <div
                v-if="i.mine"
                class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-3 sm:px-5"
            >
                <KButton
                    v-if="i.change === 'amount'"
                    size="sm"
                    icon="pen"
                    @click="openChange(i)"
                    >{{ t('বদলান') }}</KButton
                >
                <KButton
                    v-else-if="i.change === 'days'"
                    size="sm"
                    icon="pen"
                    :href="i.url"
                    external
                    >{{ t('বদলান') }}</KButton
                >
                <KButton
                    size="sm"
                    variant="danger"
                    icon="close"
                    @click="cancel(i)"
                    >{{ t('বাতিল করুন') }}</KButton
                >
            </div>
        </div>
    </section>

    <KModal
        :model-value="editing !== null"
        width="md"
        :title="t('কত টাকার credit?')"
        :subtitle="editing?.number"
        @update:model-value="(open: boolean) => !open && (editing = null)"
    >
        <KField :label="t('কত টাকা')" :error="form.errors.amount">
            <KInput
                v-model="form.amount"
                type="tel"
                inputmode="numeric"
                :invalid="!!form.errors.amount"
            />
        </KField>
        <template #footer>
            <KButton variant="quiet" @click="editing = null">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                :loading="form.processing"
                @click="saveChange"
                >{{ t('রাখুন') }}</KButton
            >
        </template>
    </KModal>
</template>
