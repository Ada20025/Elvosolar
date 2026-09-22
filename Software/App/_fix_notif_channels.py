# -*- coding: utf-8 -*-
"""
Per-channel notification preferences (email / push / nic / oboje)
- user_prefs teraz uklada aj kanaly: notif_email, notif_push (per user, globalne)
- Alert dispatch v index.php: posle EMAIL len ak notif_email, PUSH len ak notif_push
- Profile UI: v notifikacnej karte su 2 globalne prepinace (Email / Push) +
  test tlacidla pre oba kanaly
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
# 1) INDEX.PHP — notifikacne endpointy + alert dispatch
# ============================================================
p = 'index.php'
c = read(p)

# 1a) GET notifications: defaults + kanaly
rep(p, """    $defaults = ['new_device' => true, 'error' => true, 'daily_report' => false, 'negative_price' => true];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (user_id INT PRIMARY KEY, prefs TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $stmt = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $saved = json_decode($row['prefs'], true);
            if (is_array($saved)) $defaults = array_merge($defaults, $saved);
        }
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success', 'notifications' => $defaults]);""",
   """    $defaults = ['new_device' => true, 'error' => true, 'daily_report' => false, 'negative_price' => true, 'notif_email' => true, 'notif_push' => true];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (user_id INT PRIMARY KEY, prefs TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $stmt = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $saved = json_decode($row['prefs'], true);
            if (is_array($saved)) $defaults = array_merge($defaults, $saved);
        }
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success', 'notifications' => $defaults]);""",
   'GET notifications: kanaly v defaults')

# 1b) POST notifications: uloz aj kanaly
rep(p, """    $clean = [
        'new_device' => !empty($data['new_device']),
        'error' => !empty($data['error']),
        'daily_report' => !empty($data['daily_report']),
        'negative_price' => !empty($data['negative_price']),
    ];""",
   """    $clean = [
        'new_device' => !empty($data['new_device']),
        'error' => !empty($data['error']),
        'daily_report' => !empty($data['daily_report']),
        'negative_price' => !empty($data['negative_price']),
        'notif_email' => !empty($data['notif_email']),
        'notif_push' => !empty($data['notif_push']),
    ];""",
   'POST notifications: uloz kanaly')

# ============================================================
# 2) Alert dispatch — respektuj kanaly
# ============================================================
rep(p, """                    // Email pri NOVOM alerte (nie pri kazdom opakovani)
                    if ($isNew && $notify_email) {""",
   """                    // Email/push pri NOVOM alerte (nie pri kazdom opakovani) — podla kanalov usera
                    $devPrefs = ['notif_email' => true, 'notif_push' => true];
                    try {
                        $pfStmt = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = (SELECT user_id FROM devices WHERE id = ?)");
                        $pfStmt->execute([$did]);
                        $pfRow = $pfStmt->fetch();
                        if ($pfRow) {
                            $pf = json_decode($pfRow['prefs'], true);
                            if (is_array($pf)) {
                                $devPrefs['notif_email'] = array_key_exists('notif_email', $pf) ? !empty($pf['notif_email']) : true;
                                $devPrefs['notif_push'] = array_key_exists('notif_push', $pf) ? !empty($pf['notif_push']) : true;
                            }
                        }
                    } catch (Exception $e) { /* default both on */ }
                    if ($isNew && ($devPrefs['notif_email'] || $devPrefs['notif_push'])) {""",
   'alert dispatch: nacitaj kanaly usera')

# 2a) Push branch — podmienka
rep(p, """                                try {
                                    $uidStmt = $pdo->prepare("SELECT user_id FROM devices WHERE id = ?");
                                    $uidStmt->execute([$did]);
                                    $alertUid = intval($uidStmt->fetchColumn());
                                    if ($alertUid) {""",
   """                                try {
                                    $uidStmt = $pdo->prepare("SELECT user_id FROM devices WHERE id = ?");
                                    $uidStmt->execute([$did]);
                                    $alertUid = intval($uidStmt->fetchColumn());
                                    if ($alertUid && $devPrefs['notif_push']) {""",
   'push branch: podmienka notif_push')

# 2b) Email branch — podmienka
rep(p, """                                } catch (Exception $eP) { /* ignore */ }
                                require_once __DIR__ . '/mail_helper.php';
                                $sevIcon = ($a[2] === 'crit') ? '🚨' : '⚠️';""",
   """                                } catch (Exception $eP) { /* ignore */ }
                                if (!$devPrefs['notif_email']) {
                                    // user nepozeli email kanal — preskoc odoslanie
                                } else {
                                require_once __DIR__ . '/mail_helper.php';
                                $sevIcon = ($a[2] === 'crit') ? '🚨' : '⚠️';""",
   'email branch: podmienka notif_email')

# 2c) Uzavretie else bloku email branch
rep(p, """                                    '<p style="margin:0;font-size:13px;color:#cbd5e1;">Otvor dashboard pre detaily a stav zariadenia.</p>',
                                    $sevColor);
                            }""",
   """                                    '<p style="margin:0;font-size:13px;color:#cbd5e1;">Otvor dashboard pre detaily a stav zariadenia.</p>',
                                    $sevColor);
                                }
                            }""",
   'email branch: else uzavretie')

# ============================================================
# 3) PROFILE.HTML — kanalove prepinace + test tlacidla
# ============================================================
p2 = 'templates/profile.html'
h = read(p2)

# 3a) Pridaj 2 globalne kanalove prepinace pred kartu "Email notifikacie"
rep(p2, """                <!-- Email notifikácie -->
                <div class="card" style="grid-column:1/-1;">""",
   """                <!-- KANÁLY: Email / Push -->
                <div class="card" style="grid-column:1/-1;">
                    <span class="card-title"><i data-lucide="bell-ring" style="width:14px;height:14px;color:#38bdf8;"></i> Spôsob doručenia</span>
                    <p style="font-size:10px;color:var(--text-muted);margin:4px 0 12px 0;">Vyber, ako ti majú chodiť upozornenia — emailom, notifikáciou do zariadenia, obojím, alebo ničím.</p>
                    <div class="notif-item">
                        <div>
                            <span style="font-size:12px;font-weight:700;color:var(--text-primary);display:block;">📧 Email</span>
                            <span style="font-size:10px;color:var(--text-muted);">Upozornenia prídu na tvoj e-mail</span>
                        </div>
                        <div class="toggle-track on" id="trackNotifEmail" onclick="toggleNotif(this, 'notifEmail')"><div class="toggle-knob"></div></div>
                        <input type="checkbox" id="notifEmail" checked style="display:none;">
                    </div>
                    <div class="notif-item">
                        <div>
                            <span style="font-size:12px;font-weight:700;color:var(--text-primary);display:block;">🔔 Notifikácie do zariadenia (Push)</span>
                            <span style="font-size:10px;color:var(--text-muted);">Systémové notifikácie — chodia aj keď je appka zatvorená</span>
                        </div>
                        <div class="toggle-track on" id="trackNotifPush" onclick="toggleNotif(this, 'notifPush')"><div class="toggle-knob"></div></div>
                        <input type="checkbox" id="notifPush" checked style="display:none;">
                    </div>
                    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
                        <button onclick="testEmailChannel()" class="btn-primary" style="flex:1;min-width:140px;padding:10px;">Otestovať email</button>
                        <button onclick="testPushChannel()" class="btn-primary" style="flex:1;min-width:140px;padding:10px;background:linear-gradient(135deg,#2563eb,#0891b2);">Otestovať notifikáciu</button>
                    </div>
                </div>

                <!-- Email notifikácie -->
                <div class="card" style="grid-column:1/-1;">""",
   'profile.html: kanalova karta')

# 3b) JS: saveNotifications rozsireny + load + test funkcie
rep(p2, """        async function saveNotifications() {
            const settings = {
                new_device: document.getElementById('notifNewDevice').checked,
                error: document.getElementById('notifError').checked,
                daily_report: document.getElementById('notifDaily').checked,
                negative_price: document.getElementById('notifNegPrice').checked,
            };""",
   """        async function saveNotifications() {
            const settings = {
                new_device: document.getElementById('notifNewDevice').checked,
                error: document.getElementById('notifError').checked,
                daily_report: document.getElementById('notifDaily').checked,
                negative_price: document.getElementById('notifNegPrice').checked,
                notif_email: document.getElementById('notifEmail').checked,
                notif_push: document.getElementById('notifPush').checked,
            };""",
   'profile.html: saveNotifications s kanalmi')

# 3c) JS: load nastaveni (loadUserNotifications) — pridaj po userProfile deklaracii
rep(p2, """        let userProfile = {};

        // Toggle notification""",
   """        let userProfile = {};

        // Nahraj ulozene notifikacne nastavenia (vratane kanalov)
        async function loadUserNotifications() {
            try {
                const r = await fetch('/api/user/notifications', { credentials: 'same-origin' });
                const d = await r.json();
                if (d.status === 'success') {
                    const n = d.notifications;
                    const map = { notifNewDevice: 'new_device', notifError: 'error', notifDaily: 'daily_report', notifNegPrice: 'negative_price', notifEmail: 'notif_email', notifPush: 'notif_push' };
                    for (const [cbId, key] of Object.entries(map)) {
                        const cb = document.getElementById(cbId);
                        if (!cb) continue;
                        cb.checked = !!n[key];
                        const tr = document.getElementById('track' + cbId.replace('notif', 'Notif'));
                        if (tr) tr.className = 'toggle-track ' + (cb.checked ? 'on' : 'off');
                    }
                }
            } catch (e) { /* defaulty */ }
        }

        // Test email kanalu
        async function testEmailChannel() {
            showToast('Odosielam test email...');
            try {
                const r = await fetch('/api/user/test-email', { method: 'POST', credentials: 'same-origin' });
                const d = await r.json();
                showToast(d.status === 'success' ? '✅ Test email odoslaný — skontroluj schránku' : '⚠️ ' + (d.message || 'Email sa nepodarilo odoslať'), d.status !== 'success');
            } catch (e) { showToast('Chyba: ' + e.message, true); }
        }

        // Test push kanalu
        async function testPushChannel() {
            try {
                const r = await fetch('/api/push/test', { method: 'POST', credentials: 'same-origin' });
                const d = await r.json();
                showToast(d.status === 'success' ? '🔔 ' + (d.message || 'Test notifikácia odoslaná') : '⚠️ ' + (d.message || 'Push sa nepodarilo odoslať'), d.status !== 'success');
            } catch (e) { showToast('Chyba: ' + e.message, true); }
        }

        // Toggle notification""",
   'profile.html: loadUserNotifications + test funkcie')

# ============================================================
# VALIDACIA
# ============================================================
print('=== VYSLEDKY ===')
for x in OK:
    print(' ', x)
for x in ERR:
    print(' !!', x)

php = read(p)
in_php = False; depth = 0; state = 'code'
for i, ch in enumerate(php):
    if not in_php:
        if php[i:i+5] == '<?php': in_php = True
        continue
    if state == 'code':
        if php[i:i+2] == '//': state = 'line'
        elif php[i:i+2] == '/*': state = 'block'
        elif ch == '"': state = 'dq'
        elif ch == chr(39): state = 'sq'
        elif ch == '{': depth += 1
        elif ch == '}': depth -= 1
    elif state == 'line':
        if ch == chr(10): state = 'code'
    elif state == 'block':
        if php[i:i+2] == '*/': state = 'code'
    elif state == 'dq':
        if ch == chr(92): state = 'e1'
        elif ch == '"': state = 'code'
    elif state == 'e1': state = 'dq'
    elif state == 'sq':
        if ch == chr(92): state = 'e2'
        elif ch == chr(39): state = 'code'
    elif state == 'e2': state = 'sq'
print('PHP brace depth:', depth)

import re
m = re.search(r'<script>(.*)</script>', read(p2), re.S)
if m:
    js = re.sub(r'`[^`]*`', 'S', m.group(1))
    js = re.sub(r"'(?:\\.|[^'])*'", 'S', js)
    js = re.sub(r'"(?:\\.|[^"])*"', 'S', js)
    js = re.sub(r'//[^\n]*', '', js)
    for a_, b_ in (('{', '}'), ('(', ')')):
        print(f"JS {a_}{b_}: {js.count(a_)} vs {js.count(b_)}", 'OK' if js.count(a_) == js.count(b_) else '!!')
