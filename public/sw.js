/* Meridian Éclat — web push service worker.
   Receives encrypted push messages and shows a notification; a click focuses an
   open tab (or opens one) at the target URL. */

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'Notification', body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'Notification';
    const options = {
        body: data.body || '',
        icon: data.icon || undefined,
        badge: data.badge || data.icon || undefined,
        tag: data.tag || undefined,
        renotify: !!data.tag,
        data: { url: data.url || '/' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const client of list) {
                if ('focus' in client) {
                    if ('navigate' in client) {
                        client.navigate(url).catch(() => {});
                    }
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        })
    );
});
