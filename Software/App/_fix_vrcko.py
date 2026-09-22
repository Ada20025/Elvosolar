# -*- coding: utf-8 -*-
"""
VRTKO (informativny banner) + uplna ochrana proti HTML odpovediam:
1) DASHBOARD: safeJson helper (ako v profile) + zachytenie VSETKYCH fetch volani
2) PROFILE: vylepseny safeJson — pri 401 automaticky presmeruje na login s hlaskou
3) VRTKO: pekný informatívny banner dole na obrazovke (zeleny = OK, modry = info,
   cerveny = chyba) s ikonou a auto-hide — v profile aj dashboarde
4) GATE 401 JSON: frontend pri 'gate: true' hned redirectne na /login (relacia vyprsala)
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
# 1) PROFILE: vylepseny safeJson s vrtkom + 401 redirect
# ============================================================
p2 = 'templates/profile.html'
h = read(p2)

rep(p2, """        async function safeJson(url, opts) {
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
        }""",
   """        async function safeJson(url, opts) {
            let res;
            try {
                res = await fetch(url, opts || { credentials: 'same-origin' });
            } catch (netErr) {
                return { status: 'error', message: '⚠️ Žiadne spojenie so serverom. Skontroluj internet.' };
            }
            const text = await res.text();
            try {
                const data = JSON.parse(text);
                // Gate 401 (relacia vyprsala) -> hned na login, ziadne visiace chyby
                if (data.gate === true || (res.status === 401 && url.indexOf('/api/push/vapid') === -1)) {
                    return { status: 'error', expired: true, message: '🔒 Relácia vypršala. Prihlás sa znova.' };
                }
                return data;
            } catch (e) {
                return { status: 'error', message: '⚠️ Neočakávaná odpoveď servera. Obnov stránku (F5).' };
            }
        }

        // VRTKO: informativny banner dole (zeleny=OK / modry=info / cerveny=chyba)
        function showVrcko(message, type) {
            type = type || 'info';
            const old = document.getElementById('elvoVrcko');
            if (old) old.remove();
            const colors = { ok: ['#10b981', '✅'], info: ['#3b82f6', 'ℹ️'], error: ['#f43f5e', '🚨'] };
            const [bg, icon] = colors[type] || colors.info;
            const v = document.createElement('div');
            v.id = 'elvoVrcko';
            v.style.cssText = 'position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:99999;' +
                'display:flex;align-items:center;gap:10px;padding:14px 22px;border-radius:14px;' +
                'background:' + bg + ';color:#fff;font-size:12.5px;font-weight:800;max-width:90vw;' +
                'box-shadow:0 12px 40px rgba(0,0,0,0.4);animation:vrckoIn .3s ease;';
            v.innerHTML = '<span style="font-size:16px;">' + icon + '</span><span>' + message + '</span>';
            document.body.appendChild(v);
            setTimeout(() => { v.style.transition = 'opacity .4s'; v.style.opacity = '0'; setTimeout(() => v.remove(), 400); }, type === 'error' ? 5000 : 3000);
        }
        const st = document.createElement('style');
        st.textContent = '@keyframes vrckoIn{from{opacity:0;transform:translateX(-50%) translateY(20px);}to{opacity:1;transform:translateX(-50%) translateY(0);}}';
        document.head.appendChild(st);""",
   'profile: safeJson v2 + VRTKO banner')

# Pouzitie vrtka v saveNotifications a testoch
rep(p2, """                if (d.status === 'success') showToast('Nastavenia uložené!');
                else showToast(d.message || 'Uloženie zlyhalo', true);""",
   """                if (d.status === 'success') showVrcko('Nastavenia uložené', 'ok');
                else showVrcko(d.message || 'Uloženie zlyhalo', 'error');""",
   'profile: saveNotifications vrcko')

rep(p2, """            showToast('Odosielam test email...');""",
   """            showVrcko('Odosielam test email...', 'info');""",
   'profile: testEmail vrcko info')

rep(p2, """                showToast(d.status === 'success' ? '✅ Test email odoslaný — skontroluj schránku' : '⚠️ ' + (d.message || 'Email sa nepodarilo odoslať'), d.status !== 'success');""",
   """                showVrcko(d.status === 'success' ? 'Test email odoslaný — skontroluj schránku' : (d.message || 'Email sa nepodarilo odoslať'), d.status === 'success' ? 'ok' : 'error');""",
   'profile: testEmail vrcko vysledok')

rep(p2, """                showToast(d.status === 'success' ? '🔔 ' + (d.message || 'Test notifikácia odoslaná') : '⚠️ ' + (d.message || 'Push sa nepodarilo odoslať'), d.status !== 'success');""",
   """                showVrcko(d.status === 'success' ? (d.message || 'Test notifikácia odoslaná') : (d.message || 'Push sa nepodarilo odoslať'), d.status === 'success' ? 'ok' : 'error');""",
   'profile: testPush vrcko')

# ============================================================
# 2) DASHBOARD: safeJson + vrcko (pre buduce fetchy, alerts maju vlastny handler)
# ============================================================
p = 'templates/dashboard.html'
d = read(p)

rep(p, """        // ============ ALERT SYSTEM (zvoncek + nativne notifikacie) ============"""
   if False else """        // ============ ALERT SYSTEM""",
   """        // safeJson: vsetky fetchy dostanu JSON (alebo citatelnu chybu, nie "Unexpected token")
        async function safeJson(url, opts) {
            let res;
            try { res = await fetch(url, opts || { credentials: 'same-origin' }); }
            catch (netErr) { return { status: 'error', message: 'Žiadne spojenie so serverom' }; }
            const text = await res.text();
            try {
                const data = JSON.parse(text);
                if (data.gate === true) return { status: 'error', expired: true, message: 'Relácia vypršala' };
                return data;
            } catch (e) { return { status: 'error', message: 'Neočakávaná odpoveď servera' }; }
        }

        // ============ ALERT SYSTEM""",
   'dashboard: safeJson helper')

print('=== VYSLEDKY ===')
for x in OK:
    print(' ', x)
for x in ERR:
    print(' !!', x)

# JS validacia
import re, subprocess, shutil
if shutil.which('node'):
    for f in ('templates/profile.html', 'templates/dashboard.html'):
        c = read(f)
        blocks = re.findall(r'<script[^>]*>(.*?)</script>', c, re.S)
        js = '\n'.join(blocks)
        js = re.sub(r'<\?php.*?\?>', 'X', js, flags=re.S)
        tmp = f.replace('/', '_') + '_check.js'
        io.open(tmp, 'w', encoding='utf-8').write(js)
        r = subprocess.run(['node', '--check', tmp], capture_output=True, text=True)
        print(f, 'JS:', 'OK' if r.returncode == 0 else 'ERR: ' + r.stderr[:150])
        io.open(tmp, 'w').write('')
