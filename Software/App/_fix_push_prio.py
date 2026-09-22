# -*- coding: utf-8 -*-
"""
Push notifikacie — Android head-up priorita:
1) push_helper.php: Urgency: high uz je, pridame aj Topic (collapse key)
2) sw.js: standardny push handler uz je — pridame requireInteraction pre alert
3) dashboard.html: lokalne notifikacie (offline) uz maju vibrate — pridame priority tag
"""
import io

OK, ERR = [], []


def read(p):
    return io.open(p, 'r', encoding='utf-8').read()


def write(p, c):
    io.open(p, 'w', encoding='utf-8', newline='').write(c)


def rep(path, old, new, label, required=True):
    c = read(path)
    if new in c:
        OK.append('SKIP (already): ' + label)
        return
    for o in (old, old.replace('\n', '\r\n')):
        if o in c:
            c = c.replace(o, new.replace('\n', '\r\n') if '\r\n' in c else new, 1)
            write(path, c)
            OK.append('OK: ' + label)
            return
    if required:
        ERR.append('NOT FOUND: ' + label)
    else:
        OK.append('SKIP (not found): ' + label)


# 1) push_helper.php — pridaj Topic header (collapse key — nove notifikacie nahradia stare)
rep('push_helper.php',
    """'header' => "Content-Type: application/octet-stream\\r\\nContent-Encoding: aes128gcm\\r\\nTTL: 604800\\r\\nUrgency: high\\r\\nAuthorization: vapid t=" . $jwt . ", k=" . $keys['pub'] . "\\r\\n",""",
    """'header' => "Content-Type: application/octet-stream\\r\\nContent-Encoding: aes128gcm\\r\\nTTL: 604800\\r\\nUrgency: high\\r\\nTopic: elvo-alert\\r\\nAuthorization: vapid t=" . $jwt . ", k=" . $keys['pub'] . "\\r\\n",""",
    'push_helper: Topic header (collapse)')

# 2) sw.js — requireInteraction pre alert tagy (ostane visiet do kliknutia na Androide)
rep('sw.js',
    """self.addEventListener('push', e => {
    const data = e.data ? e.data.json() : { title: 'ElvoControll', body: 'Notifikácia' };
    e.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/templates/ElvosolarLogo.png',
        badge: '/templates/ElvosolarLogo.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'elvo-notification',
        data: { url: data.url || '/' }
    }));
});""",
    """self.addEventListener('push', e => {
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
});""",
    'sw.js: requireInteraction + renotify pre alerty')

print('=== VYSLEDKY ===')
for x in OK:
    print(' ', x)
for x in ERR:
    print(' !!', x)

# node syntax check sw.js
import subprocess, shutil
if shutil.which('node'):
    r = subprocess.run(['node', '--check', 'sw.js'], capture_output=True, text=True)
    print('sw.js syntax:', 'OK' if r.returncode == 0 else 'ERR: ' + r.stderr[:200])
