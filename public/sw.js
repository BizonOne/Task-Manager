/*
 * The service worker that shows notifications when the tab is closed.
 *
 * It lives at the root on purpose: a service worker can only control pages
 * under its own path, and one served from /js/ could not act for the whole
 * site.
 *
 * Deliberately tiny. This file is cached by the browser and updated on its own
 * schedule, so anything clever in here is clever code you cannot deploy a fix
 * to — the interesting decisions belong on the server, which is why the push
 * payload arrives ready to display.
 */

self.addEventListener('push', function (event) {
    if (!event.data) return;

    let payload = {};
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: 'Update', body: event.data.text() };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'Update', {
            body: payload.body || '',
            icon: '/brand/logo',
            badge: '/brand/logo',
            data: { url: payload.url || '/' },
            // Replaces an unread notification about the same thing rather than
            // stacking a second one on top of it.
            tag: payload.url || 'update',
            renotify: false,
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const target = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
            // Reuse a tab that is already on this site instead of piling up a
            // new one for every notification.
            for (const client of windows) {
                if (client.url.indexOf(self.registration.scope) === 0 && 'focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }

            return clients.openWindow(target);
        })
    );
});
