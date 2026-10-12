/*
 * Service worker of web push notifications. It is registered with its own narrow scope (/push-scope/) so it never competes with the
 * offline POS worker; push events reach it through the subscription of its registration, wherever the page is.
 * It shows the notification the server sent ({title, body, url}) and opens the url when it is clicked. It caches nothing.
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { title: 'Notification', body: event.data ? event.data.text() : '' };
    }

    const title = String(data.title || 'Notification').slice(0, 120);
    const url = typeof data.url === 'string' && data.url.startsWith('/') ? data.url : '/dashboard';

    event.waitUntil(self.registration.showNotification(title, {
        body: String(data.body || '').slice(0, 300),
        icon: '/favicon.svg',
        data: { url },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/dashboard';

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        for (const client of windows) {
            if ('focus' in client) {
                await client.focus();
                if ('navigate' in client) {
                    await client.navigate(url);
                }

                return;
            }
        }

        await self.clients.openWindow(url);
    })());
});
