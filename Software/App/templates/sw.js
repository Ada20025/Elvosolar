// Service Worker pre Push Notifikacie
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};
    const title = data.title || 'ElvoControll';
    const options = {
        body: data.body || 'Nove upozornenie',
        icon: '/templates/ElvosolarLogo.png',
        badge: '/templates/ElvosolarLogo.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'elvosolar',
        data: data.url || '/dashboard',
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data || '/dashboard')
    );
});
