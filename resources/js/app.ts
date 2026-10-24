import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
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
              : AppLayout,
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#0d9488',
        delay: 120,
    },
});

initializeTheme();
initializeFeedback();
initializeLocale();
