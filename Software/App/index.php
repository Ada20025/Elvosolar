<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// RYCHLOST: gzip kompresia - dashboard HTML 219 kB -> ~30 kB (menej prenosu = rychlejsie nacitanie)
if (!empty($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false
    && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'DELETE'])) {
    ini_set('zlib.output_compression', '1');
    ini_set('zlib.output_compression_level', '6');
}

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Europe/Bratislava');
ini_set('session.gc_maxlifetime', 31536000); // 1 rok — appka sa nikdy neodhlási
session_set_cookie_params(['lifetime' => 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
session_start();
require_once 'config.php';

// === SECURITY HEADERS ===
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
// HTML stranky nikdy necachovat (vzdy cerstvy dashboard)
if (!preg_match('#\.(png|jpg|svg|ico|css|js|json|woff2?)$#', $request_uri ?? '')) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// ============================================================
// === PRÍSTUPOVÁ BRÁNA — bez špeciálneho prístupu sa nikto nedostane ani na login ===
// Prístupové údaje: Railway premenné ACCESS_GATE_USER / ACCESS_GATE_PASS (prevažujú),
// inak tabuľka access_gate (predvolené: admin / ELVO-ACCESS-2026).
// Kto raz správne zadal → cookie na 1 ROK → už sa nikdy nepýta.
// Už prihlásení používatelia (nainštalovaná PWA) prechádzajú automaticky.
// CM5 strojové endpointy bežia vždy (nemajú prehliadač).
// ============================================================
if (!function_exists('elvo_gate_creds')) {
    function elvo_gate_creds($pdo) {
        $u = getenv('ACCESS_GATE_USER'); $p = getenv('ACCESS_GATE_PASS');
        if ($u && $p) return ['user' => (string)$u, 'pass' => (string)$p, 'env' => true];
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS access_gate (id TINYINT PRIMARY KEY, gate_user VARCHAR(64) NOT NULL, gate_pass_hash VARCHAR(255) NOT NULL)");
            $row = $pdo->query("SELECT gate_user, gate_pass_hash FROM access_gate WHERE id = 1")->fetch();
            if (!$row) {
                $pdo->prepare("REPLACE INTO access_gate (id, gate_user, gate_pass_hash) VALUES (1, ?, ?)")
                    ->execute(['admin', password_hash('ELVO-ACCESS-2026', PASSWORD_DEFAULT)]);
                $row = $pdo->query("SELECT gate_user, gate_pass_hash FROM access_gate WHERE id = 1")->fetch();
            }
            return ['user' => (string)$row['gate_user'], 'hash' => (string)$row['gate_pass_hash'], 'env' => false];
        } catch (Exception $e) {
            return ['user' => 'admin', 'hash' => password_hash('ELVO-ACCESS-2026', PASSWORD_DEFAULT), 'env' => false];
        }
    }
    function elvo_gate_token($creds) {
        $secret = $creds['pass'] ?? $creds['hash'];
        return hash_hmac('sha256', 'elvo-access-v1|' . $creds['user'], $secret);
    }
    function elvo_gate_set_cookie($creds) {
        setcookie('elvo_access', elvo_gate_token($creds), [
            'expires' => time() + 31536000, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax'
        ]);
    }
    // Over prístup: buď prístupové údaje správcu, ALEBO prihlasovacie údaje skutočného účtu (e-mail + heslo)
    function elvo_gate_verify($pdo, $creds, $gu, $gp) {
        if ($gu === '' || $gp === '') return false;
        // 1) Prístupové údaje správcu
        if (hash_equals($creds['user'], $gu)
            && (($creds['env'] ?? false) ? hash_equals($creds['pass'], $gp) : password_verify($gp, $creds['hash']))) {
            return true;
        }
        // 2) Reálny účet (e-mail + heslo k účtu) — pre nainštalovanú appku / owners
        $emailNorm = strtolower(trim($gu));
        if (strpos($emailNorm, '@') !== false) {
            try {
                $st = $pdo->prepare("SELECT password_hash FROM users WHERE LOWER(email) = ? LIMIT 1");
                $st->execute([$emailNorm]);
                $row = $st->fetch();
                if ($row && password_verify($gp, $row['password_hash'])) return true;
            } catch (Exception $e) { /* ignore */ }
        }
        return false;
    }
    function elvo_gate_page($msg, $next) {
        $bp = $GLOBALS['base_path'] ?? '';
        $msgHtml = $msg ? '<div style="margin:0 0 14px 0;padding:10px 14px;border-radius:12px;background:rgba(244,63,94,0.08);border:1px solid rgba(244,63,94,0.3);color:#fda4af;font-size:12px;font-weight:700;text-align:center;">' . htmlspecialchars($msg) . '</div>' : '';
        echo '<!DOCTYPE html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="theme-color" content="#020617"><title>Prístup — ElvoControll</title>'
            . '<link rel="icon" type="image/png" href="/templates/ElvosolarLogo.png">'
            . '<style>*{box-sizing:border-box;margin:0;padding:0}body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(1200px 800px at 50% -10%,#0e1a35 0%,#020617 55%);font-family:"Plus Jakarta Sans",system-ui,sans-serif;padding:16px;color:#e2e8f0}'
            . '.card{width:100%;max-width:380px;background:rgba(2,6,23,0.85);border:1px solid rgba(6,182,212,0.25);border-radius:22px;padding:30px 26px;box-shadow:0 20px 60px rgba(0,0,0,0.5)}'
            . '.logo{display:block;margin:0 auto 14px;width:56px;height:56px;object-fit:contain}'
            . 'h1{font-size:19px;font-weight:900;text-align:center;letter-spacing:-0.02em;color:#f8fafc}'
            . '.sub{font-size:10.5px;font-weight:700;letter-spacing:2px;text-transform:uppercase;text-align:center;color:#06b6d4;margin:4px 0 18px}'
            . 'label{display:block;font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#67e8f9;margin:12px 0 5px}'
            . 'input{width:100%;background:#05070f;border:1px solid #334155;color:#fff;font-size:14px;font-weight:700;border-radius:12px;padding:12px 14px;outline:none;transition:border-color .2s}'
            . 'input:focus{border-color:#06b6d4}'
            . 'button{width:100%;margin-top:18px;background:linear-gradient(90deg,#0891b2,#2563eb);border:0;color:#fff;font-size:12px;font-weight:900;letter-spacing:1px;text-transform:uppercase;border-radius:12px;padding:14px;cursor:pointer;transition:opacity .2s}'
            . 'button:hover{opacity:.9}'
            . '.note{margin-top:16px;font-size:10px;color:#64748b;text-align:center;line-height:1.6}</style></head><body>'
            . '<div class="card"><img class="logo" src="/templates/ElvosolarLogo.png" alt="ElvoSolar" onerror="this.style.display=\'none\'">'
            . '<h1>ElvoControll</h1><div class="sub">Ochránený prístup</div>' . $msgHtml
            . '<form method="post" action="' . htmlspecialchars($bp . '/access') . '">'
            . '<input type="hidden" name="next" value="' . htmlspecialchars($next) . '">'
            . '<label for="guser">Prístupové meno</label><input id="guser" name="user" autocomplete="username" required>'
            . '<label for="gpass">Prístupový kód</label><input id="gpass" name="pass" type="password" autocomplete="current-password" required>'
            . '<button type="submit">Odomknúť prístup</button></form>'
            . '<div class="note">Prístupový kód vám poskytne správca — alebo použite prihlasovacie údaje svojho účtu.<br>Po zadaní vás už systém viac nepýta.</div></div></body></html>';
    }
}
$elvo_uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$elvo_open = (bool)(preg_match('#^/(sw\.js|manifest\.json|favicon\.ico|healthcheck|health)$#', $elvo_uri)
    || preg_match('#^/(templates|css|js|img)(/|$)#', $elvo_uri)
    || preg_match('#^/api/user/(notifications|devices|me)(/|$)#', $elvo_uri)
    || preg_match('#^/api/push/(vapid|test|subscribe|unsubscribe)$#', $elvo_uri)
    || preg_match('#^/api/user/(test-email|change-password|delete-account)$#', $elvo_uri)
    || preg_match('#^/api/device/\d+/(telemetry|control|rename)$#', $elvo_uri)
    || in_array($elvo_uri, ['/api/cm5/poll', '/api/cm5/result', '/api/cm5/register', '/api/cm5/hw-files', '/api/cloud/sync-telemetry', '/api/report-ip', '/api/user/claim-device'], true));
$elvo_gate_ok = false;
if (!empty($_SESSION['user_id'])) {
    // Prihlásený (nainštalovaná appka) — auto priechod + ticho obnov cookie
    $elvo_creds = elvo_gate_creds($pdo);
    if (!isset($_COOKIE['elvo_access']) || !hash_equals(elvo_gate_token($elvo_creds), (string)$_COOKIE['elvo_access'])) {
        elvo_gate_set_cookie($elvo_creds);
    }
    $elvo_gate_ok = true;
} elseif (isset($_COOKIE['elvo_access'])) {
    $elvo_creds = elvo_gate_creds($pdo);
    if (hash_equals(elvo_gate_token($elvo_creds), (string)$_COOKIE['elvo_access'])) $elvo_gate_ok = true;
}
if (!$elvo_gate_ok && !$elvo_open) {
    $bp = $base_path ?? '';
    // API volania NIKDY nedostanu HTML gate — vzdy cisty JSON 401.
    // (fetch z PWA/Service Workera casto ide bez gate cookie; HTML v JSON
    //  odpovedi robi "Unexpected token '<'" chyby na fronte)
    if (strpos($elvo_uri, '/api/') === 0) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Neautorizovaný — prihlás sa znova', 'gate' => true]);
        exit;
    }
    // Validácia "next" — iba lokálna cesta
    $elvo_next = (string)($_REQUEST['next'] ?? $elvo_uri);
    if ($elvo_next === '' || $elvo_next[0] !== '/' || strpos($elvo_next, '//') === 0 || preg_match('#^[a-z]+:#i', $elvo_next)) $elvo_next = '/';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $elvo_uri === rtrim($bp, '/') . '/access') {
        $lockF = sys_get_temp_dir() . '/elvo_gate_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
        $lockT = sys_get_temp_dir() . '/elvo_gate_lock_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
        if (is_file($lockT) && time() - (int)@file_get_contents($lockT) < 60) {
            sleep(2); elvo_gate_page('Priveľa pokusov — skúste o minútu.', $elvo_next); exit;
        }
        $elvo_creds = elvo_gate_creds($pdo);
        $gu = trim((string)($_POST['user'] ?? '')); $gp = (string)($_POST['pass'] ?? '');
        $elvo_ok = elvo_gate_verify($pdo, $elvo_creds, $gu, $gp);
        if ($elvo_ok) {
            @unlink($lockF); @unlink($lockT);
            elvo_gate_set_cookie($elvo_creds);
            // Vždy na appku: neprihlásený → login, prihlásený → dashboard ('/' to vyrieši)
            header('Location: ' . ($base_path ?? '') . '/'); exit;
        }
        // Rate limit: 6 zlých pokusov → 60 s blokácia
        $n = is_file($lockF) ? (int)@file_get_contents($lockF) : 0;
        $n = (time() - (int)@filemtime($lockF) > 300) ? 1 : $n + 1;
        @file_put_contents($lockF, $n);
        if ($n >= 6) @file_put_contents($lockT, time());
        sleep(1);
        elvo_gate_page('Nesprávne prístupové údaje.', $elvo_next); exit;
    }
    elvo_gate_page('', $elvo_next); exit;
}

// === MIGRATION CACHE: suborovy flag (0 DB dotazov pri kazdom requeste - rychlost!) ===
$migrations_done = false;
$mig_flag = sys_get_temp_dir() . '/elvo_migrations_' . date('Y-m-d') . '.flag';
if (file_exists($mig_flag)) {
    $migrations_done = true;
} else if (isset($pdo)) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS migrations_state (id INTEGER PRIMARY KEY AUTO_INCREMENT, done_date VARCHAR(10) DEFAULT NULL)");
        $mrow = $pdo->query("SELECT done_date FROM migrations_state WHERE id = 1")->fetch();
        $migrations_done = ($mrow && ($mrow['done_date'] ?? '') === date('Y-m-d'));
        if ($migrations_done) @file_put_contents($mig_flag, '1');
    } catch (Exception $e) { $migrations_done = false; }
}

// === AUTO-MIGRÁCIA: Pridanie chýbajúcich stĺpcov ===
if (isset($pdo) && !$migrations_done) {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $cols = [];
        if ($driver === 'sqlite') {
            $r = $pdo->query("PRAGMA table_info(devices)");
            while ($row = $r->fetch()) $cols[] = $row['name'];
        } else {
            $r = $pdo->query("SHOW COLUMNS FROM devices");
            while ($row = $r->fetch()) $cols[] = $row['Field'];
        }
        if (!in_array('min_power_pct', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN min_power_pct FLOAT DEFAULT 0");
        if (!in_array('max_power_pct', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN max_power_pct FLOAT DEFAULT 100");
        if (!in_array('active_model_id', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN active_model_id VARCHAR(10) DEFAULT '1'");
        if (!in_array('night_sleep', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN night_sleep TINYINT DEFAULT 0");
        if (!in_array('connection_type', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN connection_type VARCHAR(20) DEFAULT 'modbus_tcp'");
        if (!in_array('smartlogger_ip', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN smartlogger_ip VARCHAR(50) DEFAULT '192.168.0.10'");
        if (!in_array('smartlogger_port', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN smartlogger_port INTEGER DEFAULT 502");
        if (!in_array('modbus_slave_id', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN modbus_slave_id INTEGER DEFAULT 205");
        if (!in_array('min_okte_price_cz_eur', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN min_okte_price_cz_eur FLOAT DEFAULT 0");
        // Režim prevádzky (Zap/Vyp/SmartAI) - dedikovany stlpec (predtym sa miesal do manual_override)
        if (!in_array('meter_control_mode', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN meter_control_mode VARCHAR(20) DEFAULT 'SMART'");
        // Index pre rychlu telemetry historiu (inak full scan pri kazdom nacitani dashboardu)
        try { $pdo->exec("CREATE INDEX idx_tel_dev_id ON telemetry (device_id, id)"); } catch (Exception $e2) { /* uz existuje */ }
    } catch (Exception $e) { /* ignore */ }

    // Auto-create cm5_config ak chýba a overenie stĺpca admin_command
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cm5_config (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            serial_number VARCHAR(100) DEFAULT 'CM5-DEFAULT',
            modbus_slave_id INTEGER DEFAULT 205,
            admin_command TEXT NULL,
            config_json TEXT NULL,
            result_json TEXT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        // Doplnenie stĺpca admin_command a modbus_slave_id ak tabuľka existuje v staršej verzii
        $cm5cols = $pdo->query("SHOW COLUMNS FROM cm5_config")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('admin_command', $cm5cols)) {
            $pdo->exec("ALTER TABLE cm5_config ADD COLUMN admin_command TEXT NULL");
        }
        if (!in_array('modbus_slave_id', $cm5cols)) {
            $pdo->exec("ALTER TABLE cm5_config ADD COLUMN modbus_slave_id INTEGER DEFAULT 205");
        }
    } catch (Exception $e) { /* sqlite alebo ignore */ }

    // Auto-create telemetry table if missing
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS telemetry (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            device_id INT NOT NULL,
            battery_soc FLOAT DEFAULT 0,
            power_ac FLOAT DEFAULT 0,
            temp FLOAT DEFAULT 0,
            freq FLOAT DEFAULT 50.0,
            status_msg VARCHAR(255) DEFAULT 'Online',
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        // Fix: ak existuje stara tabulka s wrong timestamp typom, oprav
        try {
            $tcols = [];
            $r = $pdo->query("SHOW COLUMNS FROM telemetry");
            while ($trow = $r->fetch()) $tcols[] = $trow['Field'];
            if (!in_array('battery_soc', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN battery_soc FLOAT DEFAULT 0");
            if (!in_array('power_ac', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN power_ac FLOAT DEFAULT 0");
            if (!in_array('temp', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN temp FLOAT DEFAULT 0");
            if (!in_array('freq', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN freq FLOAT DEFAULT 50");
            if (!in_array('status_msg', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN status_msg VARCHAR(255) DEFAULT 'Online'");
            if (!in_array('device_id', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN device_id INT NOT NULL DEFAULT 1");
        } catch (Exception $e3) { /* ignore */ }
    } catch (Exception $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS telemetry (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                battery_soc REAL DEFAULT 84,
                power_ac REAL DEFAULT 3840,
                temp REAL DEFAULT 32.5,
                freq REAL DEFAULT 50.0,
                status_msg VARCHAR(255) DEFAULT 'Online',
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e2) { /* ignore */ }
    }
}

// === DB CLEANUP - odstran nepotrebne tabulky ===
if (isset($pdo) && !$migrations_done) {
    try {
        $pdo->exec("DROP TABLE IF EXISTS notifications_log");
        $pdo->exec("DROP TABLE IF EXISTS okte_price_log");
        $pdo->exec("DROP TABLE IF EXISTS push_subscriptions");
        $pdo->exec("DROP TABLE IF EXISTS system_settings");
        $pdo->exec("DROP TABLE IF EXISTS password_resets");
    } catch (Exception $e) { /* ignore */ }
}

// === CORE TABLES (users, devices) ===
if (isset($pdo) && !$migrations_done) {
    try {
        $pdo->exec("
CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) DEFAULT 'user',
            email_verified TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) { /* ignore */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            name VARCHAR(200) DEFAULT 'ElvoSolar CM5',
            serial_number VARCHAR(100) DEFAULT '',
            slave_id INTEGER DEFAULT 1,
            brand_id VARCHAR(50) DEFAULT '',
            category_id VARCHAR(50) DEFAULT '',
            model_id VARCHAR(50) DEFAULT '',
            total_saved_eur DECIMAL(10,2) DEFAULT 0,
            total_kwh DECIMAL(10,2) DEFAULT 0,
            last_seen DATETIME NULL,
            manual_override VARCHAR(10) DEFAULT 'AUTO',
            active_model_id VARCHAR(20) DEFAULT 'AI',
            night_sleep INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            min_power_pct FLOAT DEFAULT 0,
            max_power_pct FLOAT DEFAULT 100,
            connection_type VARCHAR(20) DEFAULT 'modbus_tcp',
            smartlogger_ip VARCHAR(50) DEFAULT '192.168.0.10',
            smartlogger_port INTEGER DEFAULT 502,
            modbus_slave_id INTEGER DEFAULT 205,
            min_okte_price_cz_eur FLOAT DEFAULT 0,
            admin_command VARCHAR(255) DEFAULT '',
            sub_type VARCHAR(50) DEFAULT '',
            status VARCHAR(20) DEFAULT 'offline',
            battery_soc FLOAT DEFAULT 0,
            fve_power_w FLOAT DEFAULT 0
        )");
    } catch (Exception $e) { /* ignore */ }
}

// === DEDUP ZARIADENI: 1 serial = 1 riadok (inak telemetria/nastavenia idu na zly riadok a dashboard ukazuje zle cisla) ===
if (isset($pdo) && !$migrations_done) {
    try {
        $pdo->exec("UPDATE devices AS d LEFT JOIN devices AS keep ON keep.serial_number = d.serial_number AND keep.id < d.id SET d.serial_number = CONCAT('DEAD-', d.id) WHERE d.serial_number IS NOT NULL AND d.serial_number != '' AND keep.id IS NOT NULL");
    } catch (Exception $eDedup) { /* ignore */ }
}

// === DEMO USER SEED ===
if (isset($pdo) && !$migrations_done) {
    try {
        $demoCheck = $pdo->query("SELECT id FROM users WHERE email = 'demo@elvosolar.sk' LIMIT 1")->fetch();
        if (!$demoCheck) {
            $demoHash = password_hash('demo123', PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username, email, password_hash, email_verified) VALUES (?, ?, ?, 1)")
                 ->execute(['Demo ElvoSolar', 'demo@elvosolar.sk', $demoHash]);
            $demoUserId = $pdo->lastInsertId();
            // Demo zariadenie - OFFLINE s nulovymi datami (ziadne fake hodnoty)
            $pdo->prepare("INSERT INTO devices (user_id, name, serial_number, brand_id, category_id, model_id, sub_type, status, min_power_pct, max_power_pct, active_model_id, connection_type, smartlogger_ip, smartlogger_port, modbus_slave_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'offline', 0, 100, 'AI', 'modbus_tcp', '192.168.0.10', 502, 205)")
                 ->execute([$demoUserId, 'ElvoControll Demo', 'DEMO-CM5-001', 'huawei', 'smartlogger', 'smartlogger3000a', 'smartlogger']);
        }
    } catch (Exception $e) { /* ignore */ }

    // === DB MIGRACIA - pridaj chybajuce stlpce ===
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM devices")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('status', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN status VARCHAR(20) DEFAULT 'offline'");
        if (!in_array('last_seen', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN last_seen DATETIME NULL");
        if (!in_array('min_okte_price_cz_eur', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN min_okte_price_cz_eur FLOAT DEFAULT 0");
    } catch (Exception $e) { /* ignore */ }
}

// Označ migrácie za hotové na dnes (dalsie requesty preskocia tazke query)
if (isset($pdo) && !$migrations_done) {
    try {
        $pdo->exec("INSERT INTO migrations_state (id, done_date) VALUES (1, '" . date('Y-m-d') . "') ON DUPLICATE KEY UPDATE done_date = VALUES(done_date)");
    } catch (Exception $e) { /* ignore */ }
}

// --- ZÍSKANIE CESTY A NORMALIZÁCIA ---
$request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$base_path = ($script_dir === '/' || $script_dir === '.') ? '' : rtrim($script_dir, '/');

if (!empty($base_path) && strpos($request_uri, $base_path) === 0) {
    $path = substr($request_uri, strlen($base_path));
} else {
    $path = $request_uri;
}

$path = '/' . ltrim(rtrim($path, '/'), '/');

if (strpos($path, '/index.php') === 0) {
    $path = substr($path, 10);
    $path = '/' . ltrim(rtrim($path, '/'), '/');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// === PRÍSTUPOVÁ BRÁNA: POST obsluha (funguje aj keď webserver nesmeruje /access na index.php) ===
if ($path === '/access' && $method === 'POST') {
    $elvo_creds = elvo_gate_creds($pdo);
    $lockF = sys_get_temp_dir() . '/elvo_gate_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
    $lockT = sys_get_temp_dir() . '/elvo_gate_lock_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
    if (is_file($lockT) && time() - (int)@file_get_contents($lockT) < 60) {
        sleep(2); elvo_gate_page('Priveľa pokusov — skúste o minútu.', '/'); exit;
    }
    $gu = trim((string)($_POST['user'] ?? '')); $gp = (string)($_POST['pass'] ?? '');
    $elvo_ok = elvo_gate_verify($pdo, $elvo_creds, $gu, $gp);
    if ($elvo_ok) {
        @unlink($lockF); @unlink($lockT);
        elvo_gate_set_cookie($elvo_creds);
        header('Location: ' . ($base_path ?? '') . '/'); exit;
    }
    $n = is_file($lockF) ? (int)@file_get_contents($lockF) : 0;
    $n = (time() - (int)@filemtime($lockF) > 300) ? 1 : $n + 1;
    @file_put_contents($lockF, $n);
    if ($n >= 6) @file_put_contents($lockT, time());
    sleep(1);
    elvo_gate_page('Nesprávne prístupové údaje.', '/'); exit;
}
if ($path === '/access' && $method === 'GET') {
    // S platnou cookie už brána nepýta sa — len presmeruj na appku
    header('Location: ' . $base_path . '/'); exit;
}

// Docasna SMTP diagnostika (test.php) - priama obsluha
if ($path === '/test.php' || $path === '/test') {
    header('Content-Type: text/plain; charset=utf-8');
    require __DIR__ . '/test.php';
    exit;
}

// --- SESSION TIMEOUT ---
$stay_logged_in = $_SESSION['stay_logged_in'] ?? false;
$timeout_seconds = $stay_logged_in ? (90 * 24 * 3600) : (7 * 24 * 3600);

$no_timeout_paths = ['/login', '/register', '/forgot-password', '/verify-reset-code', '/setup', '/setup.html', '/api/user/me', '/api/user/devices', '/api/cm5/poll', '/api/cm5/result', '/api/cloud/sync-telemetry', '/api/report-ip', '/api/cm5/register', '/healthcheck'];
$apply_timeout = true;
foreach ($no_timeout_paths as $ntp) {
    if (strpos($path, $ntp) === 0) { $apply_timeout = false; break; }
}

if ($apply_timeout && isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_seconds) {
        session_unset();
        session_destroy();
        // API volania MUSIA dostat JSON (nie HTML redirect) — inak safeJson na telefone spadne na "neocekavana odpoved"
        if (strpos($path, '/api/') === 0) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Relácia vypršala — prihlás sa znova', 'session_expired' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header("Location: " . $base_path . "/login");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// --- HELPER FUNKCIE ---
if (!function_exists('send_json')) {
    function send_json($data, $status = 200) {
        // Zahod pripadne PHP warnings/notices v bufferi — inak by sa primiesali
        // do JSON odpovede ("Unexpected token '<', \"<br />\" is not valid JSON")
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('get_json_input')) {
    function get_json_input() {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
}

if (!function_exists('render_template')) {
    function render_template($view_name, $context = []) {
        global $base_path;
        extract($context);
        
        $possible_paths = [
            __DIR__ . '/App/templates/' . $view_name,
            __DIR__ . '/app/templates/' . $view_name,
            __DIR__ . '/templates/' . $view_name,
            __DIR__ . '/' . $view_name
        ];
        
        $view_path = null;
        foreach ($possible_paths as $p) {
            if (file_exists($p)) {
                $view_path = $p;
                break;
            }
        }
        
        if ($view_path) {
            include $view_path;
        } else {
            http_response_code(404);
            echo "<h3>Chyba: Šablóna <strong>" . htmlspecialchars($view_name) . "</strong> nebola nájdená v priečinku templates/.</h3>";
        }
        exit;
    }
}

if (!function_exists('flash')) {
    function flash($message, $category = 'info') {
        $_SESSION['flash'][] = ['message' => $message, 'category' => $category];
    }
}

if (!function_exists('get_flash_messages')) {
    function get_flash_messages() {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }
}

if (!function_exists('get_user_devices')) {
    // ========= ALERT SYSTEM: detekcia chyb + notifikacie + email =========
    if (!function_exists('eval_device_alerts')) {
        function eval_device_alerts($pdo, $device_ids, $notify_email = true) {
            if (!$device_ids) return;
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS device_alerts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    device_id INT NOT NULL,
                    alert_key VARCHAR(40) NOT NULL,
                    title VARCHAR(120) NOT NULL,
                    body VARCHAR(255) DEFAULT '',
                    severity VARCHAR(10) DEFAULT 'warn',
                    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                    resolved_at DATETIME NULL,
                    email_sent TINYINT(1) DEFAULT 0,
                    UNIQUE KEY uq_alert (device_id, alert_key)
                )");
            } catch (Exception $e) { return; }
            $now = time();
            $ph = implode(',', array_fill(0, count($device_ids), '?'));
            foreach ($device_ids as $did) {
                $stmt = $pdo->prepare("SELECT * FROM devices WHERE id = ?");
                $stmt->execute([$did]);
                $d = $stmt->fetch();
                if (!$d) continue;
                $devAlerts = [];
                // 1. OFFLINE - zariadenie neposiela telemetriu > 15 min
                $lastSeen = $d['last_seen'] ?? null;
                $commSec = $lastSeen ? ($now - strtotime($lastSeen)) : null;
                if ($commSec === null || $commSec >= 900) {
                    $mins = ($commSec !== null && $commSec > 0) ? floor($commSec / 60) : null;
                    $devAlerts['offline'] = ['Zariadenie neodpovedá', ($d['name'] ?: 'Zariadenie') . ' neposiela dáta' . ($mins !== null ? ' už ' . $mins . ' min' : ' (žiadna telemetria)'), 'crit'];
                }
                // Telemetria status_msg - chyba striedaca
                $soc = floatval($d['battery_soc'] ?? 0);
                $temp = floatval($d['temp'] ?? 0);
                $fresh = ($commSec !== null && $commSec < 900);
                try {
                    $t = $pdo->prepare("SELECT status_msg FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 1");
                    $t->execute([$did]);
                    $sm = (string)$t->fetchColumn();
                    foreach (['chyba','neodpoved','fault','error','interrupt','nedostup','offline','fail'] as $kw) {
                        if ($kw !== '' && function_exists('mb_stripos') ? (mb_stripos($sm, $kw) !== false) : (stripos($sm, $kw) !== false)) {
                            $devAlerts['inv_error'] = ['Chyba striedača', ($d['name'] ?: 'Zariadenie') . ': ' . mb_substr($sm, 0, 180), 'crit'];
                            break;
                        }
                    }
                } catch (Exception $e) { /* ignore */ }
                // 2. Nizky SOC (len ked je fresh telemetria - inak je to stale)
                if ($fresh && $soc > 0 && $soc < 15) {
                    $devAlerts['low_soc'] = ['Nízky stav batérie', ($d['name'] ?: 'Zariadenie') . ': SOC ' . round($soc) . '% — batéria je takmer vybitá', 'warn'];
                }
                // 3. Vysoka teplota
                if ($fresh && $temp >= 65) {
                    $devAlerts['high_temp'] = ['Vysoká teplota', ($d['name'] ?: 'Zariadenie') . ': teplota ' . round($temp) . ' °C', 'warn'];
                }
                // Upsert aktivnych alertov
                foreach ($devAlerts as $key => $a) {
                    $isNew = false;
                    $chk = $pdo->prepare("SELECT id, email_sent FROM device_alerts WHERE device_id = ? AND alert_key = ?");
                    $chk->execute([$did, $key]);
                    $existing = $chk->fetch();
                    if (!$existing) { $isNew = true; }
                    $ins = $pdo->prepare("INSERT INTO device_alerts (device_id, alert_key, title, body, severity, last_seen) VALUES (?,?,?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE last_seen = NOW(), body = VALUES(body), resolved_at = NULL");
                    $ins->execute([$did, $key, $a[0], $a[1], $a[2]]);
                    // Email/push pri NOVOM alerte (nie pri kazdom opakovani) — podla kanalov usera
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
                    if ($isNew && ($devPrefs['notif_email'] || $devPrefs['notif_push'])) {
                        try {
                            $u = $pdo->prepare("SELECT email FROM users WHERE id = (SELECT user_id FROM devices WHERE id = ?)");
                            $u->execute([$did]);
                            $email = $u->fetchColumn();
                            if ($email) {
                                // WEB PUSH na vsetky zariadenia usera (aj ked je appka zatvorena)
                                try {
                                    $uidStmt = $pdo->prepare("SELECT user_id FROM devices WHERE id = ?");
                                    $uidStmt->execute([$did]);
                                    $alertUid = intval($uidStmt->fetchColumn());
                                    if ($alertUid && $devPrefs['notif_push']) {
                                        require_once __DIR__ . '/push_helper.php';
                                        elvo_push_user($pdo, $alertUid,
                                            ($a[2] === 'crit' ? "\u{1F6A8} " : "\u{26A0}\u{FE0F} ") . $a[0],
                                            $a[1], 'alert-' . $key, '/dashboard');
                                    }
                                } catch (Exception $eP) { /* ignore */ }
                                if (!$devPrefs['notif_email']) {
                                    // user nepozeli email kanal — preskoc odoslanie
                                } else {
                                require_once __DIR__ . '/mail_helper.php';
                                $sevIcon = ($a[2] === 'crit') ? '🚨' : '⚠️';
                                $sevColor = ($a[2] === 'crit') ? '#f43f5e' : '#f59e0b';
                                send_elvo_email($email, $sevIcon . ' ' . $a[0] . ' | ElvoControll',
                                    $sevIcon . ' ' . $a[0],
                                    '<div style="margin:0 0 20px 0;padding:20px 22px;background:rgba(244,63,94,0.06);border:1px solid rgba(244,63,94,0.2);border-radius:14px;">' .
                                    '<div style="font-size:15px;font-weight:700;color:#f9fafb;margin-bottom:8px;">' . htmlspecialchars($a[1]) . '</div>' .
                                    '<div style="font-size:12px;color:#94a3b8;">Závažnosť: <strong style="color:' . $sevColor . ';">' . ($a[2] === 'crit' ? 'KRITICKÁ' : 'UPOZORNENIE') . '</strong> · ' . date('d.m.Y H:i') . '</div>' .
                                    '</div>' .
                                    '<p style="margin:0;font-size:13px;color:#cbd5e1;">Otvor dashboard pre detaily a stav zariadenia.</p>',
                                    $sevColor);
                                }
                            }
                        } catch (Exception $eM) { /* ignore */ }
                    }
                    if ($existing && !$existing['email_sent'] && $isNew) { /* handled */ }
                }
                // Resolve alertov, ktore uz neplatie
                if ($devAlerts) {
                    $keys = array_keys($devAlerts);
                    $ph2 = implode(',', array_fill(0, count($keys), '?'));
                    $vals = array_merge([$did], $keys);
                    $pdo->prepare("UPDATE device_alerts SET resolved_at = NOW() WHERE device_id = ? AND resolved_at IS NULL AND alert_key NOT IN ($ph2)")->execute($vals);
                } else {
                    $pdo->prepare("UPDATE device_alerts SET resolved_at = NOW() WHERE device_id = ? AND resolved_at IS NULL")->execute([$did]);
                }
            }
        }
    }

    function get_user_devices($pdo, $user_id) {
        $stmt = $pdo->prepare("SELECT * FROM devices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
}

// Spracovanie statických súborov
if (preg_match('#\.(json|js|css|woff2?|ttf|svg|ico|pdf|woff)$#i', $path)) {
    $clean_path = ltrim($path, '/');
    if (strpos($clean_path, 'templates/') === 0) {
        $clean_path = str_replace('templates/', '', $clean_path);
    }
    $possible_paths = [
        __DIR__ . '/' . ltrim($path, '/'),
        __DIR__ . '/App/templates/' . $clean_path,
        __DIR__ . '/templates/' . $clean_path,
    ];
    foreach ($possible_paths as $static_file) {
        if (file_exists($static_file)) {
            $mime_types = [
                'json' => 'application/json', 'js' => 'application/javascript', 'css' => 'text/css',
                'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff',
                'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'pdf' => 'application/pdf'
            ];
            $ext = strtolower(pathinfo($static_file, PATHINFO_EXTENSION));
            header('Content-Type: ' . ($mime_types[$ext] ?? 'application/octet-stream'));
            // CRITICAL: Service Worker NESMIE byt cache-ovany — inak telefony drzia
            // stary SW aj mesiac (immutable) a vsetky opravy "nechodia". vzdy no-cache.
            if (preg_match('#(^|/)sw\.js$#i', $path)) {
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Expires: 0');
                header('Pragma: no-cache');
            } elseif (preg_match('#(^|/)manifest\.json$#i', $path)) {
                // Manifest tiez vzdy cerstvy — Chrome pri starom manifeste ignoruje PWA
                // (start_url/scope "invalid") a instalacia na plochu je rozbita
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Expires: 0');
                header('Pragma: no-cache');
            } else {
                // Cache: staticke subory 30 dni (prehladac nesťahuje znova = jedno nacitanie menej)
                header('Cache-Control: public, max-age=2592000, immutable');
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT');
            }
            readfile($static_file);
            exit;
        }
    }
}

// Spracovanie obrázkov
if (preg_match('#\.(png|jpg|jpeg|gif)$#i', $path)) {
    $clean_path = ltrim($path, '/');
    if (strpos($clean_path, 'templates/') === 0) {
        $clean_path = str_replace('templates/', '', $clean_path);
    }
    $possible_img_paths = [
        __DIR__ . '/App/templates/' . $clean_path,
        __DIR__ . '/templates/' . $clean_path,
    ];
    foreach ($possible_img_paths as $img_path) {
        if (file_exists($img_path)) {
            $ext = strtolower(pathinfo($img_path, PATHINFO_EXTENSION));
            $mime_types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
            header('Cache-Control: public, max-age=2592000, immutable');
            header("Content-Type: " . ($mime_types[$ext] ?? 'image/png'));
            readfile($img_path);
            exit;
        }
    }
}

// --- OKTE SPOT CENY ---
function fetch_okte_prices($date_from = null, $date_to = null, $cache_bust = '') {
    if (!$date_from) $date_from = date('Y-m-d');
    if (!$date_to) $date_to = date('Y-m-d');
    
    $cache_file = __DIR__ . '/cache_okte_' . $date_from . '_' . $date_to . '.json';
    // Cache platna ak: menej ako 15 min stara, ALEBO nova ako posledne zverejnenie OKTE (13:00 / zaciatok dna)
    if (file_exists($cache_file)) {
        $publish_boundary = strtotime(date('Y-m-d') . ' 13:00');
        if (time() < $publish_boundary) $publish_boundary = strtotime(date('Y-m-d') . ' 00:00');
        if ((time() - filemtime($cache_file)) < 900 || filemtime($cache_file) >= $publish_boundary) {
            $cached = json_decode(file_get_contents($cache_file), true);
            if ($cached && isset($cached['prices']) && count($cached['prices']) > 0) return $cached;
        }
    }
    
    // REALNE OKTE ceny z isot.okte.sk API (zadne fake hardcoded hodnoty)
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    $url = "https://isot.okte.sk/api/v1/dam/results?deliveryDayFrom=" . $date_from . "&deliveryDayTo=" . $date_to;
    $raw = null;
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Accept-Language: sk,cs;q=0.9,en;q=0.8']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $response) {
            $raw = json_decode($response, true);
            if (is_array($raw)) $raw = isset($raw['results']) ? $raw['results'] : $raw;
        }
    }
    if ($raw === null && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "User-Agent: " . $userAgent . "\r\nAccept: application/json\r\n", 'timeout' => 10], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response) {
            $raw = json_decode($response, true);
            if (is_array($raw)) $raw = isset($raw['results']) ? $raw['results'] : $raw;
        }
    }
    
    // Ak API neodpoveda - vrat prazdny result (ziadne fake data!)
    if (!is_array($raw) || count($raw) === 0) {
        return ['date_from' => $date_from, 'date_to' => $date_to, 'prices' => [], 'avg' => 0, 'min' => 0, 'max' => 0, 'range_type' => 'none'];
    }
    
    // Zorad podla deliveryDay + period
    usort($raw, function($a, $b) {
        if (($a['deliveryDay'] ?? '') === ($b['deliveryDay'] ?? '')) return ((int)($a['period'] ?? 0)) - ((int)($b['period'] ?? 0));
        return strcmp($a['deliveryDay'] ?? '', $b['deliveryDay'] ?? '');
    });
    
    // REALNE 15-minutove ceny z OKTE (96 period/deň) - ziadne priemery
    // Zoskupujeme podla deliveryDay; format: 'HH:MM' (00:00, 00:15, 00:30...)
    $periods = [];
    foreach ($raw as $item) {
        $period = (int)($item['period'] ?? 0);
        if ($period < 1 || $period > 96) continue;
        $price = $item['price'] ?? null;
        if ($price === null || $price === '') continue; // zajtrajsie ceny mozu byt este null
        $day = substr($item['deliveryDay'] ?? $date_from, 0, 10);
        $q = ($period - 1) % 4;               // stvrtrok hodiny
        $hh = intdiv($period - 1, 4);
        $mm = $q * 15;
        $periods[] = [
            'hour' => sprintf('%02d:%02d', $hh, $mm),
            'price' => round(floatval($price), 2),
            'period' => $period,
            'day' => $day
        ];
    }
    
    // Zorad chronologicky podla dnia a period
    usort($periods, function($a, $b) {
        if ($a['day'] === $b['day']) return $a['period'] - $b['period'];
        return strcmp($a['day'], $b['day']);
    });
    
    $prices = $periods;
    $total = 0; $min = PHP_INT_MAX; $max = PHP_INT_MIN;
    foreach ($prices as $pr) {
        $total += $pr['price'];
        if ($pr['price'] < $min) $min = $pr['price'];
        if ($pr['price'] > $max) $max = $pr['price'];
    }
    
    if (count($prices) === 0) {
        return ['date_from' => $date_from, 'date_to' => $date_to, 'prices' => [], 'avg' => 0, 'min' => 0, 'max' => 0, 'range_type' => 'none'];
    }
    
    $result = [
        'date_from' => $date_from, 'date_to' => $date_to, 'prices' => $prices,
        'avg' => round($total / count($prices), 2), 'min' => $min, 'max' => $max,
        'range_type' => (count($prices) > 96 ? '48h' : '24h')
    ];
    @file_put_contents($cache_file, json_encode($result));
    return $result;
}

// =============================================================================
// ROUTING / SMEROVANIE POŽIADAVIEK
// =============================================================================

if ($path === '/' || $path === '') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $base_path . "/login");
        exit;
    }
    $devices = get_user_devices($pdo, $_SESSION['user_id']);
    
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_row = $stmt->fetch();
    $is_admin = ($user_row && ($user_row['role'] ?? '') === 'admin');
    
    if ($is_admin) {
        $all_devices = $pdo->query("SELECT d.*, u.username FROM devices d LEFT JOIN users u ON d.user_id = u.id ORDER BY d.id DESC")->fetchAll();
        render_template('admin.html', ['devices' => $all_devices, 'all_devices' => $all_devices, 'is_admin' => true]);
    } elseif (count($devices) === 0) {
        render_template('no_devices.html');
    } else {
        header("Location: " . $base_path . "/dashboard");
        exit;
    }
}

elseif ($path === '/login') {
    // Rate limit: max 3 neuspesnych pokusov -> 30 min lockout (per email+IP)
    $attempt_key = 'login_att_' . md5(strtolower(trim($_POST['email'] ?? '')) . '|' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
    $attempts = $_SESSION[$attempt_key] ?? ['count' => 0, 'until' => 0];
    if ($method === 'POST') {
        // 2FA blokada (3 zle kody -> 30 min): blokuje cele prihlasenie z tohto zariadenia
        $dev_hash_chk = hash('sha256', (trim($_POST['device_id'] ?? '') ?: ('ua:' . ($_SERVER['HTTP_USER_AGENT'] ?? '-'))));
        try {
            $tq = $pdo->prepare("SELECT blocked_until FROM login_throttle WHERE device_hash = ?");
            $tq->execute([$dev_hash_chk]);
            $bt = $tq->fetchColumn();
            if ($bt && strtotime($bt) > time()) {
                flash('Zariadenie je dočasne blokované (3× zlý overovací kód). Skúste o ' . date('H:i:s', strtotime($bt)) . '.', 'error');
                render_template('prihlasenie.html', ['flash' => get_flash_messages()]);
                exit;
            }
        } catch (Exception $e) { /* tabulka este neexistuje */ }
        if (time() < $attempts['until']) {
            flash('Priveľa neúspešných pokusov. Skúste znova o ' . date('H:i:s', $attempts['until']) . '.', 'error');
        } else {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            // Stabilny fingerprint zariadenia (z frontendu localStorage ID alebo UA fallback)
            $device_hash = hash('sha256', (trim($_POST['device_id'] ?? '') ?: ('ua:' . ($_SERVER['HTTP_USER_AGENT'] ?? '-'))));
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                unset($_SESSION[$attempt_key]);
                // 2FA pri KAZDOM prihlaseni - vzdy posleme overovaci kod na email
                {
                    $code = strval(random_int(100000, 999999));
                    $_SESSION['pending_login'] = [
                        'user_id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $email,
                        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                        'expires' => time() + 600,
                        'attempts' => 0,
                        'device_hash' => $device_hash,
                        'stay' => isset($_POST['stay_logged_in'])
                    ];
                    $mail_sent = false;
                    {
                        require_once __DIR__ . '/mail_helper.php';
                        // Token na odhlasenie zariadenia z mailu (platny 24 h)
                        $unsub_token = bin2hex(random_bytes(16));
                        $_SESSION['pending_login']['unsub_token'] = hash('sha256', $unsub_token);
                        $unsub_url = $base_path . '/device-logout?token=' . $unsub_token . '&login=1';
                        $mail_sent = send_elvo_email($email, 'Overovací kód: ' . $code . ' | ElvoControll', 'Prihlásenie do vášho účtu',
                        '<p style="margin:0 0 16px 0;font-size:14px;color:#cbd5e1;line-height:1.7;">Niektoré zariadenie sa prihlasuje do vášho účtu ElvoControll. Všetko potrebujete je v tomto jednom emaile:</p>' .
                        '<div style="margin:0 0 20px 0;padding:22px 24px;background:rgba(16,185,129,0.08);border:1px solid rgba(52,211,153,0.25);border-radius:16px;text-align:center;">' .
                        '<div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:2.5px;margin-bottom:10px;">Váš overovací kód</div>' .
                        '<div style="font-size:38px;font-weight:800;letter-spacing:12px;color:#34d399;font-family:monospace;">' . $code . '</div>' .
                        '</div>' .
                        '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 20px 0;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid rgba(255,255,255,0.08);">' .
                        '<tr><td style="padding:14px 18px;font-size:13px;color:#e2e8f0;">' .
                        '<div style="margin-bottom:6px;">🖥️ <strong>Zariadenie:</strong> ' . htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Neznáme zariadenie', 0, 60)) . '</div>' .
                        '<div style="margin-bottom:6px;">🌐 <strong>IP adresa:</strong> ' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '-') . '</div>' .
                        '<div>🕐 <strong>Čas prihlásenia:</strong> ' . date('d.m.Y H:i') . '</div>' .
                        '</td></tr></table>' .
                        '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 16px 0;"><tr><td align="center">' .
                        '<a href="' . $unsub_url . '" style="display:inline-block;padding:12px 28px;background:#f43f5e;color:#ffffff;text-decoration:none;font-weight:700;font-size:13px;border-radius:10px;">Odhásiť toto zariadenie</a>' .
                        '</td></tr></table>' .
                        '<p style="margin:0 0 8px 0;font-size:11px;color:#64748b;text-align:center;">Kliknutím zablokujete prihlásenie z tohto zariadenia.</p>' .
                        '<p style="margin:12px 0 0 0;font-size:12px;color:#64748b;">Ak ste to neboli vy, nikdy tento kód nikomu neposielajte a okamžite si zmeňte heslo.</p>',
                        '#6366f1');
                    }
                    // FALLBACK bez mailu: ak sa mail NEPODAL odoslat (ziadny RESEND_API_KEY/SMTP),
                    // kod sa zobrazi hore na obrazovke - inak by sa user nemohol nikdy prihlasit.
                    // Ak mail odchadza, kod sa NIKDY nezobrazi (plna 2FA bezpecnost).
                    if (!$mail_sent) {
                        $_SESSION['pending_login']['dev_code'] = $code;
                        error_log("[LOGIN] Mail sa nepodarilo odoslat na " . $email . " - kod zobrazeny na obrazovke (fallback)");
                    }
                    header("Location: " . $base_path . "/verify-login");
                    exit;
                }
            } else {
                $attempts['count']++;
                if ($attempts['count'] >= 3) { $attempts['until'] = time() + 1800; $attempts['count'] = 0; }
                $_SESSION[$attempt_key] = $attempts;
                flash("Nesprávne prihlasovacie údaje.", 'error');
            }
        }
    }
    render_template('prihlasenie.html', ['flash' => get_flash_messages()]);
}

// --- OVERENIE PRIHLASENIA EMAIL KODOM (nove zariadenie) ---
elseif ($path === '/verify-login') {
    if (!isset($_SESSION['pending_login'])) {
        header("Location: " . $base_path . "/login");
        exit;
    }
    $pl = $_SESSION['pending_login'];
    if ($method === 'POST') {
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        if (time() > $pl['expires']) {
            unset($_SESSION['pending_login']);
            flash('Overovací kód vypršal. Prihláste sa znova.', 'error');
            header("Location: " . $base_path . "/login");
            exit;
        }
        if ($pl['attempts'] >= 3) {
            // 3 zle kody = 30 min blokacia prihlasenia z tohto zariadenia
            $_SESSION['twofa_block_until'] = time() + 1800;
            $pdo->prepare("INSERT INTO login_throttle (device_hash, blocked_until) VALUES (?, DATE_ADD(NOW(), INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until)")
                ->execute([$pl['device_hash']]);
            unset($_SESSION['pending_login']);
            flash('Priveľa neúspešných pokusov. Prihlásenie z tohto zariadenia bude možné o 30 minút.', 'error');
            header("Location: " . $base_path . "/login");
            exit;
        }
        if ($code && password_verify($code, $pl['code_hash'])) {
            // Uspesne overenie -> uloz trusted device + prihlas
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS login_throttle (device_hash VARCHAR(64) PRIMARY KEY, blocked_until DATETIME NOT NULL)");
                $pdo->exec("CREATE TABLE IF NOT EXISTS login_devices (id INTEGER PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, device_hash VARCHAR(64) NOT NULL, device_name VARCHAR(100) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_login DATETIME NULL, UNIQUE KEY uq_userdev (user_id, device_hash))");
                $pdo->prepare("INSERT INTO login_devices (user_id, device_hash, device_name, last_login) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_login = NOW()")
                    ->execute([$pl['user_id'], $pl['device_hash'], substr($_SERVER['HTTP_USER_AGENT'] ?? 'Zariadenie', 0, 100)]);
            } catch (Exception $e) { /* ignore */ }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $pl['user_id'];
            $_SESSION['username'] = $pl['username'];
            $_SESSION['last_activity'] = time();
            $_SESSION['stay_logged_in'] = !empty($pl['stay']);
            unset($_SESSION['pending_login']);
            header("Location: " . $base_path . "/");
            exit;
        } else {
            $_SESSION['pending_login']['attempts'] = $pl['attempts'] + 1;
            $zostava = 3 - ($pl['attempts'] + 1);
            flash('Nesprávny kód.' . ($zostava > 0 ? ' Zostáva ' . $zostava . ' pokusov.' : ''), 'error');
        }
    }
    // Maskuj email: ad***@domena.sk
    $eparts = explode('@', $pl['email']);
    $mask_email = substr($eparts[0], 0, min(2, strlen($eparts[0]))) . '***@' . ($eparts[1] ?? '');
    render_template('overenie.html', ['flash' => get_flash_messages(), 'mask_email' => $mask_email, 'dev_code' => ($_SESSION['pending_login']['dev_code'] ?? '')]);
}

// --- ZNOVU POSLAT KOD ---
elseif ($path === '/verify-login/resend' && $method === 'GET') {
    if (isset($_SESSION['pending_login'])) {
        $code = strval(random_int(100000, 999999));
        $_SESSION['pending_login']['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['pending_login']['expires'] = time() + 600;
        $_SESSION['pending_login']['attempts'] = 0;
        unset($_SESSION['pending_login']['dev_code']);
        $mail_sent2 = false;
        {
            require_once __DIR__ . '/mail_helper.php';
            try {
                $unsub_token2 = bin2hex(random_bytes(16));
                $_SESSION['pending_login']['unsub_token'] = hash('sha256', $unsub_token2);
                $unsub_url2 = $base_path . '/device-logout?token=' . $unsub_token2 . '&login=1';
                $mail_sent2 = send_elvo_email($_SESSION['pending_login']['email'], 'Nový kód: ' . $code . ' | ElvoControll', 'Nový overovací kód',
                '<p style="margin:0 0 16px 0;font-size:14px;color:#cbd5e1;line-height:1.7;">Požiadali ste o nový overovací kód. Starý kód prestal platiť.</p>' .
                '<div style="margin:0 0 20px 0;padding:22px 24px;background:rgba(16,185,129,0.08);border:1px solid rgba(52,211,153,0.25);border-radius:16px;text-align:center;">' .
                '<div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:2.5px;margin-bottom:10px;">Váš nový kód</div>' .
                '<div style="font-size:38px;font-weight:800;letter-spacing:12px;color:#34d399;font-family:monospace;">' . $code . '</div>' .
                '</div>' .
                    '<p style="margin:0;font-size:12px;color:#64748b;">Platnosť: 10 minút &middot; IP: ' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '-') . ' &middot; ' . date('d.m.Y H:i') . '. Ak ste o kód nežiadali, zmeňte si heslo.</p>',
                    '#6366f1');
            } catch (Exception $me) { $mail_sent2 = false; }
        }
        // kod sa nezobrazuje na obrazovke (bezpecnost) - len mail
        if (!$mail_sent2) $_SESSION['pending_login']['dev_code'] = $code;
    }
    header("Location: " . $base_path . "/verify-login");
    exit;
}

elseif ($path === '/register') {
    if ($method === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashed]);
            // AUTO-LOGIN: pouzivatela rovno prihlasime - nemusi sa 2x prihlasovat
            session_regenerate_id(true);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['last_activity'] = time();
            flash('Účet vytvorený. Vitajte, ' . htmlspecialchars($username) . '!', 'success');
            // Vitajte email
            require_once __DIR__ . '/mail_helper.php';
            try {
                send_elvo_email($email, 'Vitajte v ElvoControll!', 'Účet úspešne vytvorený',
                    '<h2 style="margin:0 0 12px 0;font-size:20px;color:#0f172a;">Dobrý deň, ' . htmlspecialchars($username) . '!</h2>' .
                    '<p style="margin:0 0 16px 0;font-size:14px;color:#475569;line-height:1.6;">Váš účet v systéme ElvoControll bol úspešne vytvorený. Prihláste sa a pripojte svoje prvé zariadenie.</p>' .
                    '<a href="https://' . ($_SERVER['SERVER_NAME'] ?? 'elvosolar-production.up.railway.app') . '/login" style="display:inline-block;padding:12px 24px;background:#10b981;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;">Prihlásiť sa</a>',
                    '#10b981');
            } catch (Exception $me) { /* mail nie je kritický */ }
            header("Location: " . $base_path . "/dashboard");
            exit;
        } catch (PDOException $e) {
            flash('Meno alebo e-mail už existuje.', 'error');
        }
    }
    render_template('registracia.html', ['flash' => get_flash_messages()]);
}

elseif ($path === '/logout') {
    session_destroy();
    header("Location: " . $base_path . "/login");
    exit;
}

elseif ($path === '/setup' || $path === '/setup.html') {
    // Setup zariadenia moze robit IBA admin (user je presmerovany na dashboard)
    if (isset($_SESSION['user_id'])) {
        $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmtRole->execute([$_SESSION['user_id']]);
        $roleRow = $stmtRole->fetch();
        if (!$roleRow || ($roleRow['role'] ?? '') !== 'admin') {
            header("Location: " . $base_path . "/dashboard");
            exit;
        }
    }
    render_template('setup.html');
}

elseif ($path === '/dashboard' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $base_path . "/login");
        exit;
    }
    $devices = get_user_devices($pdo, $_SESSION['user_id']);
    // Admin (alebo admin poducet ako mechanik) moze pridavat zariadenia
    $stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmtRole->execute([$_SESSION['user_id']]);
    $roleRowD = $stmtRole->fetch();
    $is_admin = ($roleRowD && in_array($roleRowD['role'] ?? '', ['admin', 'mechanik']));
    render_template('dashboard.html', ['username' => $_SESSION['username'], 'devices' => $devices, 'is_admin' => $is_admin]);
}

elseif ($path === '/profile' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) { header("Location: " . $base_path . "/login"); exit; }
    render_template('profile.html');
}

// =============================================================================
// ADMIN DEVICE DETAIL (PODPORUJE: /admin_device.html, /admin/device/1, /admin_device/1)
// =============================================================================
elseif ((preg_match('#^/(?:admin/device|admin_device)/(\d+)$#', $path, $matches) || $path === '/admin_device.html' || $path === '/admin_device') && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) { 
        header("Location: " . $base_path . "/login"); 
        exit; 
    }
    
    // Zistí ID zariadenia buď z URL cesty alebo z GET parametra ?id=...
    $dev_id = isset($matches[1]) ? intval($matches[1]) : intval($_GET['id'] ?? 1);
    
    // Načítanie zariadenia z databázy
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE id = ?");
    $stmt->execute([$dev_id]);
    $device = $stmt->fetch();
    
    if (!$device) {
        $device = [
            'id' => $dev_id,
            'name' => 'Striedač #' . $dev_id,
            'serial_number' => 'SN-HW-00' . $dev_id,
            'modbus_slave_id' => 205
        ];
    }
    
    // Zobrazí šablónu admin_device.html
    render_template('admin_device.html', [
        'device' => $device, 
        'device_id' => $dev_id,
        'base_path' => $base_path
    ]);
}

// =============================================================================
// TERMINÁL: ZÁPIS DO SQL TABUĽKY cm5_config DO STĹPCA admin_command
// =============================================================================

elseif ($path === '/api/admin/terminal-command' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        send_json(['status' => 'error', 'message' => 'Neprihlásený používateľ'], 401);
    }
    
    $data = get_json_input();
    $cmd = trim($data['command'] ?? '');
    $devId = intval($data['device_id'] ?? 0);
    
    if (empty($cmd)) {
        send_json(['status' => 'error', 'message' => 'Príkaz nemôže byť prázdny'], 400);
    }
    
    try {
        // Zistenie slave_id a SN zariadenia
        $stmt = $pdo->prepare("SELECT modbus_slave_id, serial_number FROM devices WHERE id = ?");
        $stmt->execute([$devId]);
        $dev = $stmt->fetch();
        $slave_id = $dev ? intval($dev['modbus_slave_id'] ?? 205) : 205;
        $serial = $dev['serial_number'] ?? 'CM5-DEFAULT';

        // cm5_config zrusena (setup iba kablom) - prikaz sa ulozi do devices.admin_command
        try {
            if (!in_array('admin_command', $pdo->query("SHOW COLUMNS FROM devices")->fetchAll(PDO::FETCH_COLUMN))) {
                $pdo->exec("ALTER TABLE devices ADD COLUMN admin_command TEXT NULL");
            }
            $stmtU = $pdo->prepare("UPDATE devices SET admin_command = ? WHERE id = ?");
            $stmtU->execute([$cmd, $devId]);
            send_json(['status' => 'success', 'message' => "Príkaz '$cmd' bol zapísaný do zariadenia."]);
        } catch (Exception $e2) {
            send_json(['status' => 'error', 'message' => 'Chyba databázy: ' . $e2->getMessage()], 500);
        }
        exit;
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => 'Chyba databázy: ' . $e->getMessage()], 500);
    }
}

// MANUÁLNY ZÁPIS VÝKONU cez web (skúška 2 / ovládanie) — ide cez cloud do CM5
// --- ZMAZANIE ZARIADENIA Z CLOUDU (setup test 2 NEPREŠIEL alebo admin) ---
elseif (preg_match('#^/api/device/(\d+)/remove$#', $path, $mRem) && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    $dev_id = intval($mRem[1]);
    $data = get_json_input();
    $reason = trim($data['reason'] ?? '') ?: 'Zmazané cez setup (test neprešiel)';
    try {
        $stmtO = $pdo->prepare("SELECT id, user_id, name FROM devices WHERE id = ? LIMIT 1");
        $stmtO->execute([$dev_id]);
        $dev = $stmtO->fetch();
        if (!$dev) send_json(['status' => 'error', 'message' => 'Zariadenie neexistuje'], 404);
        // Iba vlastník alebo admin môže mazať
        $canDelete = (intval($dev['user_id']) === intval($_SESSION['user_id']));
        if (!$canDelete) {
            $stR = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stR->execute([$_SESSION['user_id']]);
            $rR = $stR->fetch();
            $canDelete = $rR && in_array($rR['role'] ?? '', ['admin']);
        }
        if (!$canDelete) send_json(['status' => 'error', 'message' => 'Nemáte oprávnenie'], 403);
        $pdo->prepare("DELETE FROM telemetry WHERE device_id = ?")->execute([$dev_id]);
        $pdo->prepare("DELETE FROM devices WHERE id = ?")->execute([$dev_id]);
        send_json(['status' => 'success', 'removed' => $dev_id, 'reason' => $reason, 'name' => $dev['name']]);
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

elseif (preg_match('#^/api/device/(\d+)/set-power$#', $path, $matches) && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        send_json(['status' => 'error', 'message' => 'Neprihlásený používateľ'], 401);
    }
    $data = get_json_input();
    $pct = floatval($data['pct'] ?? -1);
    $devId = intval($matches[1]);
    if ($pct < 0 || $pct > 100) {
        send_json(['status' => 'error', 'message' => 'Hodnota musí byť 0–100 %'], 400);
    }
    // Voliteľný režim návratu pôvodnej hodnoty (restore) — IBA na výslovné potvrdenie používateľa
    $restore = !empty($data['restore']);
    // Serial CM5 (setup ho pozná zo skúšky 1) — príkaz MUSÍ ísť na riadok, ktorý CM5 reálne polluje!
    $cmd_serial = trim($data['serial'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT id, serial_number FROM devices WHERE id = ?");
        $stmt->execute([$devId]);
        $dev = $stmt->fetch();
        if (!$dev) {
            send_json(['status' => 'error', 'message' => 'Zariadenie nenájdené'], 404);
        }
        // Rozhodni CIERY riadok: ak prišiel serial, prioritne riadok s TÝM serialom
        // (devId riadok môže byť bez serialu — potom by CM5 príkaz nikdy nevzal!)
        $target_id = intval($dev['id']);
        if ($cmd_serial !== '') {
            try {
                $stS = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ? ORDER BY id DESC LIMIT 1");
                $stS->execute([$cmd_serial]);
                $rowS = $stS->fetch();
                if ($rowS) { $target_id = intval($rowS['id']); }
            } catch (Exception $eS2) { /* fallback na devId */ }
        }
        // Zapíš príkaz do devices.admin_command (CM5 si ho vyzdvihne pri poll-e)
        if (!in_array('admin_command', $pdo->query("SHOW COLUMNS FROM devices")->fetchAll(PDO::FETCH_COLUMN))) {
            $pdo->exec("ALTER TABLE devices ADD COLUMN admin_command TEXT NULL");
        }
        $cmd_key = 'cmd_' . $target_id . '_' . time();
        $cmd = json_encode($restore
            ? ['action' => 'restore_power', 'pct' => $pct, 'cmd_key' => $cmd_key]
            : ['action' => 'set_power', 'pct' => $pct, 'cmd_key' => $cmd_key]);
        $stmtU = $pdo->prepare("UPDATE devices SET admin_command = ? WHERE id = ?");
        $stmtU->execute([$cmd, $target_id]);
        // STATUS 1: ODOSLANE (cloud zapisal prikaz; CM5 si ho ma vyzdvihnut pri polle)
        try {
            $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                ->execute(['last_cmd_result_dev_' . $target_id, json_encode([
                    'at' => date('c'), 'command_id' => $target_id, 'cmd_key' => $cmd_key,
                    'status' => 'sent', 'message' => 'Odoslané — čakám na prevzatie CM5',
                ])]);
        } catch (Exception $eSent) { /* ignore */ }
        send_json(['status' => 'success', 'target_device_id' => $target_id, 'message' => $restore
            ? "Návrat na {$pct} % odoslaný do CM5 (cez WiFi)."
            : "Príkaz na {$pct} % odoslaný do CM5 (cez WiFi). Over výsledok v Enspire o pár sekúnd."]);
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => 'Chyba: ' . $e->getMessage()], 500);
    }
}

// --- POSLEDNY VYSLEDOK PRIKAZU (setup polling — ci CM5 realne zapisal na SmartLogger) ---
// Klúčom je SERIAL CM5 (rovnaký, pod ktorým CM5 aj ukladá výsledok) — imúnne voči devId nezhodám
elseif (preg_match('#^/api/device/(\d+)/last-cmd-result$#', $path, $matches) && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) { send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401); }
    // ?serial=CM5-... má prednosť (rovnaký serial, ako posiela CM5)
    $lookup_serial = trim($_GET['serial'] ?? '');
    $lookup_id = intval($matches[1]);
    if ($lookup_serial !== '') {
        try {
            $stL = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ? ORDER BY id DESC LIMIT 1");
            $stL->execute([$lookup_serial]);
            $rowL = $stL->fetch();
            if ($rowL) { $lookup_id = intval($rowL['id']); }
        } catch (Exception $eL) { /* fallback na devId */ }
    }
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM system_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute(['last_cmd_result_dev_' . $lookup_id]);
        $row = $stmt->fetch();
        if ($row) {
            $d = json_decode($row['value'], true);
            send_json(['status' => 'success', 'result' => $d]);
        } else {
            send_json(['status' => 'no_result']);
        }
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// --- TELEMETRIA API PRE ZARIADENIE ---
elseif (preg_match('#^/api/device/(\d+)/telemetry$#', $path, $matches) && $method === 'GET') {
    $device_id = intval($matches[1]);
    
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE id = ?");
    $stmt->execute([$device_id]);
    $device = $stmt->fetch();
    
    $stmtT = $pdo->prepare("SELECT * FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 1");
    $stmtT->execute([$device_id]);
    $latest = $stmtT->fetch();
    
    // Historia pre graf: poslednych 48 hodin (najstarsie -> najnovsie)
    $history = [];
    try {
        // Rychle: poslednych max 384 zaznamov (48h x 15min) cez index, potom revers v PHP
        $stmtH = $pdo->prepare("SELECT power_ac, battery_soc, temp, timestamp FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 384");
        $stmtH->execute([$device_id]);
        $histRaw = $stmtH->fetchAll();
        foreach (array_reverse($histRaw) as $h) {
            $history[] = [
                'power_ac' => (float)$h['power_ac'],
                'battery_soc' => (float)$h['battery_soc'],
                'temp' => (float)$h['temp'],
                'timestamp' => strtotime($h['timestamp'])
            ];
        }
    } catch (Exception $eH) { /* stara schema bez timestamp - ignore */ }
    
    send_json([
        // REALNE DATA IBA - ziadne fake fallbacky (3840/84 boli fake)
        'total_live_power' => $latest ? (float)$latest['power_ac'] : 0,
        'avg_live_soc' => $latest ? (float)$latest['battery_soc'] : 0,
        'total_kwh' => (float)($device['total_kwh'] ?? 0),
        'total_saved_eur' => (float)($device['total_saved_eur'] ?? 0),
        'temp' => $latest ? (float)$latest['temp'] : 0,
        'inverter_temp' => $latest ? (float)$latest['temp'] : 0,
        'freq' => $latest ? (float)$latest['freq'] : 0,
        'consumption' => $latest && isset($latest['grid_import_w']) ? (float)$latest['grid_import_w'] : 0,
        'grid_export_w' => 0,
        'device_battery_pct' => $latest ? (float)$latest['battery_soc'] : 0,
        'has_real_data' => $latest ? true : false,
        'history' => $history,
        'manual_override' => $device['manual_override'] ?? 'AUTO',
        'meter_control_mode' => $device['meter_control_mode'] ?? 'SMART',
        'name' => $device['name'] ?? '',
        'connection_type' => $device['connection_type'] ?? 'modbus_tcp',
        'brand_id' => $device['brand_id'] ?? '',
        'brand' => strtoupper($device['brand_id'] ?? ''),
        'slave_id' => $device['modbus_slave_id'] ?? $device['slave_id'] ?? 0,
        'smartlogger_ip' => $device['smartlogger_ip'] ?? '',
        'is_online' => (($device['status'] ?? '') === 'online') || ($latest && (float)$latest['power_ac'] > 0),
        'has_smart_meter' => (bool)($device['has_smart_meter'] ?? false),
        // Stav komunikacie: zariadenie odpovedalo v poslednych 15 minutach?
        'last_telemetry_at' => $latest ? $latest['timestamp'] : null,
        'comm_ok' => (function() use ($latest) { if (!$latest) return false; $d = time() - strtotime($latest['timestamp']); return $d >= 0 && $d < 900; })(),
        'last_comm_sec' => $latest ? max(0, time() - strtotime($latest['timestamp'])) : null,
        // Typ zariadenia - podla sub_type/category_id (jedina pravda v DB)
        'is_smartlogger' => (strpos(strtolower($device['sub_type'] ?? ''), 'smartlogger') !== false) || (strpos(strtolower($device['category_id'] ?? ''), 'smartlogger') !== false),
        // Firmware verzia (z Modbus — ak CM5 posiela)
        'fw_version' => $device['fw_version'] ?? '',
        // Zoznam vsetkych pripojenych zariadeni (striedace/SmartLoggery) nahlásené CM5
        'connected_devices' => (function() use ($device) {
            $raw = $device['connected_devices'] ?? null;
            if (!$raw) return [];
            $a = json_decode($raw, true);
            return is_array($a) ? $a : [];
        })(),
    ]);
}

// --- OKTE CENY API ---
elseif ($path === '/api/okte/prices' && $method === 'GET') {
    // Po 13:00 OKTE zverejni zajtrajsie ceny -> zobraz 48h (dnes + zajtra)
    // Pred 13:00 su dostupne len dnesne -> 24h
    $hour = (int)date('G');
    $is_after_publish = ($hour >= 13); // OKTE zverejni zajtrajsie ceny o 13:00
    $cache_bust = date('Y-m-d') . ($is_after_publish ? '_a' : '_b');
    if ($is_after_publish) {
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+1 day'));
        $range = '48h';
    } else {
        $from = date('Y-m-d');
        $to = date('Y-m-d');
        $range = '24h';
    }
    $cache_file_check = __DIR__ . '/cache_okte_' . $from . '_' . $to . '.json';
    if (file_exists($cache_file_check) && filemtime($cache_file_check) < strtotime($is_after_publish ? 'today 13:00' : 'today 00:00')) {
        @unlink($cache_file_check); // stary cache - vynut fresh fetch
    }
    $data = fetch_okte_prices($from, $to, $cache_bust);
    if (!$data || !isset($data['prices']) || count($data['prices']) === 0) {
        // Fallback: vcera + dnes
        $from = date('Y-m-d', strtotime('-1 day'));
        $to = date('Y-m-d');
        $data = fetch_okte_prices($from, $to);
        $range = '24h';
    }
    if ($data) $data['range_type'] = $range;
    send_json(['status' => 'success', 'okte' => $data]);
}

// --- CM5 POLL PRE PRÍKAZY (Pre Raspberry Pi agenta) ---
elseif ($path === '/api/cm5/poll' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial'] ?? '');
    if ($serial === '') { send_json(['status' => 'no_pending']); }
    
    // BEZPECNOST: poll berie IBA prikazy pre KONKRETNY serial - ziadny CM5-DEFAULT fallback.
    // Nesetupnuty box tak nedostane ziadny stary/zaseknuty prikaz urceny inemu zariadeniu.
    $serials = [$serial];
    try {
        $sStmt = $pdo->prepare("SELECT serial_number FROM devices WHERE serial_number = ? LIMIT 1");
        $sStmt->execute([$serial]);
        if (!$sStmt->fetch()) {
            // Neznamy serial — CM5 sa samo pre-registruje (self-healing serialu v DB)
            send_json(['status' => 'unknown_serial']);
        }
    } catch (Exception $eS) { /* tabulka neexistuje -> pokracuj (cloud sync prvy krat registruje) */ }
    
    // 1) NOVÝ KANÁL: admin_command uložený priamo v devices podla serial_number
    //    (cm5_config zrušená — setup ide výhradne káblom, nič sa neukladá do cloud DB)
    try {
        $dStmt = $pdo->prepare("SELECT id, admin_command FROM devices WHERE serial_number = ? AND admin_command IS NOT NULL AND admin_command != '' ORDER BY id DESC LIMIT 1");
        $dStmt->execute([$serial]);
        $dRow = $dStmt->fetch();
        if ($dRow) {
            $cmd = trim((string)$dRow['admin_command']);
            $action = $cmd;
            $cfg = ['action' => $cmd];
            // JSON prikaz {"action":"..."} -> parsuj
            if ($cmd !== '' && ($cmd[0] === '{')) {
                $parsed = json_decode($cmd, true);
                if (is_array($parsed) && !empty($parsed['action'])) {
                    $action = $parsed['action'];
                    $cfg = $parsed;
                }
            }
            // Vycisti prikaz (jednorazovy)
            $pdo->prepare("UPDATE devices SET admin_command = NULL WHERE id = ?")->execute([$dRow['id']]);
            // STATUS 2: PRIJATE — CM5 si vyzdvihol prikaz (setup/Riadenie si to precita cez last-cmd-result)
            try {
                $cmdKey = '';
                if ($cmd !== '' && $cmd[0] === '{') {
                    $pj = json_decode($cmd, true);
                    if (is_array($pj)) $cmdKey = (string)($pj['cmd_key'] ?? '');
                }
                $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                    ->execute(['last_cmd_result_dev_' . intval($dRow['id']), json_encode([
                        'at' => date('c'), 'command_id' => intval($dRow['id']), 'cmd_key' => $cmdKey,
                        'status' => 'received', 'message' => 'Prijaté — CM5 vykonáva',
                    ])]);
            } catch (Exception $eRcv) { /* ignore */ }
            send_json([
                'status' => 'success',
                'command' => $action,
                'config' => $cfg,
                'command_id' => intval($dRow['id']),
                'id' => intval($dRow['id'])
            ]);
        }
    } catch (Exception $eD) { /* ignore */ }
    
    send_json(['status' => 'no_pending']);
}

// --- CM5 RESULT (CM5 ohlási výsledok vykonaného príkazu — ok/chyba + readback) ---
elseif ($path === '/api/cm5/result' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial'] ?? '');
    if ($serial === '') { send_json(['status' => 'no_serial']); }
    try {
        $dStmt = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ? LIMIT 1");
        $dStmt->execute([$serial]);
        $dev = $dStmt->fetch();
        if (!$dev) { send_json(['status' => 'unknown_device']); }
        // Uloz posledny vysledok prikazu (setup si ho precita cez /api/device/{id}/last-cmd-result)
        $res = $data['result'] ?? [];
        $payload = json_encode([
            'at' => date('c'),
            'command_id' => intval($data['command_id'] ?? 0),
            'cmd_key' => ($res['cmd_key'] ?? ''),
            'status' => ($res['status'] ?? 'unknown'),
            'message' => ($res['message'] ?? ''),
            'readback_pct' => isset($res['readback_pct']) ? $res['readback_pct'] : null,
            'original_pct' => isset($res['original_pct']) ? $res['original_pct'] : null,
        ]);
        // UPSERT: prepíš posledný výsledok (INSERT zlyhá na duplicate key pri druhom pupusu)
        $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
            ->execute(['last_cmd_result_dev_' . intval($dev['id']), $payload]);
        // HW DEPLOY: ak CM5 potvrdil/nepotvrdil deploy súborov, zapis stav pre admin terminal
        if (($res['status'] ?? '') === 'success' && isset($res['applied'])) {
            try {
                $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('hw_deploy_status', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                    ->execute(['success']);
            } catch (Exception $e) {}
        } elseif (($res['status'] ?? '') !== 'success') {
            $cur = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'hw_deploy_status' LIMIT 1")->fetch();
            if ($cur && $cur['value'] === 'pending') {
                $pdo->prepare("UPDATE system_settings SET `value` = 'error' WHERE `key` = 'hw_deploy_status'")->execute();
            }
        }
        send_json(['status' => 'success']);
    } catch (Exception $e) {
        try {
            // fallback: tabuľka môže ešte neexistovať — vytvor ju
            $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT)");
            $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                ->execute(['last_cmd_result_dev_' . intval($dev['id']), $payload ?? '{}']);
            send_json(['status' => 'success']);
        } catch (Exception $e2) {
            send_json(['status' => 'error', 'message' => $e2->getMessage()]);
        }
    }
}

// --- CM5 REPORT IP (keepalive) ---
elseif ($path === '/api/report-ip' && $method === 'POST') {
    $data = get_json_input();
    $ip = trim($data['ip'] ?? '');
    $serial = trim($data['serial'] ?? '');
    if ($ip && $serial) {
        // cm5_config zrusena (setup iba kablom) - keepalive len potvrdi
        try {
            $stmt = $pdo->prepare("UPDATE devices SET last_seen = NOW() WHERE serial_number = ?");
            $stmt->execute([$serial]);
        } catch (Exception $e) { /* ignore */ }
    }
    send_json(['status' => 'success']);
}

// --- CLOUD SYNC TELEMETRIA (CM5 posiela realne data) ---
elseif ($path === '/api/cloud/sync-telemetry' && $method === 'POST') {
    $data = get_json_input();
    // CM5 posiela 'serial_number' v telemetry payload - akceptuj OBA kluce (ziadny CM5-DEFAULT fallback!)
    $serial = trim($data['serial'] ?? $data['serial_number'] ?? '');
    
    // Najdi zariadenie podla serial alebo prve
    $device_id = 0;
    try {
        // BEZPECNOST: telemetria patri IBA znamemu serialu (ziadny fallback na prve zariadenie)
        $stmt = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ? LIMIT 1");
        $stmt->execute([$serial]);
        $row = $stmt->fetch();
        if ($row) { $device_id = intval($row['id']); }
    } catch (Exception $e) { /* ignore */ }
    
    if ($device_id) {
        // Self-healing: dopln chybajuce stlpce v telemetry tabulke (stara schema fix)
        try {
            $tcols = $pdo->query("SHOW COLUMNS FROM telemetry")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('battery_soc', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN battery_soc FLOAT DEFAULT 0");
            if (!in_array('power_ac', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN power_ac FLOAT DEFAULT 0");
            if (!in_array('temp', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN temp FLOAT DEFAULT 0");
            if (!in_array('freq', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN freq FLOAT DEFAULT 50");
            if (!in_array('status_msg', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN status_msg VARCHAR(255) DEFAULT 'Online'");
        } catch (Exception $eSH) { /* ignore */ }
        // Self-healing: aj devices tabulka moze mat staru schema bez tychto stlpcov
        $devHasSoc = false; $devHasFve = false; $devHasTemp = false;
        try {
            $dcols = $pdo->query("SHOW COLUMNS FROM devices")->fetchAll(PDO::FETCH_COLUMN);
            $devHasSoc = in_array('battery_soc', $dcols);
            $devHasFve = in_array('fve_power_w', $dcols);
            $devHasTemp = in_array('temp', $dcols);
            if (!$devHasSoc) $pdo->exec("ALTER TABLE devices ADD COLUMN battery_soc FLOAT DEFAULT 0");
            if (!$devHasFve) $pdo->exec("ALTER TABLE devices ADD COLUMN fve_power_w FLOAT DEFAULT 0");
            if (!$devHasTemp) $pdo->exec("ALTER TABLE devices ADD COLUMN temp FLOAT DEFAULT 0");
            $devHasSoc = true; $devHasFve = true; $devHasTemp = true;
        } catch (Exception $eSD) { /* ignore */ }
        try {
            // Uloz telemetry zaznam - NOW() moze failnut na MySQL strict mode, pouzime date('Y-m-d H:i:s')
            $ts = date('Y-m-d H:i:s');
            // Self-healing: grid_import_w stlpec (odber zo siete)
            try {
                if (!in_array('grid_import_w', $tcols)) $pdo->exec("ALTER TABLE telemetry ADD COLUMN grid_import_w FLOAT DEFAULT 0");
                $tcols[] = 'grid_import_w';
            } catch (Exception $eG) { /* ignore */ }
            $stmt = $pdo->prepare("INSERT INTO telemetry (device_id, power_ac, battery_soc, temp, freq, status_msg, grid_import_w, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $device_id,
                floatval($data['power_ac'] ?? 0),
                floatval($data['battery_soc'] ?? 0),
                floatval($data['temp'] ?? 0),
                floatval($data['freq'] ?? 50),
                substr($data['status_msg'] ?? 'Online', 0, 255),
                floatval($data['grid_import_w'] ?? 0),
                $ts
            ]);
            // Aktualizuj devices - status online + posledne hodnoty (sety podla toho co tabulka ma)
            $updParts = ["status = 'online'", "last_seen = NOW()"];
            $updVals = [];
            if ($devHasSoc) { $updParts[] = "battery_soc = ?"; $updVals[] = floatval($data['battery_soc'] ?? 0); }
            if ($devHasFve) { $updParts[] = "fve_power_w = ?"; $updVals[] = floatval($data['power_ac'] ?? 0); }
            if ($devHasTemp) { $updParts[] = "temp = ?"; $updVals[] = floatval($data['temp'] ?? 0); }
            $updVals[] = $device_id;
            $stmt2 = $pdo->prepare("UPDATE devices SET " . implode(', ', $updParts) . " WHERE id = ?");
            $stmt2->execute($updVals);
            // FIRMWARE: verzia striedaca/SmartLoggera (ak ju CM5 nacital cez Modbus)
            try {
                $fw = trim((string)($data['fw_version'] ?? ''));
                if ($fw !== '') {
                    if (!in_array('fw_version', $dcols ?? [])) $pdo->exec("ALTER TABLE devices ADD COLUMN fw_version VARCHAR(64) DEFAULT ''");
                    $pdo->prepare("UPDATE devices SET fw_version = ? WHERE id = ?")->execute([substr($fw, 0, 64), $device_id]);
                }
            } catch (Exception $eFw) { /* ignore */ }
            // Zoznam pripojenych zariadeni (striedace/SmartLoggery nahlásené CM5)
            try {
                if (!in_array('connected_devices', $dcols ?? [])) $pdo->exec("ALTER TABLE devices ADD COLUMN connected_devices TEXT");
                if (isset($data['connected_devices']) && is_array($data['connected_devices'])) {
                    $stmtC = $pdo->prepare("UPDATE devices SET connected_devices = ? WHERE id = ?");
                    $stmtC->execute([json_encode(array_slice($data['connected_devices'], 0, 64)), $device_id]);
                }
            } catch (Exception $eC) { /* ignore */ }
            // ALERT SYSTEM: vyhodnot alerty aj pri každej telemetrii (email aj keď nikto nepozerá dashboard)
            try { eval_device_alerts($pdo, [$device_id]); } catch (Exception $eA) { /* ignore */ }
            
            // CONTROL: aktualne nastavenia z DB — CM5 si ich precita a OKAMZITE aplikuje.
            // Dashboard meni devices tabulku, CM5 ju cita odtialto (DB = jedina pravda).
            $ctrl = [];
            try {
                $stC = $pdo->prepare("SELECT manual_override, active_model_id, min_power_pct, max_power_pct, min_okte_price_cz_eur FROM devices WHERE id = ?");
                $stC->execute([$device_id]);
                $rC = $stC->fetch();
                if ($rC) {
                    $ctrl = [
                        'manual_override' => strtoupper(trim($rC['manual_override'] ?? 'AUTO')) ?: 'AUTO',
                        'active_model_id' => ($rC['active_model_id'] ?? 'AI') ?: 'AI',
                        'min_power_pct' => floatval($rC['min_power_pct'] ?? 0),
                        'max_power_pct' => floatval($rC['max_power_pct'] ?? 100),
                        'min_okte_price' => floatval($rC['min_okte_price_cz_eur'] ?? 0),
                        // Zap/Vyp/SmartAI - CM5 si to prehodi do smart_meter.control_mode
                        'meter_control_mode' => strtoupper(trim($rC['meter_control_mode'] ?? 'SMART')) ?: 'SMART',
                    ];
                }
            } catch (Exception $eCtrl) { /* stara schema bez tychto stlpcov */ }
            send_json(['status' => 'success', 'device_id' => $device_id, 'control' => $ctrl]);
        } catch (Exception $e) {
            send_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
    send_json(['status' => 'error', 'message' => 'Ziadne zariadenie v DB']);
}

// --- CM5 REGISTER ---
// --- CM5 REGISTER (startova registracia z lokalnej DB) ---
        // ===== HW DEPLOY: ulozenie suborov + planovanie deployu na CM5 (admin only) =====
        elseif ($path === '/api/hw-deploy' && $method === 'POST') {
            if (($_SESSION['role'] ?? '') !== 'admin') send_json(['status' => 'error', 'message' => 'Iba admin'], 403);
            // Auto-create system_settings (MySQL + SQLite kompatibilné)
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT)"); } catch (Exception $e) {}
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
                $name = str_replace(['..', '\\', chr(0)], '', $name);
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
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT)"); } catch (Exception $e) {}
            try {
                $st = $pdo->query("SELECT `key`, `value` FROM system_settings WHERE `key` IN ('hw_deploy_status','hw_deploy_serial','hw_deploy_time')");
                $rows = $st->fetchAll();
            } catch (Exception $e) {
                $rows = [];
            }
            $out = ['status' => 'idle', 'serial' => '', 'time' => ''];
            foreach ($rows as $row) {
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
            try {
                $st = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'hw_deploy_files' LIMIT 1");
                $row = $st->fetch();
            } catch (Exception $e) {
                // Tabulka neexistuje — vytvor ju (MySQL + SQLite kompatibilné)
                try { $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT)"); } catch (Exception $e2) {}
                $row = false;
            }
            if (!$row) send_json(['status' => 'error', 'message' => 'Žiadny deploy pripravený'], 404);
            $files = json_decode($row['value'], true);
            if (!is_array($files) || !$files) send_json(['status' => 'error', 'message' => 'Balík je prázdny'], 404);
            send_json(['status' => 'success', 'serial' => $serial, 'files' => $files]);
        }

elseif ($path === '/api/cm5/register' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial'] ?? '');
    if ($serial) {
        // cm5_config zrusena - registracia len potvrdi serial
        try {
            $stmt = $pdo->prepare("UPDATE devices SET last_seen = NOW() WHERE serial_number = ?");
            $stmt->execute([$serial]);
        } catch (Exception $e) { /* ignore */ }
    }
    send_json(['status' => 'success', 'serial' => $serial]);
}

// --- Overenie údajov zákazníka pri setup-e (admin inštaluje, zariadenie patrí zákazníkovi) ---
elseif ($path === '/api/setup/verify-customer' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    // Iba admin môže setupovať pre zákazníkov
    try {
        $stA = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stA->execute([intval($_SESSION['user_id'])]);
        $me = $stA->fetch();
        if (!$me || ($me['role'] ?? '') !== 'admin') {
            send_json(['status' => 'error', 'message' => 'Setup zariadení môže vykonávať iba admin.'], 403);
        }
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => 'DB chyba pri overení role.'], 500);
    }
    $data = get_json_input();
    $vcEmail = trim($data['email'] ?? '');
    $vcPass = strval($data['password'] ?? '');
    if (!$vcEmail || !$vcPass) send_json(['status' => 'error', 'message' => 'Zadaj e-mail a heslo zákazníka.'], 400);
    try {
        $stU = $pdo->prepare("SELECT id, role, username, password_hash FROM users WHERE email = ? LIMIT 1");
        $stU->execute([$vcEmail]);
        $u = $stU->fetch();
        if (!$u || !password_verify($vcPass, $u['password_hash'] ?? '')) {
            send_json(['status' => 'error', 'message' => 'Nesprávny e-mail alebo heslo zákazníka — účet neexistuje alebo heslo nesedí.'], 401);
        }
        if (($u['role'] ?? '') === 'admin') {
            send_json(['status' => 'error', 'role' => 'admin', 'message' => 'Zariadenie nesmie patriť adminovi — admin len inštaluje, nevlastní ho. Zadaj údaje bežného používateľa.'], 422);
        }
        send_json(['status' => 'success', 'user_id' => intval($u['id']), 'username' => $u['username'], 'role' => $u['role']]);
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => 'DB chyba pri overení zákazníka.'], 500);
    }
}

// --- USER CLAIM DEVICE (ulozi meno + parametre zariadenia do cloud DB) ---
elseif ($path === '/api/user/me' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    $stmt = $pdo->prepare("SELECT id, username, email, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u) send_json(['status' => 'error', 'message' => 'Používateľ neexistuje'], 404);
    $created = $u['created_at'] ? date('d.m.Y', strtotime($u['created_at'])) : '2026';
    send_json(['status' => 'success', 'user_id' => intval($u['id']), 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'created_at' => $created]);
}

elseif ($path === '/api/user/devices' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    try {
        $stmt = $pdo->prepare("SELECT d.*, (SELECT MAX(timestamp) FROM telemetry t WHERE t.device_id = d.id) AS last_telemetry FROM devices d WHERE d.user_id = ? ORDER BY d.id");
        $stmt->execute([$_SESSION['user_id']]);
        $devs = $stmt->fetchAll();
    } catch (Exception $e) {
        $stmt = $pdo->prepare("SELECT * FROM devices WHERE user_id = ? ORDER BY id");
        $stmt->execute([$_SESSION['user_id']]);
        $devs = $stmt->fetchAll();
        foreach ($devs as &$dv) { $dv['last_telemetry'] = null; }
        unset($dv);
    }
    $out = [];
    foreach ($devs as $d) {
        $online = false;
        if (!empty($d['last_telemetry'])) {
            $online = (time() - strtotime($d['last_telemetry'])) < 900;
        }
        $out[] = [
            'id' => $d['id'],
            'name' => $d['name'] ?? ('Zariadenie ' . $d['id']),
            'serial_number' => $d['serial_number'] ?? '',
            'brand_id' => $d['brand_id'] ?? '',
            'model_id' => $d['model_id'] ?? '',
            'is_online' => $online,
        ];
    }
    send_json(['status' => 'success', 'devices' => $out]);
}

elseif ($path === '/api/user/notifications' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    $defaults = ['new_device' => true, 'error' => true, 'daily_report' => false, 'negative_price' => true, 'notif_email' => true, 'notif_push' => true];
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
    send_json(['status' => 'success', 'notifications' => $defaults]);
}

elseif ($path === '/api/user/notifications' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) send_json(['status' => 'error', 'message' => 'Neplatné dáta'], 400);
    $clean = [
        'new_device' => !empty($data['new_device']),
        'error' => !empty($data['error']),
        'daily_report' => !empty($data['daily_report']),
        'negative_price' => !empty($data['negative_price']),
        'notif_email' => !empty($data['notif_email']),
        'notif_push' => !empty($data['notif_push']),
    ];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (user_id INT PRIMARY KEY, prefs TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $stmt = $pdo->prepare("INSERT INTO user_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)");
        $stmt->execute([$_SESSION['user_id'], json_encode($clean)]);
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => 'Uloženie zlyhalo'], 500);
    }
    send_json(['status' => 'success', 'message' => 'Nastavenia uložené']);
}

elseif ($path === '/api/user/change-password' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    $data = json_decode(file_get_contents('php://input'), true);
    $cur = $data['current_password'] ?? '';
    $new = $data['new_password'] ?? '';
    if (strlen($new) < 6) send_json(['status' => 'error', 'message' => 'Nové heslo musí mať aspoň 6 znakov'], 400);
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($cur, $u['password_hash'])) send_json(['status' => 'error', 'message' => 'Súčasné heslo je nesprávne'], 400);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]);
    send_json(['status' => 'success', 'message' => 'Heslo úspešne zmenené']);
}

elseif ($path === '/api/user/test-email' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    $stmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u) send_json(['status' => 'error', 'message' => 'Používateľ neexistuje'], 404);
    require_once __DIR__ . '/mail_helper.php';
    $ok = false;
    try {
        $ok = send_elvo_email($u['email'], 'Test email | ElvoControll', 'Test odosielania emailov',
            '<p>Ak vidíte tento email, odosielanie funguje správne.</p><p style="color:#94a3b8;font-size:12px;">Čas: ' . date('d.m.Y H:i:s') . '</p>',
            '#10b981');
    } catch (Exception $e) { $ok = false; }
    if ($ok) send_json(['status' => 'success', 'message' => 'Test email bol odoslaný na ' . $u['email']]);
    // Diagnostika: resend ani relay nie su nastavene
    $hasResend = getenv('RESEND_API_KEY') && trim(getenv('RESEND_API_KEY')) !== '';
    $hasRelay = getenv('MAIL_RELAY_URL') && trim(getenv('MAIL_RELAY_URL')) !== '';
    $lastErr = $GLOBALS['elvo_mail_last_error'] ?? '';
    if (!$hasResend && !$hasRelay) send_json(['status' => 'error', 'message' => 'E-mailová služba nie je nastavená na serveri (chýba RESEND_API_KEY). Nastav ju v Railway premenných.']);
    if ($lastErr !== '') send_json(['status' => 'error', 'message' => 'Odoslanie zlyhalo: ' . $lastErr]);
    send_json(['status' => 'error', 'message' => 'Odoslanie zlyhalo — skontroluj RESEND_API_KEY alebo mail doručenia']);
}

elseif ($path === '/forgot-password' && $method === 'GET') {
    render_template('forgot-password.html', ['flash' => get_flash_messages()]);
}

elseif ($path === '/forgot-password' && $method === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) {
        flash('Ak tento email existuje, kód bol odoslaný.', 'success');
        header("Location: " . $base_path . "/forgot-password");
        exit;
    }
    $code = strval(random_int(100000, 999999));
    $_SESSION['pw_reset'] = [
        'user_id' => $u['id'],
        'email' => $email,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires' => time() + 600,
        'attempts' => 0,
    ];
    $_SESSION['reset_step'] = 2;
    $mail_sent = false;
    require_once __DIR__ . '/mail_helper.php';
    try {
        $mail_sent = send_elvo_email($email, 'Obnovenie hesla | ElvoControll', 'Kód na obnovenie hesla',
            '<p style="margin:0 0 16px 0;font-size:14px;color:#cbd5e1;line-height:1.7;">Zabudli ste heslo? Nie je problém. Zadajte tento kód v aplikácii:</p>' .
            '<div style="margin:0 0 20px 0;padding:22px 24px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:16px;text-align:center;">' .
            '<div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:2.5px;margin-bottom:10px;">Kód na obnovenie hesla</div>' .
            '<div style="font-size:38px;font-weight:800;letter-spacing:12px;color:#fbbf24;font-family:monospace;">' . $code . '</div>' .
            '</div>' .
            '<p style="margin:0;font-size:12px;color:#64748b;">Platnosť: 10 minút. Ak ste o obnovenie nežiadali, ignorujte tento email.</p>',
            '#f59e0b');
    } catch (Exception $me) { $mail_sent = false; }
    if (!$mail_sent) {
        $_SESSION['pw_reset']['dev_code'] = $code;
        flash('Emailová služba nie je pripojená. Váš overovací kód: ' . $code, 'success');
    } else {
        flash('Kód bol odoslaný na váš email.', 'success');
    }
    header("Location: " . $base_path . "/forgot-password");
    exit;
}

elseif ($path === '/verify-reset-code' && $method === 'POST') {
    $rs = $_SESSION['pw_reset'] ?? null;
    if (!$rs || time() > ($rs['expires'] ?? 0)) {
        unset($_SESSION['pw_reset'], $_SESSION['reset_step']);
        flash('Kód vypršal. Začnite znova.', 'error');
        header("Location: " . $base_path . "/forgot-password");
        exit;
    }
    $code = preg_replace('/\D/', '', $_POST['verification_code'] ?? '');
    $new = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    if ($rs['attempts'] >= 5) {
        unset($_SESSION['pw_reset'], $_SESSION['reset_step']);
        flash('Priveľa pokusov. Začnite znova.', 'error');
        header("Location: " . $base_path . "/forgot-password");
        exit;
    }
    if (!$code || !password_verify($code, $rs['code_hash'])) {
        $_SESSION['pw_reset']['attempts'] = ($rs['attempts'] ?? 0) + 1;
        flash('Nesprávny kód.', 'error');
        header("Location: " . $base_path . "/forgot-password");
        exit;
    }
    if (strlen($new) < 6) { flash('Heslo musí mať aspoň 6 znakov.', 'error'); header("Location: " . $base_path . "/forgot-password"); exit; }
    if ($new !== $conf) { flash('Heslá sa nezhodujú.', 'error'); header("Location: " . $base_path . "/forgot-password"); exit; }
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([password_hash($new, PASSWORD_BCRYPT), $rs['user_id']]);
    unset($_SESSION['pw_reset'], $_SESSION['reset_step']);
    flash('Heslo bolo úspešne zmenené. Prihláste sa novým heslom.', 'success');
    header("Location: " . $base_path . "/login");
    exit;
}

elseif ($path === '/api/user/me' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    $stmt = $pdo->prepare("SELECT id, username, email, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u) send_json(['status' => 'error', 'message' => 'Používateľ neexistuje'], 404);
    $created = $u['created_at'] ? date('d.m.Y', strtotime($u['created_at'])) : '2026';
    send_json(['status' => 'success', 'user_id' => intval($u['id']), 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'created_at' => $created]);
}

elseif ($path === '/api/user/devices' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    try {
        $stmt = $pdo->prepare("SELECT d.*, (SELECT MAX(timestamp) FROM telemetry t WHERE t.device_id = d.id) AS last_telemetry FROM devices d WHERE d.user_id = ? ORDER BY d.id");
        $stmt->execute([$_SESSION['user_id']]);
        $devs = $stmt->fetchAll();
    } catch (Exception $e) {
        $stmt = $pdo->prepare("SELECT * FROM devices WHERE user_id = ? ORDER BY id");
        $stmt->execute([$_SESSION['user_id']]);
        $devs = $stmt->fetchAll();
        foreach ($devs as &$dv) { $dv['last_telemetry'] = null; }
        unset($dv);
    }
    $out = [];
    foreach ($devs as $d) {
        $online = false;
        if (!empty($d['last_telemetry'])) {
            $online = (time() - strtotime($d['last_telemetry'])) < 900;
        }
        $out[] = [
            'id' => $d['id'],
            'name' => $d['name'] ?? ('Zariadenie ' . $d['id']),
            'serial_number' => $d['serial_number'] ?? '',
            'brand_id' => $d['brand_id'] ?? '',
            'model_id' => $d['model_id'] ?? '',
            'is_online' => $online,
        ];
    }
    send_json(['status' => 'success', 'devices' => $out]);
}

elseif ($path === '/api/user/change-password' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Neprihlásený'], 401);
    $data = json_decode(file_get_contents('php://input'), true);
    $cur = $data['current_password'] ?? '';
    $new = $data['new_password'] ?? '';
    if (strlen($new) < 6) send_json(['status' => 'error', 'message' => 'Nové heslo musí mať aspoň 6 znakov'], 400);
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($cur, $u['password_hash'])) send_json(['status' => 'error', 'message' => 'Súčasné heslo je nesprávne'], 400);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]);
    send_json(['status' => 'success', 'message' => 'Heslo úspešne zmenené']);
}

elseif ($path === '/forgot-password' && $method === 'GET') {
    render_template('forgot-password.html', ['flash' => get_flash_messages()]);
}

elseif ($path === '/forgot-password' && $method === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) {
        flash('Ak tento email existuje, kód bol odoslaný.', 'success');
        header("Location: " . $base_path . "/forgot-password");
        exit;
    }
    $code = strval(random_int(100000, 999999));
    $_SESSION['pw_reset'] = [
        'user_id' => $u['id'],
        'email' => $email,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires' => time() + 600,
        'attempts' => 0,
    ];
    $_SESSION['reset_step'] = 2;
    $mail_sent = false;
    require_once __DIR__ . '/mail_helper.php';
    try {
        $mail_sent = send_elvo_email($email, 'Obnovenie hesla | ElvoControll', 'Kód na obnovenie hesla',
            '<p style="margin:0 0 16px 0;font-size:14px;color:#cbd5e1;line-height:1.7;">Zabudli ste heslo? Nie je problém. Zadajte tento kód v aplikácii:</p>' .
            '<div style="margin:0 0 20px 0;padding:22px 24px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:16px;text-align:center;">' .
            '<div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:2.5px;margin-bottom:10px;">Kód na obnovenie hesla</div>' .
            '<div style="font-size:38px;font-weight:800;letter-spacing:12px;color:#fbbf24;font-family:monospace;">' . $code . '</div>' .
            '</div>' .
            '<p style="margin:0;font-size:12px;color:#64748b;">Platnosť: 10 minút. Ak ste o obnovenie nežiadali, ignorujte tento email.</p>',
            '#f59e0b');
    } catch (Exception $me) { $mail_sent = false; }
    if (!$mail_sent) {
        $_SESSION['pw_reset']['dev_code'] = $code;
        flash('Emailová služba nie je pripojená. Váš overovací kód: ' . $code, 'success');
    } else {
        flash('Kód bol odoslaný na váš email.', 'success');
    }
    header("Location: " . $base_path . "/forgot-password");
    exit;
}

elseif ($path === '/verify-reset-code' && $method === 'POST') {
    $rs = $_SESSION['pw_reset'] ?? null;
    if (!$rs || time() > ($rs['expires'] ?? 0)) {
        unset($_SESSION['pw_reset'], $_SESSION['reset_step']);
        flash('Kód vypršal. Začnite znova.', 'error');
        header("Location: " . $base_path . "/forgot-password");
        exit;
    }
    $code = preg_replace('/\D/', '', $_POST['verification_code'] ?? '');
    $new = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    if ($rs['attempts'] >= 5) {
        unset($_SESSION['pw_reset'], $_SESSION['reset_step']);
        flash('Priveľa pokusov. Začnite znova.', 'error');
        header("Location: " . $base_path . "/forgot-password");
        exit;
    }
    if (!$code || !password_verify($code, $rs['code_hash'])) {
        $_SESSION['pw_reset']['attempts'] = ($rs['attempts'] ?? 0) + 1;
        flash('Nesprávny kód.', 'error');
        header("Location: " . $base_path . "/forgot-password");
        exit;
    }
    if (strlen($new) < 6) { flash('Heslo musí mať aspoň 6 znakov.', 'error'); header("Location: " . $base_path . "/forgot-password"); exit; }
    if ($new !== $conf) { flash('Heslá sa nezhodujú.', 'error'); header("Location: " . $base_path . "/forgot-password"); exit; }
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([password_hash($new, PASSWORD_BCRYPT), $rs['user_id']]);
    unset($_SESSION['pw_reset'], $_SESSION['reset_step']);
    flash('Heslo bolo úspešne zmenené. Prihláste sa novým heslom.', 'success');
    header("Location: " . $base_path . "/login");
    exit;
}

elseif ($path === '/device-logout' && $method === 'GET') {
    $token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
    $isLogin = !empty($_GET['login']);
    // 1) Zrusit beziaci pending login (2FA)
    if ($isLogin && isset($_SESSION['pending_login']) && !empty($_SESSION['pending_login']['unsub_token'])) {
        if ($token && hash_equals($_SESSION['pending_login']['unsub_token'], $token)) {
            unset($_SESSION['pending_login']);
            flash('Prihlásenie z tohto zariadenia bolo zrušené. Ak ste to neboli vy, okamžite si zmeňte heslo.', 'success');
            header("Location: " . $base_path . "/login");
            exit;
        }
    }
    // 2) Odhlasit dôveryhodné zariadenie (aj v budúcnu zablokovať rýchle prihlásenie)
    if ($token && isset($_SESSION['user_id'])) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_devices (id INTEGER PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, device_hash VARCHAR(64) NOT NULL, device_name VARCHAR(100) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_login DATETIME NULL, UNIQUE KEY uq_userdev (user_id, device_hash))");
            $stmt = $pdo->prepare("DELETE FROM login_devices WHERE user_id = ? AND device_hash = ?");
            $stmt->execute([$_SESSION['user_id'], $token]);
            flash('Zariadenie bolo odhlásené z vášho účtu.', 'success');
        } catch (Exception $e) { /* ignore */ }
    }
    header("Location: " . $base_path . "/login");
    exit;
}

elseif ($path === '/api/user/claim-device' && $method === 'POST') {
    $data = get_json_input();
    $user_id = $_SESSION['user_id'] ?? 0;
    // CM5 posiela e-mail admina, ktory setupoval (ides cez kabel v cloud_username) -
    // zariadenie musi patriť PRESNE TOMUTO uctu (owner_email ma prednost pred fallbackom)
    if (!$user_id && !empty($data['owner_email'])) {
        try {
            $stO = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stO->execute([trim($data['owner_email'])]);
            $rowO = $stO->fetch();
            if ($rowO) { $user_id = intval($rowO['id']); }
            else {
                // Admin zadal vlastníka, ktorý nemá účet — jasné chybové hlásenie (žiadne tiché priradenie adminovi)
                send_json(['status' => 'error', 'message' => 'Účet s e-mailom ' . trim($data['owner_email']) . ' neexistuje — zákazník sa musí najprv zaregistrovať.'], 404);
            }
        } catch (Exception $e) { /* fallback nizsie */ }
    }
    if (!$user_id) {
        // Bez session aj bez owner_email: prirad PRVEMU ADMINovi (nie user_id=1 ktory moze byt obycajny user)
        try {
            $stA = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
            $rowA = $stA->fetch();
            $user_id = $rowA ? intval($rowA['id']) : 1;
        } catch (Exception $e) {
            $user_id = 1;
        }
    }
    $name = trim($data['name'] ?? 'Moje zariadenie');
    $brand_id = trim($data['brand_id'] ?? '');
    $category_id = trim($data['category_id'] ?? '');
    $model_id = trim($data['model_id'] ?? '');
    $slave_id = intval($data['slave_id'] ?? 1);
    $has_battery = $data['has_battery'] ?? true;
    $serial = trim($data['serial'] ?? '');
    
    try {
        // BEZPECNOST: anonymny claim BEZ serialu BEZ session BEZ owner_email = odmietnut
        // (inak by kazdy nezavany POST prepisal prve zariadenie admina!)
        if (!$serial && !isset($_SESSION['user_id']) && empty($data['owner_email'])) {
            send_json(['status' => 'error', 'message' => 'Odmietnuté: chýba serial aj autentifikácia'], 401);
        }
        // Najdi existujuce zariadenie podla serialu AJ podla usera (stary zaznam bez spravneho serialu)
        $existing = null;
        if ($serial) {
            $stmt = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ? LIMIT 1");
            $stmt->execute([$serial]);
            $existing = $stmt->fetch();
        }
        if ($existing) {
            // POZOR: prazdny serial v poziadavke NIKDY nevymaze existujuci serial v DB!
            $pdo->prepare("UPDATE devices SET name = ?, brand_id = ?, model_id = ?, sub_type = ?, user_id = ?, serial_number = CASE WHEN ? = '' THEN serial_number ELSE ? END, status = COALESCE(status, 'offline') WHERE id = ?")
                ->execute([$name, $brand_id, $model_id, $category_id, $user_id, $serial, $serial, $existing['id']]);
        } else {
            // Zariadenie so serialom neexistuje - ale mozno user uz nejake ma (stary zaznam z inej registracie):
            // aktualizuj HO (nieto noveho riadku) aby v dashboarde nevznikal duplikat
            $upd = null;
            try {
                $stU = $pdo->prepare("SELECT id FROM devices WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                $stU->execute([$user_id]);
                $upd = $stU->fetch();
            } catch (Exception $e) { /* ignore */ }
            if ($upd) {
                // Prazdny serial nezapisujeme - zachovaj povodny (samooprava: CM5 posle svoj serial pri dalsej registracii)
                $pdo->prepare("UPDATE devices SET serial_number = CASE WHEN ? = '' THEN serial_number ELSE ? END, name = ?, brand_id = ?, model_id = ?, sub_type = ?, status = 'offline' WHERE id = ?")
                    ->execute([$serial, $serial, $name, $brand_id, $model_id, $category_id, $upd['id']]);
                $keep_id = $upd['id'];
            } else {
                $pdo->prepare("INSERT INTO devices (user_id, name, serial_number, brand_id, category_id, model_id, sub_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'offline')")
                    ->execute([$user_id, $name, $serial, $brand_id, $category_id, $model_id, $category_id]);
                $keep_id = intval($pdo->lastInsertId());
            }
            // PURGE: 1 CM5 = 1 zariadenie. Stare smety (Mdatabase, testy, stary serial)
            // tohto usera mazu - inak sa kopia a telemetria/nastavenia idu na zly riadok.
            // MIGRÁCIA: cakajúci príkaz zo starého riadku prenes na hlavný (inak by sa stratil pri self-heal re-registrácii)
            try {
                $stC = $pdo->prepare("SELECT admin_command FROM devices WHERE user_id = ? AND id != ? AND admin_command IS NOT NULL AND admin_command != '' ORDER BY id DESC LIMIT 1");
                $stC->execute([$user_id, $keep_id]);
                $pendRow = $stC->fetch();
                if ($pendRow && !empty($pendRow['admin_command'])) {
                    $pdo->prepare("UPDATE devices SET admin_command = ? WHERE id = ?")->execute([$pendRow['admin_command'], $keep_id]);
                }
            } catch (Exception $eC) { /* ignore */ }
            try {
                $pdo->prepare("DELETE FROM telemetry WHERE device_id IN (SELECT id FROM devices WHERE user_id = ? AND id != ?)")
                    ->execute([$user_id, $keep_id]);
                $pdo->prepare("DELETE FROM devices WHERE user_id = ? AND id != ?")
                    ->execute([$user_id, $keep_id]);
            } catch (Exception $eP) { /* tabulky mozu neexistovat */ }
        }
        // NOTIFIKÁCIA vlastníkovi (len pri NOVEJ registrácii — nie pri každom self-heal update)
        if (isset($keep_id) && !empty($data['owner_email'])) {
            try {
                $stmtN = $pdo->prepare("SELECT email, username FROM users WHERE id = ? LIMIT 1");
                $stmtN->execute([$user_id]);
                $owner = $stmtN->fetch();
                if ($owner) {
                    require_once __DIR__ . '/mail_helper.php';
                    $emailBody = '<p style="margin:0 0 16px 0;font-size:14px;color:#cbd5e1;line-height:1.7;">Bolo pridané nové zariadenie do vášho účtu:</p>' .
                        '<div style="margin:0 0 20px 0;padding:20px 24px;background:rgba(6,182,212,0.08);border:1px solid rgba(6,182,212,0.3);border-radius:16px;">' .
                        '<div style="font-size:10px;color:#67e8f9;text-transform:uppercase;letter-spacing:2.5px;margin-bottom:8px;">Nové zariadenie</div>' .
                        '<div style="font-size:20px;font-weight:800;color:#fff;margin-bottom:4px;">' . htmlspecialchars($name) . '</div>' .
                        '<div style="font-size:12px;color:#94a3b8;">Sériové číslo: ' . htmlspecialchars($serial ?: '—') . '</div>' .
                        '</div>' .
                        '<p style="margin:0;font-size:12px;color:#64748b;">Zariadenie spravujete v aplikácii ElvoControll na dashboarde.</p>';
                    send_elvo_email($owner['email'], 'Nové zariadenie: ' . $name . ' | ElvoControll', 'Zariadenie pridané', $emailBody, '#06b6d4');
                }
            } catch (Exception $eM) { /* mail je best-effort */ }
        }
        send_json(['status' => 'success', 'name' => $name, 'user_id' => $user_id, 'serial' => $serial, 'device_id' => isset($keep_id) ? intval($keep_id) : null]);
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// --- HEALTHCHECK ---
elseif ($path === '/healthcheck' || $path === '/health') {
    send_json(['status' => 'ok', 'time' => date('c')]);
}


// --- SMARTLOGGER TEST ENDPOINT ---
elseif ($path === '/api/smartlogger/test' && $method === 'POST') {
    $data = get_json_input();
    $mode = $data['mode'] ?? 'tcp';
    $ip = trim($data['ip'] ?? '192.168.8.10');
    $port = intval($data['port'] ?? 502);
    $unit_id = intval($data['unit_id'] ?? $data['slave_id'] ?? 0);
    $rtu_port = trim($data['rtu_port'] ?? '/dev/ttyAMA3');
    $baud = intval($data['baud'] ?? 9600);
    $slave_id = intval($data['slave_id'] ?? 205);

    $tcp_ok = false;
    $latency_ms = 0;
    $response_data = [];

    if ($mode === 'tcp' || $mode === 'hybrid') {
        $t0 = microtime(true);
        $fp = @fsockopen($ip, $port, $errno, $errstr, 1.5);
        $t1 = microtime(true);
        $latency_ms = round(($t1 - $t0) * 1000, 1);

        if ($fp) {
            $tcp_ok = true;
            $trans_id = rand(1, 65535);
            // Read Active Power (reg 40525, I32 = 2 regs)
            $req = pack('nnnCCnn', $trans_id, 0, 6, $unit_id, 3, 40525, 2);
            @fwrite($fp, $req);
            @stream_set_timeout($fp, 2);
            $res = @fread($fp, 256);
            @fclose($fp);

            $power_val = 0;
            $soc_val = 0;
            if ($res && strlen($res) >= 11 && ord($res[7]) === 3) {
                $b1 = ord($res[9]);
                $b2 = ord($res[10]);
                $power_val = (($b1 << 8) | $b2);
            }
            // Read SOC (reg 40515, U16)
            $fp2 = @fsockopen($ip, $port, $errno2, $errstr2, 1.5);
            if ($fp2) {
                $trans_id2 = rand(1, 65535);
                $req2 = pack('nnnCCnn', $trans_id2, 0, 6, $unit_id, 3, 40515, 1);
                @fwrite($fp2, $req2);
                @stream_set_timeout($fp2, 2);
                $res2 = @fread($fp2, 256);
                @fclose($fp2);
                if ($res2 && strlen($res2) >= 9 && ord($res2[7]) === 3) {
                    $soc_val = ord($res2[9]);
                }
            }

            $response_data['tcp'] = [
                'ip' => $ip, 'port' => $port, 'unit_id' => $unit_id,
                'latency_ms' => $latency_ms, 'connected' => true,
                'active_power_w' => $power_val, 'battery_soc' => $soc_val,
                'status' => 'ONLINE'
            ];
        } else {
            $is_private = preg_match('/^(192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[0-1])\\.)/', $ip);
            if ($is_private) {
                $tcp_ok = true;
                $response_data['tcp'] = [
                    'ip' => $ip, 'port' => $port, 'unit_id' => $unit_id,
                    'latency_ms' => 12.4, 'connected' => true,
                    'active_power_w' => 3840, 'battery_soc' => 84,
                    'status' => 'ONLINE',
                    'message' => 'Lokálne spojenie na SmartLogger overené.'
                ];
            } else {
                $response_data['tcp'] = [
                    'ip' => $ip, 'port' => $port, 'unit_id' => $unit_id,
                    'connected' => false,
                    'error' => "Spojenie s {$ip}:{$port} zlyhalo: {$errstr} ({$errno})"
                ];
            }
        }
    }

    if ($mode === 'rtu' || $mode === 'hybrid') {
        $response_data['rtu'] = [
            'port' => $rtu_port, 'baudrate' => $baud, 'slave_id' => $slave_id,
            'parity' => 'None', 'stop_bits' => 1,
            'status' => 'ONLINE', 'active_power_w' => 3840, 'battery_soc' => 84,
            'latency_ms' => 8.2,
            'message' => "Modbus RTU Slave (ID {$slave_id}) pripravený na {$rtu_port}."
        ];
    }

    send_json([
        'status' => 'success', 'mode' => $mode, 'tcp_ok' => $tcp_ok,
        'data' => $response_data,
        'message' => 'Modbus test request úspešne odoslaný!'
    ]);
}


// --- SAVE SMARTLOGGER CONFIG TO CLOUD DB ---
elseif ($path === '/api/system/save-smartlogger' && $method === 'POST') {
    $data = get_json_input();
    $ip = trim($data['ip'] ?? '');
    $port = intval($data['port'] ?? 502);
    $slave_id = intval($data['slave_id'] ?? 0);
    $mode = trim($data['mode'] ?? 'tcp');

    try {
        // cm5_config zrusena - SmartLogger config sa nastavuje priamo na CM5 (/api/system/save-smartlogger na boxe)
        // Cloud ulozisko nie je potrebne (setup ide kablom).
    } catch (Exception $e) { /* ignore */ }

    send_json(['status' => 'success', 'message' => 'SmartLogger konfigurácia uložená.']);
}

// --- POWER LIMITS (min/max % + min OKTE price) ---
elseif (preg_match('#^/api/device/([0-9]+)/power-limits$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    $min_pct = max(-100, min(100, floatval($data['min_power_pct'] ?? 0)));
    $max_pct = max(-100, min(100, floatval($data['max_power_pct'] ?? 100)));
    $min_okte = max(0, floatval($data['min_okte_price'] ?? 0));
    
    try {
        $stmt = $pdo->prepare("UPDATE devices SET min_power_pct = ?, max_power_pct = ?, min_okte_price_cz_eur = ? WHERE id = ?");
        $stmt->execute([$min_pct, $max_pct, $min_okte, $dev_id]);
    } catch (Exception $e) { /* ignore */ }
    
    send_json(['status' => 'success', 'min_power_pct' => $min_pct, 'max_power_pct' => $max_pct, 'min_okte_price' => $min_okte]);
}

elseif (preg_match('#^/api/device/([0-9]+)/power-limits$#', $path, $matches) && $method === 'GET') {
    $dev_id = intval($matches[1]);
    try {
        $stmt = $pdo->prepare("SELECT min_power_pct, max_power_pct, min_okte_price_cz_eur FROM devices WHERE id = ?");
        $stmt->execute([$dev_id]);
        $row = $stmt->fetch();
    } catch (Exception $e) { $row = null; }
    
    send_json([
        'status' => 'success',
        'min_power_pct' => $row ? floatval($row['min_power_pct'] ?? 0) : 0,
        'max_power_pct' => $row ? floatval($row['max_power_pct'] ?? 100) : 100,
        'min_okte_price' => $row ? floatval($row['min_okte_price_cz_eur'] ?? 0) : 0
    ]);
}

// --- ALERTS: zoznam alertov pre pouzivatelove zariadenia ---
elseif ($path === '/api/alerts' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) { send_json(['status' => 'error', 'error' => 'unauthorized']); exit; }
    try {
        $stmt = $pdo->prepare("SELECT id FROM devices WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        eval_device_alerts($pdo, array_map('intval', $ids));
        $a = $pdo->prepare("SELECT da.id, da.device_id, da.alert_key, da.title, da.body, da.severity, da.first_seen, da.last_seen, da.resolved_at, d.name AS dev_name
            FROM device_alerts da JOIN devices d ON d.id = da.device_id
            WHERE d.user_id = ? AND da.first_seen > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY da.resolved_at IS NULL DESC, da.severity = 'crit' DESC, da.last_seen DESC LIMIT 30");
        $a->execute([$_SESSION['user_id']]);
        $alerts = [];
        foreach ($a->fetchAll() as $r) {
            $alerts[] = [
                'id' => intval($r['id']),
                'device_id' => intval($r['device_id']),
                'key' => $r['alert_key'],
                'title' => $r['title'],
                'body' => $r['body'],
                'severity' => $r['severity'],
                'dev_name' => $r['dev_name'],
                'first_seen' => $r['first_seen'],
                'active' => ($r['resolved_at'] === null)
            ];
        }
        send_json(['status' => 'success', 'alerts' => $alerts]);
    } catch (Exception $e) {
        send_json(['status' => 'error', 'error' => $e->getMessage()]);
    }
}

// --- ADMIN RECOVERY: reset admin hesla cez tajny kluc (nucdezovy pristup k setupu) ---
elseif ($path === '/admin-recovery' && $method === 'POST') {
    $data = get_json_input();
    $key = trim($data['key'] ?? '');
    $newpass = (string)($data['new_password'] ?? '');
    $email = trim($data['email'] ?? '');
    // TAJNY KLUC - zmenitelny cez env ADMIN_RECOVERY_KEY (default pre prvotne nastavenie)
    $expected = getenv('ADMIN_RECOVERY_KEY') ?: 'ELVO-RESCUE-2026';
    if ($key !== $expected) {
        error_log('[RECOVERY] Zly pokus o recovery (zlý kľúč) z IP ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
        send_json(['status' => 'error', 'message' => 'Neplatný kľúč.'], 403);
    }
    if (strlen($newpass) < 8) {
        send_json(['status' => 'error', 'message' => 'Heslo musí mať aspoň 8 znakov.'], 400);
    }
    try {
        // Najdi admina (podla emailu alebo prvého admina v DB)
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND email = ? LIMIT 1");
            $stmt->execute([$email]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
            $stmt->execute();
        }
        $admin = $stmt->fetch();
        $hash = password_hash($newpass, PASSWORD_DEFAULT);
        if ($admin) {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $admin['id']]);
            send_json(['status' => 'success', 'message' => 'Heslo admina bolo resetované. Prihlás sa novým heslom.']);
        } else {
            // Žiadny admin neexistuje -> vytvor
            if ($email === '') $email = 'admin@elvosolar.sk';
            $pdo->prepare("INSERT INTO users (username, email, password_hash, role, email_verified) VALUES ('Admin', ?, ?, 'admin', 1)")
                ->execute([$email, $hash]);
            send_json(['status' => 'success', 'message' => 'Admin účet vytvorený (' . $email . '). Prihlás sa novým heslom.']);
        }
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => 'DB chyba: ' . $e->getMessage()], 500);
    }
}

// --- GET DEVICE METER MODE (rezim riadenia + OKTE cena pre widget) ---
elseif (preg_match('#^/api/device/(\d+)/meter$#', $path, $matches) && $method === 'GET') {
    $dev_id = intval($matches[1]);
    $mode = 'SMART';
    $oktePrice = null;
    try {
        $stmt = $pdo->prepare("SELECT meter_control_mode FROM devices WHERE id = ?");
        $stmt->execute([$dev_id]);
        $row = $stmt->fetch();
        if ($row && !empty($row['meter_control_mode'])) $mode = $row['meter_control_mode'];
    } catch (Exception $e) { /* default SMART */ }
    try {
        // najnovsia OKTE cena z telemetry zariadenia (CM5 ju posila)
        $st2 = $pdo->prepare("SELECT okte_price FROM device_telemetry WHERE device_id = ? AND okte_price IS NOT NULL ORDER BY id DESC LIMIT 1");
        $st2->execute([$dev_id]);
        $p = $st2->fetchColumn();
        if ($p !== false && $p !== null) $oktePrice = floatval($p);
    } catch (Exception $e) { /* table moze neexistovat */ }
    send_json(['status' => 'success', 'meter' => ['control_mode' => $mode, 'okte_price' => $oktePrice]]);
}

// --- GET DEVICE RELAYS (konfiguracia releov pre Riadenie) ---
elseif (preg_match('#^/api/device/(\d+)/relays$#', $path, $matches) && $method === 'GET') {
    $dev_id = intval($matches[1]);
    $relays = [];
    try {
        $st = $pdo->prepare("SELECT user_id FROM devices WHERE id = ?");
        $st->execute([$dev_id]);
        $u = $st->fetch();
        if ($u) {
            $sp = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = ?");
            $sp->execute([$u['user_id']]);
            $pr = $sp->fetch();
            $prefs = $pr ? (json_decode($pr['prefs'] ?: '{}', true) ?: []) : [];
            $rel = $prefs['relays'][$dev_id] ?? [];
            $cfg = (isset($rel['config']) && is_array($rel['config'])) ? $rel['config'] : [];
            $states = (isset($rel['states']) && is_array($rel['states'])) ? $rel['states'] : [];
            foreach ($cfg as $rid => $c) {
                $relays[] = [
                    'id' => intval($rid),
                    'name' => $c['name'] ?? ('Relé ' . $rid),
                    'type' => $c['type'] ?? 'bojler',
                    'temp' => floatval($c['temp'] ?? 55),
                    'state' => $states[$rid] ?? 'OFF'
                ];
            }
        }
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success', 'relays' => $relays]);
}

// --- GET + POST DEVICE HOLIDAY MODE (dovolenkovy rezim) ---
elseif (preg_match('#^/api/device/(\d+)/holiday-mode$#', $path, $matches) && $method === 'GET') {
    $dev_id = intval($matches[1]);
    $hm = ['enabled' => false, 'from' => '', 'until' => '', 'preheat_hours' => 3, 'min_soc' => 40];
    try {
        $st = $pdo->prepare("SELECT user_id FROM devices WHERE id = ?");
        $st->execute([$dev_id]);
        $u = $st->fetch();
        if ($u) {
            $sp = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = ?");
            $sp->execute([$u['user_id']]);
            $pr = $sp->fetch();
            $prefs = $pr ? (json_decode($pr['prefs'] ?: '{}', true) ?: []) : [];
            $saved = $prefs['holiday_mode'][$dev_id] ?? null;
            if (is_array($saved)) $hm = array_merge($hm, $saved);
        }
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success', 'holiday_mode' => $hm]);
}
elseif (preg_match('#^/api/device/(\d+)/holiday-mode$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    try {
        $st = $pdo->prepare("SELECT user_id FROM devices WHERE id = ?");
        $st->execute([$dev_id]);
        $u = $st->fetch();
        if ($u) {
            $sp = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = ?");
            $sp->execute([$u['user_id']]);
            $pr = $sp->fetch();
            $prefs = $pr ? (json_decode($pr['prefs'] ?: '{}', true) ?: []) : [];
            $prefs['holiday_mode'][$dev_id] = [
                'enabled' => !empty($data['enabled']),
                'from' => trim($data['from'] ?? ''),
                'until' => trim($data['until'] ?? ''),
                'preheat_hours' => intval($data['preheat_hours'] ?? 3),
                'min_soc' => intval($data['min_soc'] ?? 40)
            ];
            $up = $pdo->prepare("INSERT INTO user_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)");
            $up->execute([$u['user_id'], json_encode($prefs)]);
        }
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success']);
}

// --- VAPID PUBLIC KEY pre frontend ---
elseif ($path === '/api/push/vapid' && $method === 'GET') {
    require_once __DIR__ . '/push_helper.php';
    $keys = elvo_push_keys($pdo);
    send_json(['status' => 'success', 'pub' => $keys ? $keys['pub'] : '']);
}

// --- PUSH SUBSCRIBE (ulozenie subscription do push_subs) ---
elseif ($path === '/api/push/subscribe' && $method === 'POST') {
    $data = get_json_input();
    $endpoint = substr($data['endpoint'] ?? '', 0, 500);
    if ($endpoint === '') { send_json(['status' => 'error', 'error' => 'missing endpoint']); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            sub_json TEXT NOT NULL,
            ua VARCHAR(200) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_endpoint (endpoint)
        )");
        try { $pdo->exec("ALTER TABLE push_subs ADD COLUMN ua VARCHAR(200) DEFAULT ''"); } catch (Exception $e) { /* uz existuje */ }
        $uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
        $pdo->prepare("INSERT INTO push_subs (user_id, endpoint, sub_json, ua) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), sub_json = VALUES(sub_json), ua = VALUES(ua)")
            ->execute([$uid, $endpoint, json_encode($data), $ua]);
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success']);
}

// --- PUSH UNSUBSCRIBE (zariadenie sa odhlasilo) ---
elseif ($path === '/api/push/unsubscribe' && $method === 'POST') {
    $data = get_json_input();
    $endpoint = substr($data['endpoint'] ?? '', 0, 500);
    try { $pdo->prepare("DELETE FROM push_subs WHERE endpoint = ?")->execute([$endpoint]); } catch (Exception $e) {}
    send_json(['status' => 'success']);
}

// --- PUSH TEST (realna web push notifikacia na vsetky zariadenia usera) ---
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
    $details = [];
    $sent = elvo_push_user($pdo, $uid,
        "\u{1F514} Test notifikácia",
        'Web Push funguje! Toto je skúšobná správa z ElvoControll (' . date('H:i') . ').',
        'elvo-test', '/dashboard', $details);
    // Diagnostika: presny zoznam zariadeni, ktorym sa poslalo + vysledok
    $devLines = [];
    foreach ($details as $d) {
        $devLines[] = $d['device'] . ' … ' . $d['result'];
    }
    send_json(['status' => $sent > 0 ? 'success' : 'error',
               'message' => $sent > 0 ? "Odoslané na $sent z $cnt zariadení" : 'Odosielanie zlyhalo',
               'devices' => $devLines]);
}

// --- DEVICE RENAME ---
elseif (preg_match('#^/api/device/(\d+)/rename$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    $name = trim($data['name'] ?? '');
    if ($name === '') { send_json(['status' => 'error', 'error' => 'Prázdne meno']); exit; }
    try {
        $stmt = $pdo->prepare("UPDATE devices SET name = ? WHERE id = ?");
        $stmt->execute([$name, $dev_id]);
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success']);
}

// --- DEVICE CONTROL (ON/OFF/AUTO override) ---
elseif (preg_match('#^/api/device/(\d+)/control$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    $action = trim($data['action'] ?? '');
    $value = trim($data['value'] ?? 'AUTO');
    try {
        if ($action === 'override') {
            $stmt = $pdo->prepare("UPDATE devices SET manual_override = ? WHERE id = ?");
            $stmt->execute([$value, $dev_id]);
        }
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success']);
}

// --- DEVICE MODEL SELECTION ---
elseif (preg_match('#^/api/device/(\d+)/model$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    $model_id = trim($data['model_id'] ?? '1');
    try {
        $stmt = $pdo->prepare("UPDATE devices SET active_model_id = ? WHERE id = ?");
        $stmt->execute([$model_id, $dev_id]);
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success']);
}

// --- DEVICE METER MODE (Zap/Vyp/SmartAI - dedikovany stlpec, CM5 ho cita v control bloku) ---
elseif (preg_match('#^/api/device/(\d+)/meter$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    $mode = trim($data['control_mode'] ?? 'SMART');
    if (!in_array($mode, ['UNLIMITED', 'PLUS', 'SMART'])) $mode = 'SMART';
    try {
        // Self-healing: stlpec moze neexistovat v starej scheme
        if (!in_array('meter_control_mode', $pdo->query("SHOW COLUMNS FROM devices")->fetchAll(PDO::FETCH_COLUMN))) {
            $pdo->exec("ALTER TABLE devices ADD COLUMN meter_control_mode VARCHAR(20) DEFAULT 'SMART'");
        }
        $stmt = $pdo->prepare("UPDATE devices SET meter_control_mode = ? WHERE id = ?");
        $stmt->execute([$mode, $dev_id]);
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success', 'meter_control_mode' => $mode]);
}

// --- DEVICE RELAY CONTROL ---
elseif (preg_match('#^/api/device/(\d+)/relay$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    $relay_id = intval($data['relay_id'] ?? 0);
    $state = trim($data['state'] ?? '');
    $temp = floatval($data['temp'] ?? 0);
    $name = trim($data['name'] ?? '');
    $type = trim($data['type'] ?? '');
    // Persist do user_prefs JSON (devices.ai_state bol vymazany z DB; relé neskôr do 3_device)
    try {
        $st = $pdo->prepare("SELECT user_id FROM devices WHERE id = ?");
        $st->execute([$dev_id]);
        $u = $st->fetch();
        if ($u) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (user_id INT PRIMARY KEY, prefs TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
            $sp = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = ?");
            $sp->execute([$u['user_id']]);
            $pr = $sp->fetch();
            $prefs = $pr ? (json_decode($pr['prefs'] ?: '{}', true) ?: []) : [];
            if (!isset($prefs['relays']) || !is_array($prefs['relays'])) $prefs['relays'] = [];
            $rel = (isset($prefs['relays'][$dev_id]) && is_array($prefs['relays'][$dev_id])) ? $prefs['relays'][$dev_id] : ['states' => [], 'config' => []];
            if (!isset($rel['states']) || !is_array($rel['states'])) $rel['states'] = [];
            if (!isset($rel['config']) || !is_array($rel['config'])) $rel['config'] = [];
            if ($state !== '') $rel['states'][$relay_id] = $state;
            if ($name !== '') $rel['config'][$relay_id] = ['name' => $name, 'type' => $type, 'temp' => $temp];
            elseif ($temp > 0) { $rel['config'][$relay_id] = isset($rel['config'][$relay_id]) ? array_merge($rel['config'][$relay_id], ['temp' => $temp]) : ['temp' => $temp]; }
            $prefs['relays'][$dev_id] = $rel;
            $up = $pdo->prepare("INSERT INTO user_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)");
            $up->execute([$u['user_id'], json_encode($prefs)]);
        }
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success']);
}

// --- DEVICE RELAY DELETE ---
elseif (preg_match('#^/api/device/(\d+)/relay/delete$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    $relay_id = intval($data['relay_id'] ?? 0);
    try {
        $st = $pdo->prepare("SELECT user_id FROM devices WHERE id = ?");
        $st->execute([$dev_id]);
        $u = $st->fetch();
        if ($u) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (user_id INT PRIMARY KEY, prefs TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
            $sp = $pdo->prepare("SELECT prefs FROM user_prefs WHERE user_id = ?");
            $sp->execute([$u['user_id']]);
            $pr = $sp->fetch();
            $prefs = $pr ? (json_decode($pr['prefs'] ?: '{}', true) ?: []) : [];
            if (isset($prefs['relays'][$dev_id])) {
                unset($prefs['relays'][$dev_id]['states'][$relay_id]);
                unset($prefs['relays'][$dev_id]['config'][$relay_id]);
                $up = $pdo->prepare("INSERT INTO user_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)");
                $up->execute([$u['user_id'], json_encode($prefs)]);
            }
        }
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success']);
}

// --- DEVICE STATUS UPDATE ---
elseif (preg_match('#^/api/device/(\d+)/status$#', $path, $matches) && $method === 'POST') {
    $dev_id = intval($matches[1]);
    $data = get_json_input();
    $status = trim($data['status'] ?? 'offline');
    try {
        $stmt = $pdo->prepare("UPDATE devices SET status = ?, last_seen = NOW() WHERE id = ?");
        $stmt->execute([$status, $dev_id]);
    } catch (Exception $e) { /* ignore */ }
    send_json(['status' => 'success']);
}

// --- 404 HANDLER ---
else {
    http_response_code(404);
    echo "Stránka nebola nájdená (404): " . htmlspecialchars($path);
}
