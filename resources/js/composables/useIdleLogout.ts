import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Signs the user out after a stretch of no activity — a finance screen
 * left open on a shared desk should not stay open. Warns a minute before.
 */
export function useIdleLogout() {
    const page = usePage();
    const warning = ref(false);
    const secondsLeft = ref(60);

    let last = Date.now();
    let timer: number | undefined;

    const events = ['pointerdown', 'keydown', 'scroll', 'touchstart'];

    function bump(): void {
        last = Date.now();

        if (warning.value) {
            warning.value = false;
        }
    }

    function tick(): void {
        const minutes = (page.props.idleMinutes as number | null) ?? 30;
        const idle = (Date.now() - last) / 1000;
        const limit = minutes * 60;

        if (idle >= limit) {
            window.clearInterval(timer);
            router.post('/logout');
        } else if (idle >= limit - 60) {
            warning.value = true;
            secondsLeft.value = Math.ceil(limit - idle);
        }
    }

    onMounted(() => {
        events.forEach((e) =>
            window.addEventListener(e, bump, { passive: true }),
        );
        timer = window.setInterval(tick, 1000);
    });

    onBeforeUnmount(() => {
        events.forEach((e) => window.removeEventListener(e, bump));
        window.clearInterval(timer);
    });

    return { warning, secondsLeft, stay: bump };
}
