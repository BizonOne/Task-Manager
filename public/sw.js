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

// Take over as soon as a new version of this file lands. Without these two a
// fix here waits for every tab on the site to be closed, which for a tool
// people leave open all day means it effectively never ships.
self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

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
            // Every notification is its own notification.
            //
            // These used to be grouped by task, so three comments on one task
            // were one entry instead of three. That is tidier and it cost two
            // rounds of "nothing arrived": a notification replacing an earlier
            // one with the same tag is silent by default, and even with
            // renotify the operating system may still fold it into what is
            // already sitting in its notification centre. A tidy list is worth
            // nothing next to a notification that shows up.
            //
            // No tag also means no renotify — Chrome throws on renotify
            // without one, and a throw in here means no notification at all.
            timestamp: Date.now(),
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
