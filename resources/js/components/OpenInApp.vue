<script setup lang="ts">
import { computed, ref } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import { t } from '@/lib/i18n';

/**
 * On an Android phone's browser (not inside the app): "open this in KlokOne". The intent link opens
 * the app on this very page when it is installed, else the download page. Hidden once dismissed.
 */
const show = ref(
    typeof navigator !== 'undefined' &&
        /Android/i.test(navigator.userAgent) &&
        !/KlokFinanceApp/.test(navigator.userAgent) &&
        (() => {
            try {
                return localStorage.getItem('open-in-app:hidden') !== '1';
            } catch {
                return true;
            }
        })(),
);

const link = computed(() => {
    if (typeof window === 'undefined') {
        return '#';
    }
    const { host, pathname, search } = window.location;
    const fallback = encodeURIComponent(`${window.location.origin}/app/get`);

    return `intent://${host}${pathname}${search}#Intent;scheme=https;package=com.klokads.finance;S.browser_fallback_url=${fallback};end`;
});

function hide(): void {
    show.value = false;
    try {
        localStorage.setItem('open-in-app:hidden', '1');
    } catch {
        // nothing kept: it shows again next time
    }
}
</script>

<template>
    <div
        v-if="show"
        class="flex items-center gap-3 border-b border-line bg-brand-soft px-4 py-2.5"
    >
        <span
            class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand text-white"
            ><KIcon name="bell" :size="16"
        /></span>
        <span class="min-w-0 flex-1 text-[13.5px] leading-tight text-ink-2">{{
            t('KlokOne app-এ আরও সহজে দেখুন')
        }}</span>
        <a
            :href="link"
            class="inline-flex h-8 shrink-0 items-center rounded-lg bg-brand px-3 text-[13.5px] font-bold whitespace-nowrap text-white dark:text-rail"
            >{{ t('App-এ খুলুন') }}</a
        >
        <button
            type="button"
            class="grid size-7 shrink-0 place-items-center rounded-md text-ink-3"
            :aria-label="t('বন্ধ')"
            @click="hide"
        >
            <KIcon name="close" :size="14" />
        </button>
    </div>
</template>
