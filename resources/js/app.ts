import { vStack } from '@/lib/stack';
import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AuthLayout from '@/layouts/AuthLayout.vue';
import MainLayout from '@/layouts/MainLayout.vue';
import PortalLayout from '@/layouts/PortalLayout.vue';
import { initializeFeedback } from '@/lib/feedback';
import { initializeLocale, t } from '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'KlokOne';

void createInertiaApp({
    title: (title) => (title ? `${t(title)} · ${appName}` : appName),
    // public/ pages are for clients (no sign-in): they bring their own frame.
    layout: (name) =>
        name.startsWith('auth/')
            ? AuthLayout
            : name.startsWith('public/')
              ? null
              : name.startsWith('portal/')
                ? PortalLayout
                : MainLayout,
    withApp: (app) => {
        // Tables that turn into cards on a phone (see .stack in app.css).
        app.directive('stack', vStack);
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#a07fbd',
        delay: 120,
    },
});

initializeTheme();
initializeFeedback();
initializeLocale();
