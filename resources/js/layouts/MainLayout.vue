<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import PortalLayout from '@/layouts/PortalLayout.vue';

/**
 * The frame of every signed-in page: a client's own person sees their own pages (profile,
 * password) inside the portal, everyone else the books.
 */
const isClient = computed(() => usePage().props.auth.user?.role === 'Client');
</script>

<template>
    <PortalLayout v-if="isClient"><slot /></PortalLayout>
    <AppLayout v-else><slot /></AppLayout>
</template>
