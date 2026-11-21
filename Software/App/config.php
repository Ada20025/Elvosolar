<?php
// config.php — ElvoControll
// Prostredie: Railway (MySQL) / iný MySQL hosting / lokálne (SQLite fallback)
//
// Railway MySQL plugin dáva tieto premenné:
//   MYSQL_DATABASE, MYSQL_PRIVATE_URL, MYSQL_ROOT_PASSWORD
//   (staršie varianty: MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQL_URL)
// Preto sa najprv skúša URL (mysql://user:heslo@host:port/db) a až potom
// jednotlivé premenné. Žiadne heslá nie sú v kóde.

if (!function_exists('elvo_parse_db_url')) {
    /** mysql://user:pass@host:port/dbname -> pole alebo null */
    function elvo_parse_db_url($url) {
        $url = trim((string)$url);
        if ($url === '') return null;
        $parts = @parse_url($url);
        if (!$parts || empty($parts['host'])) return null;
        $name = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
        if ($name === '') return null;
        return [
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? (string)$parts['port'] : '3306',
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : '',
            'pass' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
            'name' => $name,
        ];
    }
}

// 0) Voliteľný config.local.php — pre shared hosting (WebSupport a pod.), kde sa
//    premenné prostredia nedajú nastaviť. Súbor nie je v gite, takže aktualizácia
//    appky ho neprepíše. Formát:
//    return ['host' => 'localhost', 'port' => '3306', 'name' => 'db', 'user' => 'u', 'pass' => 'p'];
$db_cfg = null;
$elvo_local_cfg = __DIR__ . '/config.local.php';
if (is_file($elvo_local_cfg)) {
    $elvo_local = @include $elvo_local_cfg;
    if (is_array($elvo_local) && !empty($elvo_local['host']) && !empty($elvo_local['name'])) {
        $db_cfg = [
            'host' => (string)$elvo_local['host'],
            'port' => (string)($elvo_local['port'] ?? '3306'),
            'user' => (string)($elvo_local['user'] ?? ''),
            'pass' => (string)($elvo_local['pass'] ?? ''),
            'name' => (string)$elvo_local['name'],
        ];
    }
}

// 1) Priorita: URL z Railway / všeobecná DATABASE_URL
foreach (['MYSQL_URL', 'MYSQL_PRIVATE_URL', 'DATABASE_URL', 'DB_URL'] as $env_key) {
    $parsed = elvo_parse_db_url(getenv($env_key));
    if ($parsed) { $db_cfg = $parsed; break; }
}

// 2) Fallback: jednotlivé premenné
if (!$db_cfg) {
    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '';
    $name = getenv('MYSQL_DATABASE') ?: getenv('DB_NAME') ?: '';
    if ($host && $name) {
        $db_cfg = [
            'host' => $host,
            'port' => getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306',
            'user' => getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root',
            'pass' => getenv('MYSQL_ROOT_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: getenv('DB_PASS') ?: '',
            'name' => $name,
        ];
    }
}

// 3) Ak je známa len DB + root heslo (Railway), doplň používateľa/heslo
if ($db_cfg && $db_cfg['pass'] === '') {
    $db_cfg['pass'] = getenv('MYSQL_ROOT_PASSWORD') ?: '';
}
if ($db_cfg && $db_cfg['user'] === '') {
    $db_cfg['user'] = 'root';
}

$is_mysql = ($db_cfg !== null);

if ($is_mysql) {
    define('DB_HOST', $db_cfg['host']);
    define('DB_NAME', $db_cfg['name']);
    define('DB_USER', $db_cfg['user']);
    define('DB_PASS', $db_cfg['pass']);
    define('DB_PORT', $db_cfg['port']);
} else {
    define('DB_HOST', '');
    define('DB_NAME', '');
    define('DB_USER', '');
    define('DB_PASS', '');
    define('DB_PORT', '3306');
}

$pdo = null;

// ── MySQL pripojenie ────────────────────────────────────────────────────────
if ($is_mysql) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    } catch (Exception $e) {
        error_log("[DB] MySQL pripojenie zlyhalo (" . DB_HOST . "/" . DB_NAME . "): " . $e->getMessage());
        $pdo = null;
    }
}

// ── Automatické vytvorenie tabuliek (Railway štart s prázdnou DB) ──────────
if ($pdo) {
    try {
        require_once __DIR__ . '/db_bootstrap.php';
        if (function_exists('elvo_db_bootstrap')) {
            elvo_db_bootstrap($pdo);
        }
    } catch (Throwable $e) {
        error_log("[DB] Bootstrap schémy zlyhal: " . $e->getMessage());
    }
}

// ── Ak sú MySQL údaje nastavené, ale spojenie zlyhalo, NEPREJDEME potichu na
// SQLite — appka by bežala nad prázdnou lokálnou DB a tvárila sa, že dáta sú OK.
if (!$pdo && $is_mysql) {
    http_response_code(500);
    die('Chyba: nepodarilo sa pripojiť k MySQL (' . DB_HOST . '/' . DB_NAME . '). '
        . 'Skontroluj údaje v config.local.php alebo premenné prostredia.');
}

// ── SQLite fallback (lokálny vývoj / výpadok DB) ───────────────────────────
if (!$pdo) {
    try {
        $sqlite_path = __DIR__ . '/local_app.db';
        $pdo = new PDO("sqlite:" . $sqlite_path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(100),
                email VARCHAR(255) UNIQUE,
                password_hash VARCHAR(255),
                role VARCHAR(20) DEFAULT 'user',
                email_verified INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER DEFAULT 1,
                name VARCHAR(100),
                serial_number VARCHAR(100) UNIQUE,
                brand VARCHAR(50) DEFAULT 'HUAWEI',
                model_name VARCHAR(100) DEFAULT 'SUN2000-5KTL-M1',
                status VARCHAR(20) DEFAULT 'online',
                has_battery INTEGER DEFAULT 1,
                battery_soc REAL DEFAULT 84,
                fve_power_w REAL DEFAULT 3840,
                grid_power_w REAL DEFAULT 0,
                min_power_w REAL DEFAULT 0,
                max_power_w REAL DEFAULT 10000,
                min_power_pct REAL DEFAULT 0,
                max_power_pct REAL DEFAULT 100,
                active_model_id VARCHAR(10) DEFAULT '1',
                night_sleep INTEGER DEFAULT 0,
                connection_type VARCHAR(20) DEFAULT 'modbus_tcp',
                smartlogger_ip VARCHAR(50) DEFAULT '192.168.0.10',
                smartlogger_port INTEGER DEFAULT 502,
                modbus_slave_id INTEGER DEFAULT 205,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS telemetry (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                battery_soc REAL DEFAULT 84,
                power_ac REAL DEFAULT 3840,
                temp REAL DEFAULT 32.5,
                freq REAL DEFAULT 50.0,
                status_msg VARCHAR(255) DEFAULT 'Online',
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS system_settings (
                key VARCHAR(100) PRIMARY KEY,
                value TEXT
            );
        ");

        // Seed predvoleného admin používateľa — len lokálny SQLite fallback.
        // Heslo sa berie z ADMIN_PASS (v Railway si ho nastav!), inak admin123.
        // Vypnutie: SEED_DEMO=0.
        $admPass = (string)(getenv('ADMIN_PASS') ?: '');
        if ($admPass === '') {
            // Ziadne zname default heslo — nahodne, zapisane iba do serveroveho logu
            $admPass = bin2hex(random_bytes(12));
            error_log('[DB] SEED admin heslo (nastav ADMIN_PASS): ' . $admPass);
        }
        $chkAdmin = $pdo->query("SELECT id FROM users WHERE email = 'admin@elvosolar.sk' OR username = 'admin' LIMIT 1")->fetch();
        if (!$chkAdmin && getenv('SEED_DEMO') !== '0') {
            $admHash = password_hash($admPass, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username, email, password_hash, role, email_verified) VALUES ('admin', 'admin@elvosolar.sk', ?, 'admin', 1)")
                ->execute([$admHash]);
            $admId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO devices (user_id, name, serial_number, brand, model_name, status, battery_soc, fve_power_w, connection_type, smartlogger_ip, smartlogger_port, modbus_slave_id) VALUES (?, 'ElvoSolar CM5 AI Smart EMS', 'CM5-ELVO-001', 'HUAWEI', 'SmartLogger 3000 / SUN2000', 'online', 84, 3840, 'modbus_tcp', '192.168.0.10', 502, 205)")
                ->execute([$admId]);
        }
    } catch (Exception $e2) {
        error_log("[DB] Kritická chyba SQLite: " . $e2->getMessage());
    }
}

// Jasná chyba namiesto bielej obrazovky, ak DB nie je dostupná
if (!$pdo) {
    http_response_code(500);
    die('Chyba: databáza nie je dostupná. V Railway skontroluj premenné '
        . 'MYSQL_PRIVATE_URL (alebo MYSQLHOST + MYSQL_DATABASE) v záložke Variables.');
}

// cm5_config tabuľka (permissívna schéma — rovnaká ako v db/schema.sql)
if ($pdo && file_exists(__DIR__ . '/cm5_config_table.php')) {
    try {
        require_once __DIR__ . '/cm5_config_table.php';
        if (function_exists('ensure_cm5_config_table')) {
            ensure_cm5_config_table($pdo);
        }
    } catch (Throwable $e) { /* ignore */ }
}
