<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import { money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';

defineProps<{
    platforms: {
        id: number;
        name: string;
        spent: number;
        charged: number;
        due: number;
        charges: {
            id: number;
            date: string | null;
            description: string;
            amount: number;
            notes: string | null;
        }[];
    }[];
    card: number;
}>();
</script>

<template>
    <PageHeader
        :eyebrow="t('ব্যবসা')"
        title="Ad Platform"
        :lead="
            t(
                'Ad চললেই খরচ লেখা হয়, কিন্তু card থেকে টাকা কাটে পরে — threshold-এ বা invoice-এ। এখানে দেখা যায় কোন platform আর কত কাটবে।',
            )
        "
    />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section v-for="p in platforms" :key="p.id" class="panel flex flex-col">
            <div class="p-5 sm:p-6">
                <h2 class="text-[17px] font-bold">{{ p.name }}</h2>
                <dl class="mt-4 grid grid-cols-3 gap-3">
                    <div>
                        <dt class="eyebrow">{{ t('খরচ') }}</dt>
                        <dd class="num mt-1 text-[17px]">
                            {{ money(p.spent, 'usd') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('কেটেছে') }}</dt>
                        <dd class="num mt-1 text-[17px]">
                            {{ money(p.charged, 'usd') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="eyebrow">{{ t('আরো কাটবে') }}</dt>
                        <dd
                            class="num mt-1 text-[17px] font-semibold"
                            :class="p.due > 0 ? 'text-out' : ''"
                        >
                            {{ money(p.due, 'usd') }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="p.spent > 0"
                    class="mt-4 h-2 overflow-hidden rounded-full bg-sunken"
                >
                    <div
                        class="h-full rounded-full bg-ink-2/70"
                        :style="{
                            width: `${Math.min(100, (p.charged / p.spent) * 100)}%`,
                        }"
                    />
                </div>
            </div>
            <div class="mt-auto border-t border-line">
                <p class="px-5 pt-4 text-[12px] font-medium text-ink-3 sm:px-6">
                    {{ t('সর্বশেষ card charge') }}
                </p>
                <ul v-if="p.charges.length" class="px-5 pb-3 sm:px-6">
                    <li
                        v-for="c in p.charges"
                        :key="c.id"
                        class="flex items-baseline gap-3 border-b border-line py-2.5 text-[13px] last:border-0"
                    >
                        <span
                            class="num w-14 shrink-0 text-[12px] text-ink-3"
                            >{{ niceDate(c.date, false) }}</span
                        >
                        <span
                            class="min-w-0 flex-1 truncate"
                            :title="c.notes ?? ''"
                            >{{ c.description }}</span
                        >
                        <span class="num font-medium">{{
                            money(c.amount, 'usd')
                        }}</span>
                    </li>
                </ul>
                <KEmpty v-else :title="t('এখনো কাটেনি')" />
            </div>
        </section>
    </div>
</template>
