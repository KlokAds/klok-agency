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
    spent_bdt?: number;
    pending_usd: number;
    pending_count: number;
    tasks_due: number;
    ending: {
        id: number | string;
        client: string | null;
        title: string;
        end_on: string;
    }[];
    ads: {
        client: string;
        account?: string;
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
    invoice_paid: 'check',
    invoice_overdue: 'warn',
    invoice_claim: 'doc',
};

const tone = computed(() =>
    ['renewal', 'ended', 'invoice_overdue', 'invoice_claim'].includes(
        props.item.kind,
    )
        ? 'bg-wait-soft text-wait'
        : ['scale', 'invoice_paid'].includes(props.item.kind)
          ? 'bg-in-soft text-in'
          : 'bg-brand-soft text-brand-ink',
);
</script>

<template>
    <div
        class="relative flex gap-3 rounded-xl px-3 py-3"
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
                class="block text-[13.5px] leading-snug font-semibold break-words"
                :class="[
                    item.url ? 'hover:text-brand-ink' : '',
                    item.read ? 'text-ink-2' : 'text-ink',
                ]"
                @click="emit('open', item)"
            >
                {{ t(item.title, item.params) }}
            </component>
            <p
                v-if="item.body"
                class="mt-0.5 text-[12.5px] break-words text-ink-2"
            >
                {{ t(item.body, item.params) }}
            </p>

            <!-- The end-of-day note, in full on the notifications page -->
            <div
                v-if="full && item.summary"
                class="mt-3 grid grid-cols-1 gap-3 rounded-xl border border-line p-3 text-[12.5px]"
            >
                <p class="flex flex-wrap gap-x-4 gap-y-1 text-ink-2">
                    <span
                        >{{ t('আজ ad খরচ') }}
                        <b class="num text-ink">{{
                            money(item.summary.spent_usd, 'usd')
                        }}</b
                        ><b v-if="item.summary.spent_bdt" class="num text-ink">
                            + {{ money(item.summary.spent_bdt, 'bdt') }}</b
                        ></span
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
                    <!-- One line a client, the figures wrapping under the name on a phone. -->
                    <ul class="divide-y divide-line">
                        <li
                            v-for="a in item.summary.ads"
                            :key="a.account ?? a.client"
                            class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5"
                        >
                            <span class="min-w-0 flex-1 truncate font-medium">{{
                                a.client
                            }}</span>
                            <span class="num text-ink">
                                {{
                                    a.currency === 'USD'
                                        ? '$'
                                        : a.currency === 'BDT'
                                          ? String.fromCharCode(0x09f3)
                                          : ''
                                }}{{ a.spend.toFixed(2) }}
                            </span>
                            <span class="num">
                                {{ a.results }} {{ t('result') }}
                            </span>
                            <span v-if="a.cpr !== null" class="num text-ink-3">
                                CPR {{ a.cpr.toFixed(2) }}
                            </span>
                            <span v-if="a.clicks" class="num text-ink-3">
                                {{ a.clicks }} click
                            </span>
                        </li>
                    </ul>
                </div>
                <p v-if="item.summary.pilot_error" class="text-out">
                    Klok Pilot: {{ item.summary.pilot_error }}
                </p>
            </div>

            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                <span
                    class="flex items-center gap-1.5 text-[11.5px] whitespace-nowrap text-ink-3"
                >
                    <span
                        v-if="!item.read"
                        class="size-1.5 rounded-full bg-brand"
                        :title="t('পড়া হয়নি')"
                    />
                    {{ ago(item.at) }}
                </span>
                <!-- Plain anchors (WhatsApp keeps its new tab), dressed as small buttons. -->
                <a
                    v-if="item.whatsapp"
                    :href="item.whatsapp"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-[10px] border border-in/30 bg-in-soft px-3 text-[12.5px] font-semibold whitespace-nowrap text-in hover:border-in hover:bg-in hover:text-white"
                    ><KIcon name="chat" :size="14" />WhatsApp</a
                >
                <a
                    v-if="item.sms"
                    :href="item.sms"
                    class="inline-flex h-8 items-center gap-1.5 rounded-[10px] border border-line-2 bg-surface px-3 text-[12.5px] font-semibold whitespace-nowrap text-ink hover:border-ink-3 hover:bg-surface-2"
                    :class="item.whatsapp ? '' : 'ml-auto'"
                    ><KIcon name="mail" :size="14" />SMS</a
                >
            </div>
        </div>
    </div>
</template>
