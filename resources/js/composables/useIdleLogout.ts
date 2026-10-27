import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { forgetPages } from '@/lib/offline';

type Bridge = { callHandler: (name: string) => Promise<unknown> };

function bridge(): Bridge | undefined {
    return (window as unknown as { flutter_inappwebview?: Bridge })
        .flutter_inappwebview;
}

/**
 * Whether the Android app locks itself (fingerprint or PIN). A new app
 * answers true or false; an app from before the handler answers nothing
 * (null) and keeps the old way, no idle sign-out, until the phone updates.
 * No answer in 3 seconds means the page signs out like a browser does.
 */
async function appLocks(): Promise<boolean> {
    const app = bridge();

    if (!app) {
        return false;
    }

    try {
        const answer = await Promise.race([
            app.callHandler('appLock'),
            new Promise((done) => window.setTimeout(() => done(false), 3000)),
        ]);

        return answer === true || answer === null || answer === undefined;
    } catch {
        return false;
    }
}

/**
 * Signs the user out after a stretch of no activity — a finance screen
 * left open on a shared desk should not stay open. Warns a minute before.
 * Inside the Android app it is skipped only while the app's own lock is on
 * (asked as the time runs out): with the lock off, the phone signs out too.
 */
export function useIdleLogout() {
    const page = usePage();
    const warning = ref(false);
    const secondsLeft = ref(60);
    const inApp = /KlokFinanceApp/.test(navigator.userAgent);

    let last = Date.now();
    let timer: number | undefined;
    // What the app said for this idle stretch; asked again after activity.
    let appLock: 'asking' | 'off' | null = null;

    const events = ['pointerdown', 'keydown', 'scroll', 'touchstart'];

    function bump(): void {
        last = Date.now();
        appLock = null;

        if (warning.value) {
            warning.value = false;
        }
    }

    async function signOut(): Promise<void> {
        // The kept pages go too: offline, they would still show the books.
        forgetPages();
        // The app first stops this phone getting this person's notifications.
        await bridge()
            ?.callHandler('signingOut')
            .catch(() => undefined);
        router.post('/logout');
    }

    function tick(): void {
        const minutes = (page.props.idleMinutes as number | null) ?? 30;
        const idle = (Date.now() - last) / 1000;
        const limit = minutes * 60;

        if (idle < limit - 60) {
            return;
        }

        if (inApp && appLock !== 'off') {
            if (appLock === null) {
                appLock = 'asking';
                void appLocks().then((on) => {
                    if (on) {
                        // The app locks itself; start the stretch again.
                        bump();
                    } else if (appLock === 'asking') {
                        appLock = 'off';
                    }
                });
            }

            return;
        }

        if (idle >= limit) {
            window.clearInterval(timer);
            void signOut();
        } else {
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
