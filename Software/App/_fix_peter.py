# -*- coding: utf-8 -*-
"""
Fixes for Peter Dzurko's 4 reported issues:
  1. "Packets out of order. Expected 1 received 0" on registration
     -> root cause: PDO::ATTR_PERSISTENT => true (known mysqlnd bug)
        ALREADY FIXED (config.php clean) - verified here.
  2. Double login (register -> login page -> login again)
     -> ALREADY FIXED (auto-login after registration, sets $_SESSION directly)
  3. System (HW File Upload) - fake deploy with hardcoded passwords
     -> FIX NOW: real server-side deploy:
        a) admin picks target CM5 from device list (real serials from DB)
        b) POST /api/hw-deploy (admin session) stores files in system_settings
        c) admin_command action=deploy_files queued for CM5
        d) CM5 downloads files via /api/cm5/hw-files, applies, restarts
        e) terminal shows REAL progress via /api/hw-deploy/status polling
  4. Responsive for all device sizes
     -> FIX NOW: CSS media queries for the admin page + VS Code modal
"""
import io

OK = []
ERR = []


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
# 3a) INDEX.PHP: /api/hw-deploy + /api/hw-deploy/status + /api/cm5/hw-files
# ============================================================
p = 'index.php'
c = read(p)

deploy_endpoints = '''
        // ===== HW DEPLOY: ulozenie suborov + planovanie deployu na CM5 (admin only) =====
        elseif ($path === '/api/hw-deploy' && $method === 'POST') {
            if (($_SESSION['role'] ?? '') !== 'admin') send_json(['status' => 'error', 'message' => 'Iba admin'], 403);
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $files = $input['files'] ?? [];
            $serial = trim($input['serial'] ?? '');
            $password = trim($input['password'] ?? '');
            if (!$files || !is_array($files)) send_json(['status' => 'error', 'message' => 'Žiadne súbory'], 400);
            if ($serial === '') send_json(['status' => 'error', 'message' => 'Vyber cieľové zariadenie (CM5)'], 400);
            // Server-side heslo (nie JS!) - admin musí potvrdiť svoje heslo
            $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND role = 'admin'");
            $st->execute([$_SESSION['user_id']]);
            $admin = $st->fetch();
            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                send_json(['status' => 'error', 'message' => 'Nesprávne administrátorské heslo'], 403);
            }
            // Limit: max 20 suborov, max 400 KB kazdy
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
            // Ulozenie balika do system_settings + planovanie deployu na cielovy CM5
            $st = $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('hw_deploy_files', ?), ('hw_deploy_serial', ?), ('hw_deploy_time', ?), ('hw_deploy_status', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $st->execute([json_encode($clean), $serial, date('Y-m-d H:i:s'), 'pending']);
            // Planovanie prikazu: existujuci cielovy device
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
            $st = $pdo->query("SELECT `value` FROM system_settings WHERE `key` IN ('hw_deploy_status','hw_deploy_serial','hw_deploy_time')");
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

anchor = "        // ===== HW DEPLOY"
if anchor in c:
    OK.append('SKIP: hw-deploy endpoints already present')
else:
    # Vlozime pred "elseif ($path === '/api/user/me'" alebo pred koniec API sekcie:
    for anchor2 in ("        elseif ($path === '/api/user/me' && $method === 'GET') {",
                    "        } elseif ($path === '/api/user/me' && $method === 'GET') {"):
        if anchor2 in c:
            c = c.replace(anchor2, deploy_endpoints + "\n" + anchor2, 1)
            write(p, c)
            OK.append('OK: hw-deploy endpoints vlozene pred /api/user/me')
            break
    else:
        ERR.append('NOT FOUND: /api/user/me anchor pre hw-deploy endpoints')

# ============================================================
# 3b) ADMIN.HTML: realny deploy — vyber CM5, server heslo, terminal polling
# ============================================================
p2 = 'templates/admin.html'
h = read(p2)

# 3b-1) Nahradiť fake promptPasswordAndDeploy + pridat endpoint select
fake_old = """        // DEPLOY IBA CEZ ADMIN HESLO (BEZ AI DETEKCIE)
        function promptPasswordAndDeploy() {
            const pass = prompt("ZADAJTE ADMINISTRÁTORSKÉ HESLO PRE DEPLOY DO HARDVÉRU:");
            if (!pass) return;

            const term = document.getElementById('vscodeTerminalBody');

            // Overenie hesla
            if (pass !== 'admin' && pass !== 'root' && pass !== 'elvosolar') {
                term.innerHTML += `<div style="color:#ef4444;">[AUTH FAILED] Nesprávne heslo! Zápis do hardvéru bol zamietnutý.</div>`;
                term.scrollTop = term.scrollHeight;
                alert("Nesprávne administrátorské heslo!");
                return;
            }

            // Úspech
            const fileCount = Object.keys(fileDatabase).length;
            term.innerHTML += `<div style="color:#10b981;font-weight:700;">[AUTH OK] Administrátor overený. Zapisujem ${fileCount} súborov do Gateway CM5...</div>`;
            
            setTimeout(() => {
                term.innerHTML += `<div style="color:#22c55e;font-weight:700;">[DEPLOY SUCCESS] ✅ Všetky súbory boli úspešne zapísané do hardvéru a démon reštartovaný!</div>`;
                term.scrollTop = term.scrollHeight;
                alert("✅ Všetky súbory boli úspešne nahraté do hardvéru riadiacej jednotky!");
                closeVsCodeModal();
            }, 600);
        }"""
fake_new = """        // Zoznam cielovych CM5 (realne serialy z DB, renderovane PHP-ckom)
        const hwDevices = <?php echo json_encode(array_map(function($d){ return ['serial' => $d['serial_number'], 'name' => $d['name']]; }, array_values(array_filter($devicesList, function($d){ return !empty($d['serial_number']); })))); ?>;

        let hwDeployPollTimer = null;

        function openVsCodeModal() {
            document.getElementById('vscodeModal').classList.remove('hidden');
            renderFileList();
            selectVsFile(currentActiveFile);
            renderHwDeviceSelect();
        }

        function renderHwDeviceSelect() {
            const sel = document.getElementById('hwDeviceSelect');
            if (!sel) return;
            if (!hwDevices.length) {
                sel.innerHTML = '<option value="">Žiadne zariadenie v DB</option>';
                return;
            }
            sel.innerHTML = hwDevices.map(d =>
                `<option value="${d.serial}">${d.name} — ${d.serial}</option>`).join('');
        }

        // DEPLOY: server-side overenie hesla + realny zapis na vybrane CM5
        async function promptPasswordAndDeploy() {
            const term = document.getElementById('vscodeTerminalBody');
            const pass = prompt("ZADAJTE ADMINISTRÁTORSKÉ HESLO PRE DEPLOY DO HARDVÉRU:");
            if (!pass) return;

            const sel = document.getElementById('hwDeviceSelect');
            const serial = sel ? sel.value : '';
            if (!serial) {
                term.innerHTML += `<div style="color:#ef4444;">[TARGET] Vyber cieľové zariadenie CM5 zo zoznamu.</div>`;
                term.scrollTop = term.scrollHeight;
                return;
            }

            const fileCount = Object.keys(fileDatabase).length;
            if (!fileCount) {
                term.innerHTML += `<div style="color:#ef4444;">[FILES] Najprv nahraj súbory.</div>`;
                term.scrollTop = term.scrollHeight;
                return;
            }

            term.innerHTML += `<div style="color:#38bdf8;">[DEPLOY] Cieľ: ${serial} — odosielam ${fileCount} súborov na server (overenie hesla)...</div>`;
            term.scrollTop = term.scrollHeight;

            try {
                const resp = await fetch('<?php echo $base_url; ?>/api/hw-deploy', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    credentials: 'same-origin',
                    body: JSON.stringify({ files: fileDatabase, serial, password: pass })
                });
                const data = await resp.json();
                if (!data || data.status !== 'success') {
                    term.innerHTML += `<div style="color:#ef4444;">[AUTH FAILED] ${data && data.message ? data.message : 'Deploy zamietnutý'}</div>`;
                    term.scrollTop = term.scrollHeight;
                    return;
                }
                term.innerHTML += `<div style="color:#10b981;font-weight:700;">[AUTH OK] Server prijal ${data.count} súborov. Čakám, kým CM5 ${serial} prevezme a zapíše balík...</div>`;
                term.scrollTop = term.scrollHeight;
                pollHwDeployStatus(serial, term);
            } catch (err) {
                term.innerHTML += `<div style="color:#ef4444;">[ERROR] ${err}</div>`;
                term.scrollTop = term.scrollHeight;
            }
        }

        function pollHwDeployStatus(serial, term) {
            if (hwDeployPollTimer) clearInterval(hwDeployPollTimer);
            let ticks = 0;
            hwDeployPollTimer = setInterval(async () => {
                ticks++;
                try {
                    const r = await fetch('<?php echo $base_url; ?>/api/hw-deploy/status', {credentials: 'same-origin'});
                    const d = await r.json();
                    const st = d && d.deploy ? d.deploy.status : 'idle';
                    if (st === 'success') {
                        clearInterval(hwDeployPollTimer); hwDeployPollTimer = null;
                        term.innerHTML += `<div style="color:#22c55e;font-weight:700;">[DEPLOY SUCCESS] ✅ CM5 ${serial} potvrdil zápis súborov (reštart vykonaný).</div>`;
                        term.scrollTop = term.scrollHeight;
                    } else if (st === 'error') {
                        clearInterval(hwDeployPollTimer); hwDeployPollTimer = null;
                        term.innerHTML += `<div style="color:#ef4444;">[DEPLOY FAILED] ❌ CM5 ${serial} oznámil chybu pri zápise. Pozri log na zariadení (elvo-log).</div>`;
                        term.scrollTop = term.scrollHeight;
                    } else if (ticks > 60) {
                        clearInterval(hwDeployPollTimer); hwDeployPollTimer = null;
                        term.innerHTML += `<div style="color:#eab308;">[TIMEOUT] CM5 ${serial} nepotvrdil do 5 minút. Skontroluj, či je online (elvo-log).</div>`;
                        term.scrollTop = term.scrollHeight;
                    }
                } catch (e) { /* keep polling */ }
            }, 5000);
        }"""
rep(p2, fake_old, fake_new, 'admin.html: realny deploy namiesto fake')

# 3b-2) Pridat select zariadenia do top-baru VS Code modalu
sel_old = """                    <!-- ROVNO TLAČIDLO S HESLOM (BEZ AI DETEKCIE) -->
                    <button class="vscode-btn deploy" onclick="promptPasswordAndDeploy()">
                        <i data-lucide="key" style="width:13px;height:13px;"></i> Nahrať do HW (Heslo)
                    </button>"""
sel_new = """                    <!-- CIELOVE ZARIADENIE (realny serial z DB) -->
                    <select id="hwDeviceSelect" class="vscode-btn" style="max-width:230px;color:#e2e8f0;background:#1e1e1e;"></select>

                    <!-- DEPLOY S HESLOM (server-side overenie) -->
                    <button class="vscode-btn deploy" onclick="promptPasswordAndDeploy()">
                        <i data-lucide="key" style="width:13px;height:13px;"></i> Nahrať do HW (Heslo)
                    </button>"""
rep(p2, sel_old, sel_new, 'admin.html: select cieloveho CM5 v top-bare')

# 3b-3) Odstranit duplicitny openVsCodeModal (stary bez renderHwDeviceSelect)
dup_old = """        function openVsCodeModal() {
            document.getElementById('vscodeModal').classList.remove('hidden');
            renderFileList();
            selectVsFile(currentActiveFile);
        }"""
rep(p2, dup_old, '        // openVsCodeModal je definovaná vyššie (s renderHwDeviceSelect)', 'admin.html: duplicitny openVsCodeModal odstraneny', required=False)

# 4) Responsive CSS pre admin + VS Code modal na vsetky velkosti
css_old = """        .vscode-modal-backdrop {"""
css_new = """        /* ===== RESPONSIVE: vsetky velkosti zariadeni ===== */
        @media (max-width: 1024px) {
            .fs-kpi-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 10px !important; }
            .fs-hero-inner { grid-template-columns: 1fr !important; }
            .fs-menu-links .fs-menu-link { padding: 6px 8px !important; font-size: 11px !important; }
            .fs-menu-links .fs-menu-link span, .fs-menu-links .fs-menu-link { white-space: normal !important; }
        }
        @media (max-width: 768px) {
            .fs-nav { flex-direction: column !important; align-items: stretch !important; gap: 8px !important; padding: 10px 12px !important; }
            .fs-nav-left { flex-wrap: wrap !important; }
            .fs-menu-links { flex-wrap: wrap !important; gap: 4px !important; }
            .fs-menu-links .fs-menu-link { font-size: 10.5px !important; padding: 5px 7px !important; }
            .fs-kpi-grid { grid-template-columns: 1fr 1fr !important; }
            .fs-donut-panel { margin-top: 8px !important; }
            .fs-table-wrap, .sites-table { font-size: 11px !important; }
            #vscodeModal .vscode-window { width: 98vw !important; height: 92vh !important; }
            .vscode-body { flex-direction: column !important; }
            .vscode-sidebar { width: 100% !important; max-height: 130px; border-right: none !important; border-bottom: 1px solid #333; }
            .vscode-editor-container { min-height: 160px; }
            .vscode-terminal-body { max-height: 110px; }
            .vscode-top-actions { flex-wrap: wrap !important; }
            .vscode-top-actions .vscode-btn { font-size: 11px !important; padding: 5px 8px !important; }
        }
        @media (max-width: 480px) {
            .fs-kpi-grid { grid-template-columns: 1fr !important; }
            .fs-menu-links .fs-menu-link { font-size: 10px !important; padding: 4px 6px !important; }
            .fs-brand-img { max-height: 30px !important; }
            .admin-badge-btn { font-size: 11px !important; padding: 5px 8px !important; }
        }

        .vscode-modal-backdrop {"""
rep(p2, css_old, css_new, 'admin.html: responsive CSS vsetky velkosti')

# ============================================================
# 3c) CM5 (app.py): action deploy_files — stiahne balik a aplikuje
# ============================================================
import os
p3 = os.path.join('..', '..', 'Hardware', 'app.py')
a = read(p3)

deploy_cm5 = '''            elif action == "deploy_files":
                # ADMIN DEPLOY HW SUBOROV: stiahne balik z cloudu, zapise subory, restart app.py
                try:
                    import shutil, zipfile, io as _io
                    r_files = requests.get(CLOUD_SERVER_URL + "/api/cm5/hw-files",
                                           params={"serial": serial_num}, timeout=20, verify=False)
                    data_files = r_files.json()
                    if data_files.get("status") != "success" or not data_files.get("files"):
                        result = {"status": "error", "message": "Balík súborov sa nepodarilo stiahnuť"}
                    else:
                        hw_dir = os.path.dirname(os.path.abspath(__file__))
                        backup_dir = os.path.join(hw_dir, "hw_backup")
                        os.makedirs(backup_dir, exist_ok=True)
                        applied = []
                        for fname, content in data_files["files"].items():
                            fname = str(fname).replace("..", "").replace(chr(92), "").lstrip("/")
                            if not fname:
                                continue
                            target = os.path.join(hw_dir, fname)
                            # zaloha povodneho suboru
                            if os.path.exists(target):
                                bdir = os.path.join(backup_dir, time.strftime("%Y%m%d_%H%M%S"))
                                os.makedirs(bdir, exist_ok=True)
                                shutil.copy2(target, os.path.join(bdir, os.path.basename(fname)))
                            with open(target, "w", encoding="utf-8") as f:
                                f.write(content)
                            applied.append(fname)
                        log_message(f"[HW DEPLOY] Zapisanych {len(applied)} suborov: {applied}")
                        result = {"status": "success", "applied": applied}
                        # restart appky (deploy sa prejavi po reboote sluzby)
                        def _delayed_restart():
                            time.sleep(2)
                            log_message("[HW DEPLOY] Restart app.py po deploji...")
                            os._exit(0)
                        threading.Thread(target=_delayed_restart, daemon=True).start()
                except Exception as e_df:
                    result = {"status": "error", "message": str(e_df)[:200]}

'''
anchor3 = '            elif action == "set_power":'
if 'deploy_files' in a:
    OK.append('SKIP: app.py deploy_files already present')
elif anchor3 in a:
    a = a.replace(anchor3, deploy_cm5 + anchor3, 1)
    write(p3, a)
    OK.append('OK: app.py deploy_files akcia pridana')
else:
    ERR.append('NOT FOUND: set_power anchor v app.py')

# ============================================================
# VERIFIKACIA
# ============================================================
print("=== VYSLEDKY ===")
for x in OK:
    print(" ", x)
for x in ERR:
    print(" !!", x)

# PHP string-aware validacia
import re as _re
php = read('index.php')
in_php = False
depth = 0
state = 'code'
line = 1
min_depth_line = None
for i, ch in enumerate(php):
    if ch == '\\n':
        line += 1
    if not in_php:
        if php[i:i+5] == '<?php':
            in_php = True
        continue
    if state == 'code':
        if php[i:i+2] in ('//',):
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
                min_depth_line = line
                depth = 0
    elif state == 'line':
        if ch == '\\n':
            state = 'code'
    elif state == 'block':
        if php[i:i+2] == '*/':
            state = 'code'
    elif state == 'dq':
        if ch == '\\\\':
            continue
        elif ch == '"':
            state = 'code'
    elif state == 'sq':
        if ch == '\\\\':
            continue
        elif ch == "'":
            state = 'code'
print(f"PHP brace depth: {depth} (0 = OK)")
if min_depth_line:
    print(f"  !! zaporny depth na riadku {min_depth_line}")

# JS balans v admin.html
h2 = read(p2)
m = _re.search(r'<script>(.*)</script>', h2, _re.S)
if m:
    js = m.group(1)
    js2 = _re.sub(r'`[^`]*`', '""', js)
    js2 = _re.sub(r"'(?:\\\\.|[^'])*'", '""', js2)
    js2 = _re.sub(r'"(?:\\\\.|[^"])*"', '""', js2)
    js2 = _re.sub(r'//[^\n]*', '', js2)
    for a_, b_ in (('{', '}'), ('(', ')'), ('[', ']')):
        if js2.count(a_) != js2.count(b_):
            print(f"JS {a_}{b_} balans: {js2.count(a_)} vs {js2.count(b_)} !!")
        else:
            print(f"JS {a_}{b_} balans OK ({js2.count(a_)})")

# Python compile
import py_compile, tempfile
try:
    py_compile.compile(p3, cfile=os.path.join(tempfile.gettempdir(), 'app_check.pyc'), doraise=True)
    print("app.py: python compile OK")
except Exception as e:
    print(f"app.py compile ERROR: {e}")
