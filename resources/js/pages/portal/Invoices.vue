<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

defineProps<{
    invoices: {
        number: string;
        issued: string | null;
        due_date: string | null;
        total: number;
        paid: number;
        due: number;
        status: string;
        overdue: boolean;
        url: string;
    }[];
}>();
</script>

<template>
    <Head title="Invoice" />

    <h1 class="text-[24px] font-bold">Invoice</h1>
    <p class="mt-1 text-[13.5px] text-ink-3">
        {{ t('খুললে দেখা, pay করা আর রসিদ — সব এক পাতায়।') }}
    </p>

    <p v-if="!invoices.length" class="panel mt-5 p-5 text-[14px] text-ink-3">
        {{ t('এখনো কোনো invoice নেই।') }}
    </p>

    <section class="mt-5 grid grid-cols-1 gap-3">
        <a
            v-for="i in invoices"
            :key="i.number"
            :href="i.url"
            class="panel flex items-center gap-4 p-4 transition-colors hover:border-brand sm:p-5"
        >
            <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-center gap-2">
                    <span class="num text-[15px] font-semibold">{{
                        i.number
                    }}</span>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-[11.5px] font-bold"
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
                </span>
                <span class="mt-1 block text-[12.5px] text-ink-3">
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
                    class="num block text-[12.5px] font-semibold text-wait"
                    >{{
                        t('বাকি {amount}', { amount: money(i.due, 'bdt') })
                    }}</span
                >
            </span>
        </a>
    </section>
</template>
