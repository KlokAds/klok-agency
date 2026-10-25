<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import KSelect from '@/components/k/KSelect.vue';
import { t } from '@/lib/i18n';
import { SETTINGS_TABS } from '@/lib/settings-tabs';

// The parts of Settings: tabs across the top on a computer, one dropdown on a phone.
const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const tabs = computed(() =>
    SETTINGS_TABS.filter((tab) => page.props.auth.can[tab.perm]),
);
const isCurrent = (href: string) =>
    path.value === href || path.value.startsWith(href + '/');
const options = computed(() =>
    tabs.value.map((tab) => ({
        value: tab.href,
        label: t(tab.label),
        icon: tab.icon,
    })),
);
const current = computed({
    get: () => tabs.value.find((tab) => isCurrent(tab.href))?.href ?? null,
    set: (href: string | number | null) => {
        if (typeof href === 'string' && !isCurrent(href)) {
            router.visit(href);
        }
    },
});
</script>

<template>
    <nav class="no-print mb-6" :aria-label="t('Settings-এর ভাগ')">
        <div class="sm:hidden">
            <KSelect v-model="current" :options="options" />
        </div>
        <div class="hidden gap-1 border-b border-line sm:flex sm:flex-wrap">
            <Link
                v-for="tab in tabs"
                :key="tab.href"
                :href="tab.href"
                class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-[14.5px] font-semibold whitespace-nowrap transition-colors"
                :class="
                    isCurrent(tab.href)
                        ? 'border-brand text-ink'
                        : 'border-transparent text-ink-3 hover:text-ink'
                "
            >
                <KIcon :name="tab.icon" :size="15" />{{ t(tab.label) }}
            </Link>
        </div>
    </nav>
</template>
