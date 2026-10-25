import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Keeps a page's figures current without anyone pulling to refresh: the props are fetched again
 * every minute while the page is on screen, and the moment it comes back on screen after a
 * while away (the app resumed, the tab switched back). Never while someone is typing or has a
 * dialog open, so nothing they are doing is disturbed.
 */
export function useLiveRefresh(everySeconds = 60): void {
    let last = Date.now();
    let timer: number | undefined;

    const busy = () =>
        document.hidden ||
        document.querySelector('[role="dialog"]') !== null ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(
            document.activeElement?.tagName ?? '',
        );

    const refresh = () => {
        if (busy() || !navigator.onLine) {
            return;
        }
        last = Date.now();
        router.reload();
    };

    const onVisible = () => {
        if (!document.hidden && Date.now() - last > 20_000) {
            refresh();
        }
    };

    onMounted(() => {
        timer = window.setInterval(refresh, everySeconds * 1000);
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);
    });

    onBeforeUnmount(() => {
        window.clearInterval(timer);
        document.removeEventListener('visibilitychange', onVisible);
        window.removeEventListener('focus', onVisible);
    });
}
