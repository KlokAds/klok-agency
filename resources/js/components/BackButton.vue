<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import KButton from '@/components/k/KButton.vue';
import { canGoBack } from '@/lib/back';
import { t } from '@/lib/i18n';

/**
 * Top of every inner page: back to the page before, or one level up when the page was opened
 * straight from a link. The home page has none.
 */
const props = defineProps<{ home: string }>();

const page = usePage();
const path = computed(
    () => page.url.split(/[?#]/)[0].replace(/\/+$/, '') || '/',
);
const shown = computed(() => path.value !== props.home && path.value !== '/');

function back(): void {
    if (canGoBack()) {
        window.history.back();

        return;
    }

    const up = path.value.replace(/\/[^/]+$/, '');
    router.visit(up.split('/').length > 1 && up !== '' ? up : props.home);
}
</script>

<template>
    <div v-if="shown" class="no-print mb-3">
        <KButton size="sm" variant="plain" icon="back" @click="back">{{
            t('ফিরে যান')
        }}</KButton>
    </div>
</template>
