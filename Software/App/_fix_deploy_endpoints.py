# -*- coding: utf-8 -*-
"""Vlozi hw-deploy endpointy do index.php (pred /api/cm5/register)."""
import io, os

OK, ERR = [], []


def read(p):
    return io.open(p, 'r', encoding='utf-8').read()


def write(p, c):
    io.open(p, 'w', encoding='utf-8', newline='').write(c)


p = 'index.php'
c = read(p)

if '/api/hw-deploy' in c:
    print('SKIP: hw-deploy endpoints already in index.php')
else:
    deploy_endpoints = '''        // ===== HW DEPLOY: ulozenie suborov + planovanie deployu na CM5 (admin only) =====
        elseif ($path === '/api/hw-deploy' && $method === 'POST') {
            if (($_SESSION['role'] ?? '') !== 'admin') send_json(['status' => 'error', 'message' => 'Iba admin'], 403);
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $files = $input['files'] ?? [];
            $serial = trim($input['serial'] ?? '');
            $password = trim($input['password'] ?? '');
            if (!$files || !is_array($files)) send_json(['status' => 'error', 'message' => 'Žiadne súbory'], 400);
            if ($serial === '') send_json(['status' => 'error', 'message' => 'Vyber cieľové zariadenie (CM5)'], 400);
            // Server-side heslo (nie JS!) - admin musi potvrdit svoje heslo
            $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND role = 'admin'");
            $st->execute([$_SESSION['user_id']]);
            $adminRow = $st->fetch();
            if (!$adminRow || !password_verify($password, $adminRow['password_hash'])) {
                send_json(['status' => 'error', 'message' => 'Nesprávne administrátorské heslo'], 403);
            }
            // Limit: max 20 suborov, max 400 KB kazdy, 2 MB spolu
            $clean = [];
            $totalBytes = 0;
            foreach ($files as $name => $content) {
                if (!is_string($name) || !is_string($content)) continue;
                $name = str_replace(['..', '\\\\', chr(0)], '', $name);
                if (strlen($content) > 400 * 1024) send_json(['status' => 'error', 'message' => "Súbor $name je príliš veľký (>400 KB)"], 400);
                $totalBytes += strlen($content);
                $clean[$name] = $content;
                if (count($clean) >= 20) break;
            }
            if (!$clean) send_json(['status' => 'error', 'message' => 'Žiadne platné súbory'], 400);
            if ($totalBytes > 2 * 1024 * 1024) send_json(['status' => 'error', 'message' => 'Spolu príliš veľa dát (>2 MB)'], 400);
            // Ulozenie balika + planovanie prikazu pre cielovy CM5
            $st = $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('hw_deploy_files', ?), ('hw_deploy_serial', ?), ('hw_deploy_time', ?), ('hw_deploy_status', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $st->execute([json_encode($clean), $serial, date('Y-m-d H:i:s'), 'pending']);
            $st = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ? LIMIT 1");
            $st->execute([$serial]);
            $dev = $st->fetch();
            if (!$dev) send_json(['status' => 'error', 'message' => "Zariadenie $serial neexistuje v databáze"], 404);
            $payload = json_encode(['action' => 'deploy_files', 'count' => count($clean)]);
            $st = $pdo->prepare("UPDATE devices SET admin_command = ? WHERE id = ?");
            $st->execute([$payload, $dev['id']]);
            send_json(['status' => 'success', 'message' => 'Deploy naplánovaný', 'device_id' => intval($dev['id']), 'count' => count($clean)]);
        }

        // ===== HW DEPLOY STATUS (admin polling - realny stav z CM5 result) =====
        elseif ($path === '/api/hw-deploy/status' && $method === 'GET') {
            if (($_SESSION['role'] ?? '') !== 'admin') send_json(['status' => 'error', 'message' => 'Iba admin'], 403);
            $st = $pdo->query("SELECT `key`, `value` FROM system_settings WHERE `key` IN ('hw_deploy_status','hw_deploy_serial','hw_deploy_time')");
            $out = ['status' => 'idle', 'serial' => '', 'time' => ''];
            foreach ($st->fetchAll() as $row) {
                if ($row['key'] === 'hw_deploy_status') $out['status'] = $row['value'];
                elseif ($row['key'] === 'hw_deploy_serial') $out['serial'] = $row['value'];
                elseif ($row['key'] === 'hw_deploy_time') $out['time'] = $row['value'];
            }
            send_json(['status' => 'success', 'deploy' => $out]);
        }

        // ===== CM5: stiahnutie HW suborov (poll nachystany balik) =====
        elseif ($path === '/api/cm5/hw-files' && $method === 'GET') {
            $serial = trim($_GET['serial'] ?? '');
            if ($serial === '') send_json(['status' => 'error', 'message' => 'Chýba serial'], 400);
            $st = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'hw_deploy_files' LIMIT 1");
            $row = $st->fetch();
            if (!$row) send_json(['status' => 'error', 'message' => 'Žiadny deploy pripravený'], 404);
            $files = json_decode($row['value'], true);
            if (!is_array($files) || !$files) send_json(['status' => 'error', 'message' => 'Balík je prázdny'], 404);
            send_json(['status' => 'success', 'serial' => $serial, 'files' => $files]);
        }

'''
    # system_settings stlpce: key/value (overene). Vlozime pred /api/cm5/register
    anchor = "elseif ($path === '/api/cm5/register' && $method === 'POST') {"
    for a_ in (anchor, anchor.replace("elseif (", "        elseif (")):
        if a_ in c:
            c = c.replace(a_, deploy_endpoints + a_, 1)
            write(p, c)
            print('OK: hw-deploy endpointy vlozene pred /api/cm5/register')
            break
    else:
        ERR.append('anchor /api/cm5/register not found')

for x in OK:
    print(' ', x)
for x in ERR:
    print(' !!', x)

# PHP validacia
php = read(p)
in_php = False
depth = 0
state = 'code'
for i, ch in enumerate(php):
    if not in_php:
        if php[i:i+5] == '<?php':
            in_php = True
        continue
    if state == 'code':
        if php[i:i+2] == '//':
            state = 'line'
        elif php[i:i+2] == '/*':
            state = 'block'
        elif ch == '"':
            state = 'dq'
        elif ch == "'":
            state = 'sq'
        elif ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth < 0:
                print(f'  !! zaporny depth okolo offsetu {i}')
                depth = 0
    elif state == 'line':
        if ch == '\\n':
            state = 'code'
    elif state == 'block':
        if php[i:i+2] == '*/':
            state = 'code'
    elif state == 'dq':
        if ch == '\\\\':
            state = 'esc'
        elif ch == '"':
            state = 'code'
    elif state == 'esc':
        state = 'dq'
    elif state == 'sq':
        if ch == '\\\\':
            state = 'esc2'
        elif ch == "'":
            state = 'code'
    elif state == 'esc2':
        state = 'sq'
print(f'PHP brace depth: {depth} (0 = OK)')
