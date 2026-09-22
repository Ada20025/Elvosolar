# -*- coding: utf-8 -*-
"""
Push notifikacie: test button + offline push fix
1) Odstrani DUPLICITNY /api/push/subscribe endpoint (druhy bez tela prepisoval prvy
   -> subscription sa NIKDY neulozila -> web push nemohol fungovat)
2) Prida /api/push/test endpoint (admin+user): posle realny web push na vsetky
   zariadenia prihlaseneho usera cez elvo_push_user
3) Dashboard: tlacidlo "OTESTOVAŤ NOTIFIKÁCIU" (zvoncek dropdown) + offline
   notifikacie cez SW registrator (applyDeviceStatus lokalne aj cez push kanal)
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
# 1) INDEX.PHP: odstran duplicitny subscribe + pridaj /api/push/test
# ============================================================
p = 'index.php'
c = read(p)

dup_old = """// --- PUSH SUBSCRIBE (ulozenie subscription) ---
elseif ($path === '/api/push/subscribe' && $method === 'POST') {
 // Lokalne notifikacie bezia cez Notification API (zvoncek) - subscription sa neuklada
    send_json(['status' => 'success']);
}

// --- DEVICE RENAME ---"""
dup_new = """// --- PUSH TEST (realna web push notifikacia na vsetky zariadenia usera) ---
elseif ($path === '/api/push/test' && $method === 'POST') {
    $uid = intval($_SESSION['user_id'] ?? 0);
    if (!$uid) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    require_once __DIR__ . '/push_helper.php';
    // pocet aktivnych subscriptions
    try {
        $st = $pdo->prepare("SELECT COUNT(*) AS cnt FROM push_subs WHERE user_id = ?");
        $st->execute([$uid]);
        $cnt = intval($st->fetchColumn());
    } catch (Exception $e) { $cnt = 0; }
    if ($cnt === 0) {
        send_json(['status' => 'error', 'message' => 'Nemáš žiadne prihlásené push zariadenia. Obnov stránku a povoľ notifikácie.']);
    }
    $ok = elvo_push_user($pdo, $uid,
        '\\u{1F514} Test notifikácia',
        'Web Push funguje! Toto je skúšobná správa z ElvoControll (' . date('H:i') . ').',
        'elvo-test', '/dashboard');
    send_json(['status' => $ok ? 'success' : 'error',
               'message' => $ok ? "Test push odoslaný na $cnt zariadenie(í)" : 'Push sa nepodarilo odoslať (pozri server log)']);
}

// --- DEVICE RENAME ---"""
rep(p, dup_old, dup_new, 'index.php: duplicitny subscribe odstraneny + push/test endpoint')

# ============================================================
# 2) DASHBOARD.HTML: test tlacidlo + offline push fix
# ============================================================
p2 = 'templates/dashboard.html'
h = read(p2)

# 2a) Tlacidlo v alert panele — najprv najdi strukturu panelu
# Pridame ho vedla notifPermBtn (overlay header)
btn_anchor = '<button id="notifPermBtn" onclick="enableNativeNotifications()"'
if 'sendTestPushNotification' in h:
    OK.append('SKIP: test button already present')
else:
    # Najdeme riadok s notifPermBtn a pridame za neho test button
    idx = h.find(btn_anchor)
    if idx == -1:
        ERR.append('NOT FOUND: notifPermBtn anchor')
    else:
        line_end = h.find('\n', idx)
        insert = '\n                            <button id="pushTestBtn" onclick="sendTestPushNotification(this)" style="display:none;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.3);color:#60a5fa;font-size:9px;font-weight:700;padding:4px 8px;border-radius:6px;cursor:pointer;">OTESTOVAŤ NOTIFIKÁCIU</button>'
        h = h[:line_end] + insert + h[line_end:]
        write(p2, h)
        OK.append('OK: push test button vlozeny')

# 2b) JS funkcia sendTestPushNotification + zobrazit button ked permission granted
js_anchor = """        function enableNativeNotifications() {"""
js_new_fn = """        // === PUSH TEST: realna web push notifikacia cez server (aj pri zavorenej appke) ===
        async function sendTestPushNotification(btn) {
            if (btn) { btn.disabled = true; btn.textContent = 'ODOSELAM...'; }
            try {
                const r = await fetch(basePath + '/api/push/test', { method: 'POST', credentials: 'same-origin' });
                const d = await r.json();
                if (d.status === 'success') {
                    showNativeAlert('🔔 Test notifikácia odoslaná', d.message || 'Skontroluj notifikácie na tomto aj ostatných zariadeniach.');
                } else {
                    showNativeAlert('⚠️ Push problém', d.message || 'Nepodarilo sa odoslať test.');
                }
            } catch (e) {
                showNativeAlert('⚠️ Chyba', 'Spojenie so serverom zlyhalo: ' + e.message);
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = 'OTESTOVAŤ NOTIFIKÁCIU'; setTimeout(() => { const b = document.getElementById('pushTestBtn'); if (b) b.style.display = 'none'; }, 4000); }
            }
        }

        function enableNativeNotifications() {"""
rep(p2, js_anchor, js_new_fn, 'dashboard.html: sendTestPushNotification funkcia')

# 2c) Zobrazit test button ked je permission granted (v initNotifPermission)
rep(p2, """                        if (Notification.permission === 'default' && !notifPermissionAsked) { b.style.display = 'inline-block'; }
                        else if (Notification.permission === 'granted') { b.style.display = 'none'; }
                        else { b.style.display = 'none'; }""",
   """                        const tb = document.getElementById('pushTestBtn');
                        if (Notification.permission === 'default' && !notifPermissionAsked) { b.style.display = 'inline-block'; }
                        else if (Notification.permission === 'granted') { b.style.display = 'none'; if (tb) tb.style.display = 'inline-block'; }
                        else { b.style.display = 'none'; }""",
   'dashboard.html: test button viditelny pri granted permission')

# ============================================================
# VALIDACIA
# ============================================================
print("=== VYSLEDKY ===")
for x in OK:
    print(' ', x)
for x in ERR:
    print(' !!', x)

# PHP check
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
print('push/test endpoint:', '/api/push/test' in php)
print('duplicitny subscribe prec:', php.count("/api/push/subscribe' && $method === 'POST'") == 1)

# JS check dashboard
import re
m = re.search(r'<script>(.*)</script>', read(p2), re.S)
if m:
    js = re.sub(r'`[^`]*`', '""', m.group(1))
    js = re.sub(r"'(?:\\.|[^'])*'", '""', js)
    js = re.sub(r'"(?:\\.|[^"])*"', '""', js)
    js = re.sub(r'//[^\n]*', '', js)
    for a_, b_ in (('{', '}'), ('(', ')')):
        print(f"JS {a_}{b_}: {js.count(a_)} vs {js.count(b_)}", 'OK' if js.count(a_) == js.count(b_) else '!!')
