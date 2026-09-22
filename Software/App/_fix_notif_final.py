# -*- coding: utf-8 -*-
"""
Notifikacie fix (final):
1) PROFILE: vsetky fetch JSON volania dostali obrannu logiku — ak server vrati
   HTML (gate/PHP warning), zobraz citelnu hlasku namiesto "Unexpected token '<'"
2) DASHBOARD: zvoncek (bell) odstraneny z headera (user ho nechce)
3) PUSH REGISTRACIA: vzdy pri nacitani dashboardu sa SW registruje a subscription
   posle na server (uz robi) + pridane aj do PROFILE stranky (aby prihlaseny user
   na profile prihlasil push) — double safety
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


# ============================================================
# 1) PROFILE: bezpecny JSON fetch helper + jeho pouzitie
# ============================================================
p2 = 'templates/profile.html'
h = read(p2)

rep(p2, """        let userProfile = {};""",
   """        let userProfile = {};

        // Bezpecny JSON fetch — ak server vrati HTML (gate/warning), jasna hlaska
        async function safeJson(url, opts) {
            const res = await fetch(url, opts || { credentials: 'same-origin' });
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                if (text.indexOf('<') === 0) {
                    return { status: 'error', message: 'Server vrátil neočakávanú odpoveď (pravdepodobne vypršala relácia). Obnov stránku.' };
                }
                return { status: 'error', message: 'Neplatná odpoveď servera: ' + text.substring(0, 80) };
            }
        }

        // Push registracia aj na profile — subscription sa ulozi hned
        async function registerPushOnProfile() {
            try {
                if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
                const reg = await navigator.serviceWorker.register('/sw.js');
                await navigator.serviceWorker.ready;
                const vk = await safeJson('/api/push/vapid');
                if (!vk.pub) return;
                let sub = await reg.pushManager.getSubscription();
                if (!sub) {
                    const u8 = s => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
                    let key = vk.pub; while (key.length % 4) key += '=';
                    sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: u8(key).buffer });
                }
                await safeJson('/api/push/subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub.toJSON()) });
                localStorage.setItem('elvo_push_endpoint', sub.endpoint);
                console.log('[PUSH] subscribed (profile)');
            } catch (e) { console.log('[PUSH] profile err:', e.message); }
        }""",
   'profile: safeJson helper + push registracia')

# saveNotifications cez safeJson
rep(p2, """            try {
                await fetch('/api/user/notifications', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    credentials: 'same-origin',
                    body: JSON.stringify(settings)
                });
                showToast('Nastavenia uložené!');
            } catch(e) { showToast('Chyba: ' + e.message, true); }""",
   """            try {
                const d = await safeJson('/api/user/notifications', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    credentials: 'same-origin',
                    body: JSON.stringify(settings)
                });
                if (d.status === 'success') showToast('Nastavenia uložené!');
                else showToast(d.message || 'Uloženie zlyhalo', true);
            } catch(e) { showToast('Chyba: ' + e.message, true); }""",
   'profile: saveNotifications cez safeJson')

# loadUserNotifications cez safeJson
rep(p2, """                const r = await fetch('/api/user/notifications', { credentials: 'same-origin' });
                const d = await r.json();""",
   """                const d = await safeJson('/api/user/notifications');""",
   'profile: loadUserNotifications cez safeJson')

# testEmail cez safeJson
rep(p2, """                const r = await fetch('/api/user/test-email', { method: 'POST', credentials: 'same-origin' });
                const d = await r.json();""",
   """                const d = await safeJson('/api/user/test-email', { method: 'POST', credentials: 'same-origin' });""",
   'profile: testEmail cez safeJson')

# testPush cez safeJson
rep(p2, """                const r = await fetch('/api/push/test', { method: 'POST', credentials: 'same-origin' });
                const d = await r.json();""",
   """                const d = await safeJson('/api/push/test', { method: 'POST', credentials: 'same-origin' });""",
   'profile: testPush cez safeJson')

# loadDevices cez safeJson
rep(p2, """                const res = await fetch('/api/user/devices', { credentials: 'same-origin' });
                const data = await res.json();""",
   """                const data = await safeJson('/api/user/devices');""",
   'profile: loadDevices cez safeJson')

# Init volanie push registracie
rep(p2, """        loadAvatar();
        loadProfile();
        loadDevices();
        loadUserNotifications();
        if (window.lucide) lucide.createIcons();""",
   """        loadAvatar();
        loadProfile();
        loadDevices();
        loadUserNotifications();
        registerPushOnProfile();
        if (window.lucide) lucide.createIcons();""",
   'profile: init push registracia')

# ============================================================
# 2) DASHBOARD: zvoncek prec z headera
# ============================================================
p = 'templates/dashboard.html'
d = read(p)

bell_old = """                    <button id="alertsBtn" onclick="toggleAlertsPanel(event)" aria-label="Notifikácie" style="position:relative;display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:12px;background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.2);cursor:pointer;transition:all 0.2s;">
                        <i data-lucide="bell" style="width:18px;height:18px;color:var(--text-primary);"></i>
                    </button>"""
bell_new = """                    <!-- Zvoncek odstraneny — notifikacie chodia do systemovej listy -->"""
rep(p, bell_old, bell_new, 'dashboard: zvoncek prec z headera')

print('=== VYSLEDKY ===')
for x in OK:
    print(' ', x)
for x in ERR:
    print(' !!', x)

# JS validacia oboch
import subprocess, shutil
if shutil.which('node'):
    for f in ('templates/dashboard.html', 'templates/profile.html'):
        c = read(f)
        import re
        blocks = re.findall(r'<script[^>]*>(.*?)</script>', c, re.S)
        js = '\n'.join(blocks)
        js = re.sub(r'<\?php.*?\?>', 'X', js, flags=re.S)
        tmp = f.replace('/', '_') + '.js'
        io.open(tmp, 'w', encoding='utf-8').write(js)
        r = subprocess.run(['node', '--check', tmp], capture_output=True, text=True)
        print(f, 'JS:', 'OK' if r.returncode == 0 else 'ERR: ' + r.stderr[:150])
        io.open(tmp, 'w').write('')
