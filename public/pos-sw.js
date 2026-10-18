/*
 * Service worker of the offline POS terminal (/sell/pos/offline).
 *
 * It only does two things: keeps the terminal page and the front-end files it needs (so the till opens with no network), and
 * leaves everything else alone. Sales and the catalog live in IndexedDB, managed by the page, not here. API calls are never
 * answered from this cache: an offline sale is queued by the page and replayed when the network is back.
 */
const VERSION = 'v1';
const SHELL = 'pos-shell-' + VERSION;
const ASSETS = 'pos-assets-' + VERSION;
const TERMINAL_PATHS = ['/sell/pos/offline', '/offline'];

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith('pos-') && ! key.endsWith(VERSION)) {
                await caches.delete(key);
            }
        }

        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'precache') {
        event.waitUntil(precache(event.data.urls || []));
    }

    if (event.data && event.data.type === 'warm') {
        event.waitUntil(warm(event.data.urls || []));
    }
});

// Background Sync (where the browser has it): wake any open page so it sends the waiting offline entries
self.addEventListener('sync', (event) => {
    if (event.tag === 'offline-sync') {
        event.waitUntil(self.clients.matchAll({ type: 'window' }).then((clients) => clients.forEach((client) => client.postMessage({ type: 'offline-sync' }))));
    }
});

// Only the two offline screens are ever kept (never any other page): the page asks for them to be stored while online
async function warm(urls) {
    const cache = await caches.open(SHELL);

    await Promise.all(urls.filter((url) => TERMINAL_PATHS.includes(url)).map(async (url) => {
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } });

            if (response.ok && response.redirected === false) {
                await cache.put(new URL(url, self.location.origin).href, response);
            }
        } catch (error) {
            // offline while warming
        }
    }));
}

function friendlyOfflinePage() {
    return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>This page needs the internet</title>'
        + '<body style="font-family:sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem"><h1 style="font-size:1.3rem">This page needs the internet</h1>'
        + '<p>You are offline, so this page cannot open. Nothing you did has been lost.</p><p>These still work without internet:</p>'
        + '<ul><li><a href="/sell/pos/offline">Offline POS terminal</a></li><li><a href="/offline">Offline entries</a> (sales invoice, purchase, payment, expense, new customer or supplier, stock adjustment)</li></ul>'
        + '<p>Entries waiting on this device are sent on their own when the internet is back.</p></body>', {
        status: 503,
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
    });
}

async function precache(urls) {
    const cache = await caches.open(ASSETS);

    await Promise.all(urls.map(async (url) => {
        try {
            // a build file has a hashed name: one already kept never needs fetching again
            if (await cache.match(url)) {
                return;
            }

            const response = await fetch(url, { credentials: 'same-origin' });

            if (response.ok) {
                await cache.put(url, response);
            }
        } catch (error) {
            // offline while warming: the next online visit does it
        }
    }));
}

async function networkFirst(request, cacheName) {
    const cache = await caches.open(cacheName);

    try {
        const response = await fetch(request);

        if (response.ok) {
            await cache.put(request.url, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await cache.match(request.url);

        if (cached) {
            return cached;
        }

        return new Response('<!doctype html><title>Offline</title><p>The offline terminal has not been opened online on this device yet.</p>', {
            status: 503,
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
        });
    }
}

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request.url);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        await cache.put(request.url, response.clone());
    }

    return response;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate' && TERMINAL_PATHS.includes(url.pathname)) {
        event.respondWith(networkFirst(request, SHELL));

        return;
    }

    // any other page: the network, and when there is none a friendly screen instead of the browser's error (nothing is stored)
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => friendlyOfflinePage()));

        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/assets/') || url.pathname.startsWith('/vendor/')) {
        event.respondWith(cacheFirst(request, ASSETS));
    }
});
