const CACHE_NAME = 'elvosolar-v9';

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
            names.map(n => caches.delete(n))  // VYMAZE VSETKY cache (aj aktualnu) — cisty start
        )).then(() => clients.claim())
    );
});

// Obnova push subscription — Chrome/Android obcas zmeni token (update prehliadaca),
// bez tejto udalosti by notifikacie po case umreli. Novy token sa samodosle na server.
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        self.registration.pushManager.subscribe(
            (event.oldSubscription && event.oldSubscription.options)
                ? event.oldSubscription.options
                : { userVisibleOnly: true, applicationServerKey: undefined }
        )
        .catch(() => null)
        .then(async (newSubscription) => {
            if (!newSubscription) return;
            // Posli novy token na backend (cookie -> credentials include)
            try {
                await fetch('/api/push/subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(newSubscription.toJSON())
                });
            } catch (e) { /* skusime pri dalsej udalosti */ }
            // Ak zmena vznikla zo starej subscription, backend si mrtvy endpoint
            // vycisti automaticky pri prvom push (410 Gone handler)
        })
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
