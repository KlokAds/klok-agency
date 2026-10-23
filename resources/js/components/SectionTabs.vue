<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import { t } from '@/lib/i18n';
import { SECTIONS } from '@/lib/sections';

// Tabs for a page that is one of several views of the same thing (the
// dollars and the card; the two ledgers), so each job has one menu item.
const props = defineProps<{ section: keyof typeof SECTIONS }>();

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const tabs = computed(() =>
    SECTIONS[props.section].filter((tab) => page.props.auth.can[tab.perm]),
);
</script>

<template>
    <nav
        class="no-print -mx-4 mb-5 overflow-x-auto px-4 sm:mx-0 sm:px-0"
        :aria-label="t('এই অংশের পাতা')"
    >
        <div
            class="inline-flex min-w-full gap-1 border-b border-line sm:min-w-0"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.href"
                :href="tab.href"
                class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-[13.5px] font-semibold whitespace-nowrap transition-colors"
                :class="
                    path === tab.href
                        ? 'border-brand text-ink'
                        : 'border-transparent text-ink-3 hover:text-ink'
                "
            >
                <KIcon :name="tab.icon" :size="15" />{{ t(tab.label) }}
            </Link>
        </div>
    </nav>
</template>
