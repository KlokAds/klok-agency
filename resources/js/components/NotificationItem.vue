<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import { ago, money } from '@/lib/format';
import { t } from '@/lib/i18n';

export type Notice = {
    id: string;
    kind: string;
    title: string;
    body: string | null;
    params: Record<string, string | number | null>;
    url: string | null;
    whatsapp: string | null;
    sms: string | null;
    summary: DaySummary | null;
    read: boolean;
    at: string;
};

export type DaySummary = {
    spent_usd: number;
    pending_usd: number;
    pending_count: number;
    tasks_due: number;
    ending: {
        id: number;
        client: string | null;
        title: string;
        end_on: string;
    }[];
    ads: {
        client: string;
        currency: string;
        spend: number;
        results: number;
        cpr: number | null;
        clicks?: number;
    }[];
    pilot_error: string | null;
};

const props = defineProps<{ item: Notice; full?: boolean }>();
const emit = defineEmits<{ open: [item: Notice] }>();

const ICONS: Record<string, string> = {
    renewal: 'chat',
    result: 'list',
    scale: 'rocket',
    ended: 'clock',
    summary: 'chart',
    meeting: 'calendar',
    follow_up: 'chat',
    task: 'check',
};

const tone = computed(() =>
    props.item.kind === 'renewal' || props.item.kind === 'ended'
        ? 'bg-wait-soft text-wait'
        : props.item.kind === 'scale'
          ? 'bg-in-soft text-in'
          : 'bg-brand-soft text-brand-ink',
);
</script>

<template>
    <div
        class="flex gap-3 rounded-xl px-3 py-3"
        :class="item.read ? '' : 'bg-surface-2'"
    >
        <span
            class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg"
            :class="tone"
        >
            <KIcon :name="ICONS[item.kind] ?? 'bell'" :size="16" />
        </span>
        <div class="min-w-0 flex-1">
            <component
                :is="item.url ? Link : 'p'"
                :href="item.url ?? undefined"
                class="block text-[13.5px] leading-snug font-semibold hover:text-brand-ink"
                @click="emit('open', item)"
            >
                {{ t(item.title, item.params) }}
            </component>
            <p v-if="item.body" class="mt-0.5 text-[12.5px] text-ink-2">
                {{ t(item.body, item.params) }}
            </p>

            <!-- The end-of-day note, in full on the notifications page -->
            <div
                v-if="full && item.summary"
                class="mt-3 grid gap-3 rounded-xl border border-line p-3 text-[12.5px]"
            >
                <p class="flex flex-wrap gap-x-4 gap-y-1 text-ink-2">
                    <span
                        >{{ t('আজ ad খরচ') }}
                        <b class="num text-ink">{{
                            money(item.summary.spent_usd, 'usd')
                        }}</b></span
                    >
                    <span
                        >{{ t('Client দেবে') }}
                        <b class="num text-ink">{{
                            money(item.summary.pending_usd, 'usd')
                        }}</b></span
                    >
                    <span
                        >{{ t('বাকি কাজ') }}
                        <b class="num text-ink">{{
                            item.summary.tasks_due
                        }}</b></span
                    >
                </p>
                <div v-if="item.summary.ending.length">
                    <p class="eyebrow mb-1">{{ t('কাল শেষ হচ্ছে') }}</p>
                    <p
                        v-for="r in item.summary.ending"
                        :key="r.id"
                        class="text-ink-2"
                    >
                        <b class="text-ink">{{ r.client }}</b> · {{ r.title }}
                    </p>
                </div>
                <div v-if="item.summary.ads.length">
                    <p class="eyebrow mb-1">
                        {{ t('আজকের ads (Klok Pilot)') }}
                    </p>
                    <table class="w-full">
                        <tr
                            v-for="a in item.summary.ads"
                            :key="a.client"
                            class="border-t border-dashed border-line"
                        >
                            <td class="py-1.5 font-medium">{{ a.client }}</td>
                            <td class="num py-1.5 text-right">
                                {{ a.currency === 'USD' ? '$' : ''
                                }}{{ a.spend.toFixed(2) }}
                            </td>
                            <td class="num py-1.5 text-right">
                                {{ a.results }} {{ t('result') }}
                            </td>
                            <td class="num py-1.5 text-right text-ink-3">
                                {{
                                    a.cpr !== null
                                        ? `CPR ${a.cpr.toFixed(2)}`
                                        : '—'
                                }}
                            </td>
                            <td class="num py-1.5 text-right text-ink-3">
                                {{ a.clicks ? `${a.clicks} click` : '' }}
                            </td>
                        </tr>
                    </table>
                </div>
                <p v-if="item.summary.pilot_error" class="text-out">
                    Klok Pilot: {{ item.summary.pilot_error }}
                </p>
            </div>

            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                <span class="text-[11.5px] text-ink-3">{{ ago(item.at) }}</span>
                <a
                    v-if="item.whatsapp"
                    :href="item.whatsapp"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-md bg-in-soft px-2 py-0.5 text-[11.5px] font-semibold text-in hover:bg-in hover:text-white"
                    >WhatsApp</a
                >
                <a
                    v-if="item.sms"
                    :href="item.sms"
                    class="rounded-md bg-sunken px-2 py-0.5 text-[11.5px] font-semibold text-ink-2 hover:bg-ink hover:text-paper"
                    >SMS</a
                >
            </div>
        </div>
    </div>
</template>
