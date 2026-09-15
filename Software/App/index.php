<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Europe/Bratislava');
session_start();
require_once 'config.php';

// === AUTO-MIGRÁCIA: Pridanie chýbajúcich stĺpcov ===
if (isset($pdo)) {
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
        if (!in_array('min_power_w', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN min_power_w FLOAT DEFAULT 0");
        if (!in_array('max_power_w', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN max_power_w FLOAT DEFAULT 10000");
        if (!in_array('min_power_pct', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN min_power_pct FLOAT DEFAULT 0");
        if (!in_array('max_power_pct', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN max_power_pct FLOAT DEFAULT 100");
        if (!in_array('active_model_id', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN active_model_id VARCHAR(10) DEFAULT '1'");
        if (!in_array('night_sleep', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN night_sleep TINYINT DEFAULT 0");
        if (!in_array('connection_type', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN connection_type VARCHAR(20) DEFAULT 'modbus_tcp'");
        if (!in_array('smartlogger_ip', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN smartlogger_ip VARCHAR(50) DEFAULT '192.168.0.10'");
        if (!in_array('smartlogger_port', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN smartlogger_port INTEGER DEFAULT 502");
        if (!in_array('modbus_slave_id', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN modbus_slave_id INTEGER DEFAULT 205");
        if (!in_array('min_okte_price_cz_eur', $cols)) $pdo->exec("ALTER TABLE devices ADD COLUMN min_okte_price_cz_eur FLOAT DEFAULT 0");
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
            battery_soc FLOAT DEFAULT 84,
            power_ac FLOAT DEFAULT 3840,
            temp FLOAT DEFAULT 32.5,
            freq FLOAT DEFAULT 50.0,
            status_msg VARCHAR(255) DEFAULT 'Online',
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
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

// === CORE TABLES (users, devices, password_resets) ===
if (isset($pdo)) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
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
            name VARCHAR(150) DEFAULT 'Moje zariadenie',
            serial_number VARCHAR(100) DEFAULT '',
            brand VARCHAR(50) DEFAULT 'HUAWEI',
            brand_id VARCHAR(20) DEFAULT 'huawei',
            model_name VARCHAR(150) DEFAULT '',
            model_id VARCHAR(20) DEFAULT '',
            sub_type VARCHAR(50) DEFAULT '',
            status VARCHAR(20) DEFAULT 'offline',
            last_seen DATETIME NULL,
            battery_soc FLOAT DEFAULT 0,
            fve_power_w FLOAT DEFAULT 0,
            grid_power_w FLOAT DEFAULT 0,
            temp FLOAT DEFAULT 25.0,
            min_power_w FLOAT DEFAULT 0,
            max_power_w FLOAT DEFAULT 10000,
            min_power_pct FLOAT DEFAULT 0,
            max_power_pct FLOAT DEFAULT 100,
            active_model_id VARCHAR(10) DEFAULT '1',
            night_sleep TINYINT DEFAULT 0,
            connection_type VARCHAR(20) DEFAULT 'modbus_rtu',
            smartlogger_ip VARCHAR(50) DEFAULT '',
            smartlogger_port INTEGER DEFAULT 502,
            modbus_slave_id INTEGER DEFAULT 205,
            baud_rate INTEGER DEFAULT 9600,
            parity VARCHAR(10) DEFAULT 'none',
            stop_bits INTEGER DEFAULT 1,
            serial_port VARCHAR(50) DEFAULT '',
            total_saved_eur FLOAT DEFAULT 0,
            total_kwh FLOAT DEFAULT 0
        )");
    } catch (Exception $e) { /* ignore */ }
}

// === DEMO USER SEED ===
if (isset($pdo)) {
    try {
        $demoCheck = $pdo->query("SELECT id FROM users WHERE email = 'demo@elvosolar.sk' LIMIT 1")->fetch();
        if (!$demoCheck) {
            $demoHash = password_hash('demo123', PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username, email, password_hash, email_verified) VALUES (?, ?, ?, 1)")
                 ->execute(['Demo ElvoSolar', 'demo@elvosolar.sk', $demoHash]);
            $demoUserId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO devices (user_id, name, serial_number, brand, model_name, status, battery_soc, fve_power_w, grid_power_w, min_power_w, max_power_w, min_power_pct, max_power_pct, active_model_id, connection_type, smartlogger_ip, smartlogger_port, modbus_slave_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                 ->execute([$demoUserId, 'ElvoControll Demo', 'DEMO-CM5-001', 'HUAWEI', 'SmartLogger 3000 / SUN2000', 'online', 84, 3840, -450, 0, 10000, 0, 100, '1', 'modbus_tcp', '192.168.0.10', 502, 205]);
        }
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

// --- SESSION TIMEOUT ---
$stay_logged_in = $_SESSION['stay_logged_in'] ?? false;
$timeout_seconds = $stay_logged_in ? (90 * 24 * 3600) : (30 * 60);

$no_timeout_paths = ['/login', '/register', '/forgot-password', '/verify-reset-code', '/setup', '/setup.html', '/api/cm5/poll', '/api/cm5/result', '/api/cloud/sync-telemetry', '/api/report-ip', '/api/cm5/register', '/healthcheck'];
$apply_timeout = true;
foreach ($no_timeout_paths as $ntp) {
    if (strpos($path, $ntp) === 0) { $apply_timeout = false; break; }
}

if ($apply_timeout && isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_seconds) {
        session_unset();
        session_destroy();
        header("Location: " . $base_path . "/login");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// --- HELPER FUNKCIE ---
if (!function_exists('send_json')) {
    function send_json($data, $status = 200) {
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
            header("Content-Type: " . ($mime_types[$ext] ?? 'image/png'));
            readfile($img_path);
            exit;
        }
    }
}

// --- OKTE SPOT CENY ---
function fetch_okte_prices($date_from = null, $date_to = null) {
    if (!$date_from) $date_from = date('Y-m-d');
    if (!$date_to) $date_to = date('Y-m-d');
    
    $cache_file = __DIR__ . '/cache_okte_' . $date_from . '_' . $date_to . '.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 900) {
        return json_decode(file_get_contents($cache_file), true);
    }
    
    $base_prices = [
        '00:00' => 58.20, '01:00' => 52.40, '02:00' => 48.90, '03:00' => 46.50,
        '04:00' => 49.80, '05:00' => 64.20, '06:00' => 88.50, '07:00' => 118.40,
        '08:00' => 132.80, '09:00' => 112.50, '10:00' => 84.60, '11:00' => 62.30,
        '12:00' => 45.20, '13:00' => 42.50, '14:00' => 48.90, '15:00' => 74.50,
        '16:00' => 105.20, '17:00' => 138.60, '18:00' => 148.00, '19:00' => 142.50,
        '20:00' => 126.80, '21:00' => 104.20, '22:00' => 82.50, '23:00' => 65.40
    ];
    $prices = [];
    $total = 0; $min = PHP_INT_MAX; $max = PHP_INT_MIN;
    $idx = 0;
    foreach ($base_prices as $h => $p) {
        $prices[] = ['hour' => $h, 'price' => $p, 'period' => $idx + 1];
        $total += $p;
        if ($p < $min) $min = $p;
        if ($p > $max) $max = $p;
        $idx++;
    }
    $result = [
        'date_from' => $date_from, 'date_to' => $date_to, 'prices' => $prices,
        'avg' => round($total / count($prices), 2), 'min' => $min, 'max' => $max, 'range_type' => '24h'
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
    if ($method === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['last_activity'] = time();
            header("Location: " . $base_path . "/");
            exit;
        } else {
            flash("Nesprávne prihlasovacie údaje.", 'error');
        }
    }
    render_template('prihlasenie.html', ['flash' => get_flash_messages()]);
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
            flash('Účet vytvorený. Môžete sa prihlásiť.', 'success');
            header("Location: " . $base_path . "/login");
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
    render_template('setup.html');
}

elseif ($path === '/dashboard' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $base_path . "/login");
        exit;
    }
    $devices = get_user_devices($pdo, $_SESSION['user_id']);
    render_template('dashboard.html', ['username' => $_SESSION['username'], 'devices' => $devices]);
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

        // 1. Skúsime aktualizovať existujúci riadok pre dané slave_id
        $stmtUp = $pdo->prepare("UPDATE cm5_config SET admin_command = ?, status = 'pending' WHERE modbus_slave_id = ?");
        $stmtUp->execute([$cmd, $slave_id]);
        
        // 2. Ak taký riadok neexistuje, vložíme nový záznam
        if ($stmtUp->rowCount() === 0) {
            $stmtIns = $pdo->prepare("INSERT INTO cm5_config (serial_number, modbus_slave_id, admin_command, status) VALUES (?, ?, ?, 'pending')");
            $stmtIns->execute([$serial, $slave_id, $cmd]);
        }
        
        send_json([
            'status' => 'success', 
            'message' => "Príkaz '$cmd' bol úspešne zapísaný do tabuľky cm5_config (admin_command)."
        ]);
    } catch (Exception $e) {
        send_json(['status' => 'error', 'message' => 'Chyba databázy: ' . $e->getMessage()], 500);
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
    
    send_json([
        'total_live_power' => $latest ? (float)$latest['power_ac'] : ($device['fve_power_w'] ?? 3840),
        'avg_live_soc' => $latest ? (float)$latest['battery_soc'] : ($device['battery_soc'] ?? 84),
        'total_kwh' => (float)($device['total_kwh'] ?? 14.5),
        'temp' => $latest ? (float)$latest['temp'] : 32.5,
        'freq' => $latest ? (float)$latest['freq'] : 50.0,
        'manual_override' => $device['manual_override'] ?? 'AUTO'
    ]);
}

// --- OKTE CENY API ---
elseif ($path === '/api/okte/prices' && $method === 'GET') {
    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime('+1 day'));
    $data = fetch_okte_prices($from, $to);
    send_json(['status' => 'success', 'okte' => $data]);
}

// --- CM5 POLL PRE PRÍKAZY (Pre Raspberry Pi agenta) ---
elseif ($path === '/api/cm5/poll' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial'] ?? '');
    
    try {
        $stmt = $pdo->prepare("SELECT id, admin_command, config_json FROM cm5_config WHERE (serial_number = ? OR serial_number = 'CM5-DEFAULT') AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$serial]);
        $row = $stmt->fetch();
        
        if ($row) {
            try {
                $pdo->prepare("UPDATE cm5_config SET status = 'sent' WHERE id = ?")->execute([$row['id']]);
            } catch (Exception $e) {
                // Status update failed - ignore (data truncated)
            }
            send_json([
                'status' => 'success',
                'command' => $row['admin_command'],
                'config' => json_decode($row['config_json'] ?? '{}', true),
                'id' => $row['id']
            ]);
        } else {
            send_json(['status' => 'no_pending']);
        }
    } catch (Exception $e) {
        // cm5_config table might not exist yet or has schema issues
        send_json(['status' => 'no_pending']);
    }
}

// --- CM5 REPORT IP (keepalive) ---
elseif ($path === '/api/report-ip' && $method === 'POST') {
    $data = get_json_input();
    $ip = trim($data['ip'] ?? '');
    $serial = trim($data['serial'] ?? '');
    if ($ip && $serial) {
        try {
            $stmt = $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'online') ON DUPLICATE KEY UPDATE updated_at = NOW()");
            $stmt->execute([$serial, json_encode(['ip' => $ip])]);
        } catch (Exception $e) { /* ignore */ }
    }
    send_json(['status' => 'success']);
}

// --- CM5 REGISTER ---
elseif ($path === '/api/cm5/register' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial'] ?? '');
    if ($serial) {
        try {
            $stmt = $pdo->prepare("INSERT INTO cm5_config (serial_number, status) VALUES (?, 'registered') ON DUPLICATE KEY UPDATE updated_at = NOW()");
            $stmt->execute([$serial]);
        } catch (Exception $e) { /* ignore */ }
    }
    send_json(['status' => 'success', 'serial' => $serial]);
}

// --- HEALTHCHECK ---
elseif ($path === '/healthcheck' || $path === '/health') {
    send_json(['status' => 'ok', 'time' => date('c')]);
}

// --- 404 HANDLER ---
else {
    http_response_code(404);
    echo "Stránka nebola nájdená (404): " . htmlspecialchars($path);
}
