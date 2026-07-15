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
    // Rich push: optional big image and up to 2 action buttons.
    const actions = Array.isArray(data.actions)
        ? data.actions.slice(0, 2).map((a, i) => ({ action: 'a' + i, title: a.label || 'Open' }))
        : [];
    const actionUrls = {};
    if (Array.isArray(data.actions)) data.actions.slice(0, 2).forEach((a, i) => { actionUrls['a' + i] = a.url || data.url || '/'; });

    const options = {
        body: data.body || '',
        icon: data.icon || undefined,
        badge: data.badge || data.icon || undefined,
        image: data.image || undefined,
        tag: data.tag || undefined,
        renotify: !!data.tag,
        actions: actions,
        data: { url: data.url || '/', actionUrls: actionUrls },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const d = event.notification.data || {};
    // A button click routes to that button's URL; a body click uses the main URL.
    const url = (event.action && d.actionUrls && d.actionUrls[event.action]) || d.url || '/';

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
