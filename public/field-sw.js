/*
 * Service worker of the field app (/service/work), installed from the same page as the manifest.
 *
 * It keeps the page and the front-end files it needs, so the technician's page opens with no network, and leaves everything else
 * alone: API calls are never answered from this cache (the page keeps the last list of the signed-in user itself and queues nothing
 * here), so nobody can be shown another user's data from a shared device.
 */
const VERSION = 'v1';
const SHELL = 'field-shell-' + VERSION;
const ASSETS = 'field-assets-' + VERSION;
const START = '/service/work';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith('field-') && ! key.endsWith(VERSION)) {
                await caches.delete(key);
            }
        }

        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'precache') {
        event.waitUntil((async () => {
            const cache = await caches.open(ASSETS);

            await Promise.all((event.data.urls || []).map(async (url) => {
                try {
                    const response = await fetch(url, { credentials: 'same-origin' });

                    if (response.ok) {
                        await cache.put(url, response);
                    }
                } catch (error) {
                    // offline while warming: the next online visit does it
                }
            }));
        })());
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
        return;
    }

    if (request.mode === 'navigate' && url.pathname.startsWith(START)) {
        event.respondWith((async () => {
            const cache = await caches.open(SHELL);

            try {
                const response = await fetch(request);

                if (response.ok) {
                    await cache.put(START, response.clone());
                }

                return response;
            } catch (error) {
                return (await cache.match(START)) || Response.error();
            }
        })());

        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons-field-')) {
        event.respondWith((async () => {
            const cache = await caches.open(ASSETS);
            const cached = await cache.match(request);

            if (cached) {
                return cached;
            }

            const response = await fetch(request);

            if (response.ok) {
                await cache.put(request, response.clone());
            }

            return response;
        })());
    }
});
