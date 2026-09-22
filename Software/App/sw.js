const CACHE_NAME = 'elvosolar-v5';
const urlsToCache = ['/', '/login', '/templates/ElvosolarLogo.png'];

// Staticke subory -> cache-first (hned, bez cakania na siet)
const STATIC_PATTERNS = [
    /fonts\.googleapis\.com/, /fonts\.gstatic\.com/,
    /unpkg\.com/, /cdn\.jsdelivr\.net/,
    /\/templates\/.+\.(png|svg|jpg|ico|css|js)$/,
    /\/manifest\.json$/
];

self.addEventListener('install', e => { self.skipWaiting(); });
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(names => Promise.all(
            names.filter(n => n !== CACHE_NAME).map(n => caches.delete(n))
        )).then(() => clients.claim())
    );
});

self.addEventListener('push', e => {
    const data = e.data ? e.data.json() : { title: 'ElvoControll', body: 'Notifikácia' };
    const isAlert = (data.tag || '').startsWith('alert-');
    e.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/templates/ElvosolarLogo.png',
        badge: '/templates/ElvosolarLogo.png',
        vibrate: [200, 100, 200, 100, 200],
        tag: data.tag || 'elvo-notification',
        renotify: true,
        requireInteraction: isAlert,
        silent: false,
        data: { url: data.url || '/' }
    }));
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    e.waitUntil(clients.openWindow(e.notification.data.url));
});

self.addEventListener('fetch', e => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = req.url;
    // API nikdy necachujeme (vzdy cerstve data)
    if (url.includes('/api/') || url.includes('/healthcheck')) return;
    const isStatic = STATIC_PATTERNS.some(rx => rx.test(url));
    if (!isStatic) return;
    e.respondWith(
        caches.match(req).then(hit => {
            if (hit) {
                // aktualizuj v pozadi (stale-while-revalidate)
                fetch(req).then(res => {
                    if (res && res.ok) {
                        const cl = caches.open(CACHE_NAME);
                        cl.then(cache => cache.put(req, res));
                    }
                }).catch(() => {});
                return hit;
            }
            return fetch(req).then(res => {
                if (res && res.ok) {
                    const clone = res.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(req, clone));
                }
                return res;
            }).catch(() => hit);
        })
    );
});
