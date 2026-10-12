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
const TERMINAL_PATH = '/sell/pos/offline';

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
});

async function precache(urls) {
    const cache = await caches.open(ASSETS);

    await Promise.all(urls.map(async (url) => {
        try {
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

    if (request.mode === 'navigate' && url.pathname === TERMINAL_PATH) {
        event.respondWith(networkFirst(request, SHELL));

        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/assets/') || url.pathname.startsWith('/vendor/')) {
        event.respondWith(cacheFirst(request, ASSETS));
    }
});
