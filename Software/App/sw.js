const CACHE_NAME = 'elvosolar-v1';
const urlsToCache = ['/'];

self.addEventListener('install', e => { self.skipWaiting(); });
self.addEventListener('activate', e => { e.waitUntil(clients.claim()); });

self.addEventListener('push', e => {
    const data = e.data ? e.data.json() : { title: 'ElvoControll', body: 'Notifikácia' };
    e.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/templates/ElvosolarLogo.png',
        badge: '/templates/ElvosolarLogo.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'elvo-notification',
        data: { url: data.url || '/' }
    }));
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    e.waitUntil(clients.openWindow(e.notification.data.url));
});
