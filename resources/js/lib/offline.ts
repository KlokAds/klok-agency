/**
 * Working with no internet. The service worker (public/sw.js) keeps the
 * last copy of every page opened, so they still open offline. A quick
 * entry written offline waits here, on this phone, under its own id, and
 * goes to the server once the internet is back; the server books each id
 * once, so a resend never books it twice. Entries the server refuses (a
 * wrong field) stay in the list, with the reason, until they are removed.
 */
import { reactive } from 'vue';
import { toast } from '@/lib/feedback';
import { t } from '@/lib/i18n';

const QUEUE = 'kf-offline-queue';
const LOOKUPS = 'kf-lookups';

export type Waiting = {
    id: string;
    user: number;
    job: string;
    data: Record<string, unknown>;
    at: string;
    error?: string;
};

export const offline = reactive({
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    waiting: read<Waiting[]>(QUEUE) ?? [],
    sending: false,
});

function read<T>(key: string): T | null {
    try {
        const raw = localStorage.getItem(key);

        return raw ? (JSON.parse(raw) as T) : null;
    } catch {
        return null;
    }
}

function write(key: string, value: unknown): void {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage full or blocked: the entry stays in memory until sent.
    }
}

function uuid(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Keeps a quick entry to send later. */
export function keep(
    user: number,
    job: string,
    data: Record<string, unknown>,
): void {
    offline.waiting.push({
        id: uuid(),
        user,
        job,
        data: JSON.parse(JSON.stringify(data)),
        at: new Date().toISOString(),
    });
    write(QUEUE, offline.waiting);
    toast(
        t('নেট নেই — entry phone-এ রাখা হলো, নেট এলে নিজে থেকে জমা হবে।'),
        'info',
    );
}

export function drop(id: string): void {
    offline.waiting = offline.waiting.filter((w) => w.id !== id);
    write(QUEUE, offline.waiting);
}

/** The lists the quick entry chooses from, kept for when there is no internet. */
export function keepLookups(user: number, lookups: unknown): void {
    if (lookups) {
        write(LOOKUPS, { user, lookups });
    }
}

export function keptLookups<T>(user: number): T | null {
    const kept = read<{ user: number; lookups: T }>(LOOKUPS);

    return kept && kept.user === user ? kept.lookups : null;
}

/** Sends what is waiting, oldest first; stops at the first sign of no internet. */
export async function send(user: number): Promise<number> {
    if (offline.sending || !navigator.onLine) {
        return 0;
    }

    offline.sending = true;
    let sent = 0;

    try {
        for (const item of [...offline.waiting]) {
            if (item.user !== user || item.error) {
                continue;
            }

            let response: Response;

            try {
                response = await fetch(`/quick/${item.job}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'manual',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrf(),
                        'X-Offline-Id': item.id,
                    },
                    body: JSON.stringify(item.data),
                });
            } catch {
                break;
            }

            // Booked (the server answers with a redirect back) or already booked.
            if (response.type === 'opaqueredirect' || response.ok) {
                drop(item.id);
                sent++;
                continue;
            }

            if (response.status === 422 || response.status === 403) {
                const body = (await response.json().catch(() => ({}))) as {
                    message?: string;
                };
                item.error = body.message ?? String(response.status);
                write(QUEUE, offline.waiting);
                continue;
            }

            // Signed out, session expired or the server is down: try again later.
            break;
        }
    } finally {
        offline.sending = false;
    }

    if (sent > 0) {
        toast(t('অফলাইনে লেখা {count} টা entry জমা হয়েছে।', { count: sent }));
    }

    return sent;
}

let started = false;

/** Watches the connection and registers the service worker; once per page load. */
export function startOffline(
    user: () => number | null,
    onSent: () => void,
): void {
    if (started || typeof window === 'undefined') {
        return;
    }

    started = true;

    const trySend = async () => {
        const id = user();

        if (id !== null && (await send(id)) > 0) {
            onSent();
        }
    };

    window.addEventListener('online', () => {
        offline.online = true;
        void trySend();
    });
    window.addEventListener('offline', () => (offline.online = false));
    window.setInterval(() => void trySend(), 60_000);
    void trySend();

    if ('serviceWorker' in navigator && import.meta.env.PROD) {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    }
}

/** Signing out: the kept pages and lists go; entries not yet sent stay for their writer. */
export function forgetPages(): void {
    try {
        localStorage.removeItem(LOOKUPS);
    } catch {
        // nothing kept
    }

    navigator.serviceWorker?.controller?.postMessage('clear-pages');
}
