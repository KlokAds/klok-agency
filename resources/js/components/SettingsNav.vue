<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import { t } from '@/lib/i18n';
import { SETTINGS_TABS } from '@/lib/settings-tabs';

// The tabs across the top of every Settings page.
const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const tabs = computed(() =>
    SETTINGS_TABS.filter((tab) => page.props.auth.can[tab.perm]),
);
</script>

<template>
    <nav
        class="no-print -mx-4 mb-6 [scrollbar-width:none] overflow-x-auto px-4 sm:mx-0 sm:px-0 [&::-webkit-scrollbar]:hidden"
        :aria-label="t('Settings-এর ভাগ')"
    >
        <div
            class="inline-flex min-w-full gap-1 border-b border-line sm:min-w-0"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.href"
                :href="tab.href"
                class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-[14.5px] font-semibold whitespace-nowrap transition-colors"
                :class="
                    path === tab.href || path.startsWith(tab.href + '/')
                        ? 'border-brand text-ink'
                        : 'border-transparent text-ink-3 hover:text-ink'
                "
            >
                <KIcon :name="tab.icon" :size="15" />{{ t(tab.label) }}
            </Link>
        </div>
    </nav>
</template>
