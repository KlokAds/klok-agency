<script setup lang="ts">
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import { t } from '@/lib/i18n';

/**
 * Where a client's campaign stands, at a glance: a live green dot, a tick when it is over, two
 * bars when paused (by the client, or for want of money), a warning when it stopped on our side.
 */
export type CampaignStateKey =
    | 'live'
    | 'ended'
    | 'paused_client'
    | 'paused_team'
    | 'paused_credit'
    | 'held';

const props = defineProps<{ state: CampaignStateKey; size?: 'sm' | 'md' }>();

const look = computed(
    () =>
        ({
            live: { label: 'চলছে', tone: 'bg-in-soft text-in' }, // i18n
            ended: { label: 'শেষ হয়েছে', tone: 'bg-sunken text-ink-2' }, // i18n
            paused_client: {
                label: 'আপনি থামিয়েছেন',
                tone: 'bg-wait-soft text-wait',
            }, // i18n
            paused_team: {
                label: 'Klok Ads থামিয়েছে',
                tone: 'bg-wait-soft text-wait',
            }, // i18n
            paused_credit: {
                label: 'টাকা শেষ — থামানো',
                tone: 'bg-out-soft text-out',
            }, // i18n
            held: {
                label: 'আমাদের দিকে সমস্যা — ঠিক হচ্ছে',
                tone: 'bg-out-soft text-out',
            }, // i18n
        })[props.state] ?? { label: props.state, tone: 'bg-sunken text-ink-2' },
);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1.5 rounded-full font-bold"
        :class="[
            look.tone,
            size === 'md'
                ? 'px-3 py-1 text-[12.5px]'
                : 'px-2.5 py-0.5 text-[11.5px]',
        ]"
    >
        <span v-if="state === 'live'" class="relative flex size-2">
            <span
                class="absolute inline-flex size-full animate-ping rounded-full bg-in opacity-60"
            />
            <span class="relative inline-flex size-2 rounded-full bg-in" />
        </span>
        <KIcon v-else-if="state === 'ended'" name="check" :size="13" />
        <KIcon v-else-if="state === 'held'" name="warn" :size="13" />
        <svg
            v-else
            viewBox="0 0 12 12"
            class="size-3"
            fill="currentColor"
            aria-hidden="true"
        >
            <rect x="2.5" y="2" width="2.5" height="8" rx="0.8" />
            <rect x="7" y="2" width="2.5" height="8" rx="0.8" />
        </svg>
        {{ t(look.label) }}
    </span>
</template>
