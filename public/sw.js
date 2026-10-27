/*
 * KlokOne service worker.
 *
 * Keeps on the phone:
 *  - the built files (they never change once built, so cache-first);
 *  - the last copy of every page opened (network first: with internet a page
 *    is always fresh; with none, the last copy shows).
 * Quick entries written offline wait in the page's own queue (lib/offline.ts).
 * Signing out deletes the kept pages, and so does opening the sign-in page,
 * an answer that comes back signed out, or another user signing in: kept
 * pages belong to the one user the server named (X-Kept-For).
 */
const ASSETS = 'kf-assets-v1';
const PAGES = 'kf-pages-v1';
const DATA = 'kf-data-v1';
const WHO = 'kf-who-v1';
const KEEP = 60;

const FALLBACK = `<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>নেট নেই</title><body style="font-family:system-ui;background:#f6f7f6;color:#14211f;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px">
<div><h1 style="font-weight:600">নেট নেই</h1><p>এই পাতাটা আগে খোলা হয়নি, তাই রাখা নেই। নেট এলে আবার চেষ্টা করুন।</p>
<p><a href="javascript:history.back()" style="color:#0d9488">ফিরে যান</a></p></div></body></html>`;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) =>
                                key.startsWith('kf-') &&
                                ![ASSETS, PAGES, DATA, WHO].includes(key),
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

/** Every kept page and list goes, and with them whose they were. */
function forget() {
    return Promise.all([
        caches.delete(PAGES),
        caches.delete(DATA),
        caches.delete(WHO),
    ]);
}

/** Pages are kept for one user: when the server names someone else, the old ones go first. */
async function keepFor(user) {
    const who = await caches.open(WHO);
    const hit = await who.match('/who');

    if (hit && (await hit.text()) === user) {
        return;
    }

    await Promise.all([caches.delete(PAGES), caches.delete(DATA)]);
    await who.put('/who', new Response(user));
}

self.addEventListener('message', (event) => {
    if (event.data === 'clear-pages') {
        event.waitUntil(forget());
    }
});

async function cacheFirst(request) {
    const cache = await caches.open(ASSETS);
    const hit = await cache.match(request);

    if (hit) {
        return hit;
    }

    const response = await fetch(request);

    if (response.ok || response.type === 'opaque') {
        cache.put(request, response.clone());
    }

    return response;
}

/** Never kept: signing in and out, client pages, hooks, and answers meant only for now. */
function keepable(url) {
    return !/^\/(login|logout|register|two-factor|forgot-password|reset-password|user\/confirm|passkeys?|r\/|pay|i\/|hooks|app\/|notifications|quick|search|up)\b/.test(
        url.pathname,
    );
}

async function trim(cache) {
    const keys = await cache.keys();

    for (const key of keys.slice(0, Math.max(0, keys.length - KEEP))) {
        await cache.delete(key);
    }
}

/** The network first; a good answer is kept under `key`. With no internet, the kept copy. */
async function networkFirst(request, cacheName, key) {
    try {
        const response = await fetch(request);
        const landed = new URL(response.url || request.url);
        const user = response.headers.get('X-Kept-For');

        // Sent to sign in, or answered as nobody (signed out here or elsewhere, or the
        // session ran out): nothing kept stays on the phone.
        if (landed.pathname === '/login' || (response.ok && !user)) {
            await forget();

            return response;
        }

        if (response.ok && !response.redirected && keepable(landed)) {
            await keepFor(user);
            const cache = await caches.open(cacheName);
            await cache.put(key, response.clone());
            trim(cache);
        }

        return response;
    } catch (error) {
        const hit = await caches.match(key, { cacheName });

        if (hit) {
            return hit;
        }

        throw error;
    }
}

/** A page change offline whose data was never kept: taken from the kept whole page. */
async function dataFromKeptPage(key) {
    const html = await caches.match(key, { cacheName: PAGES });
    const text = html ? await html.text() : '';
    const match =
        text.match(/<script[^>]*data-page[^>]*>([\s\S]*?)<\/script>/) ||
        text.match(/data-page="([^"]*)"/);

    if (!match) {
        return null;
    }

    const json = match[1]
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');

    return new Response(json, {
        headers: {
            'Content-Type': 'application/json',
            'X-Inertia': 'true',
            Vary: 'X-Inertia',
        },
    });
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (
        url.origin === self.location.origin &&
        url.pathname.startsWith('/build/')
    ) {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (url.hostname === 'fonts.bunny.net' || url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
        event.respondWith(cacheFirst(request));

        return;
    }

    // Opening the sign-in page: whoever comes next must not find the last user's pages.
    if (
        url.origin === self.location.origin &&
        request.mode === 'navigate' &&
        /^\/(login|logout)\b/.test(url.pathname)
    ) {
        event.waitUntil(forget());

        return;
    }

    if (url.origin !== self.location.origin || !keepable(url)) {
        return;
    }

    const key = `${url.pathname}${url.search}`;

    // A page change inside the app (Inertia): its data. Partial reloads are never kept.
    if (
        request.mode !== 'navigate' &&
        request.headers.get('X-Inertia') &&
        !request.headers.get('X-Inertia-Partial-Data') &&
        !request.headers.get('X-Inertia-Partial-Except')
    ) {
        event.respondWith(
            networkFirst(request, DATA, key).catch(async (error) => {
                const kept = await dataFromKeptPage(key);

                if (kept) {
                    return kept;
                }

                throw error;
            }),
        );

        return;
    }

    if (request.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        networkFirst(request, PAGES, key).catch(
            () =>
                new Response(FALLBACK, {
                    headers: { 'Content-Type': 'text/html; charset=utf-8' },
                }),
        ),
    );
});
