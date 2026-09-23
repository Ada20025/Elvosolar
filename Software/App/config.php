<?php
// config.php — ElvoControl
// Automaticky detekuje prostredie (Railway / AlwaysData / SQLite Lokálne) s 100% ochranou pred bielou obrazovkou

// 1. Zistenie parametrov z Railway prostredia
$railway_host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '';
$railway_db   = getenv('MYSQL_DATABASE') ?: getenv('DB_NAME') ?: '';
$railway_user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: '';
$railway_pass = getenv('MYSQL_ROOT_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: getenv('DB_PASS') ?: '';
$railway_port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';

// 2. Fallback AlwaysData parametre
$alwaysdata_host = 'mysql-adamdz.alwaysdata.net';
$alwaysdata_db   = 'adamdz_solar';
$alwaysdata_user = 'adamdz_admin';
$alwaysdata_pass = '1Adamko.';

if ($railway_host && $railway_db) {
    $db_host = $railway_host;
    $db_name = $railway_db;
    $db_user = $railway_user;
    $db_pass = $railway_pass;
    $db_port = $railway_port;
} else {
    $db_host = $alwaysdata_host;
    $db_name = $alwaysdata_db;
    $db_user = $alwaysdata_user;
    $db_pass = $alwaysdata_pass;
    $db_port = '3306';
}

define('DB_HOST', $db_host);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('DB_PORT', $db_port);

$pdo = null;

// Pokus o pripojenie k MySQL (s 3-sekundovým timeoutom)
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 3
    ]);
} catch (Exception $e) {
    error_log("[DB] MySQL pripojenie zlyhalo, prepínam na lokálny SQLite fallback: " . $e->getMessage());
    
    // SQLite Failover: zaručuje, že web nikdy nespadne na bielu obrazovku
    try {
        $sqlite_path = __DIR__ . '/local_app.db';
        $pdo = new PDO("sqlite:" . $sqlite_path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        // Vytvorenie základných tabuliek v SQLite ak neexistujú
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

        // Seed predvoleného admin používateľa (admin / admin123)
        $chkAdmin = $pdo->query("SELECT id FROM users WHERE email = 'admin@elvosolar.sk' OR username = 'admin' LIMIT 1")->fetch();
        if (!$chkAdmin) {
            $admHash = password_hash('admin123', PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username, email, password_hash, role, email_verified) VALUES ('admin', 'admin@elvosolar.sk', ?, 'admin', 1)")
                ->execute([$admHash]);
            $admId = $pdo->lastInsertId();
            
            // Predvolené zariadenie
            $pdo->prepare("INSERT INTO devices (user_id, name, serial_number, brand, model_name, status, battery_soc, fve_power_w, connection_type, smartlogger_ip, smartlogger_port, modbus_slave_id) VALUES (?, 'ElvoSolar CM5 AI Smart EMS', 'CM5-ELVO-001', 'HUAWEI', 'SmartLogger 3000 / SUN2000', 'online', 84, 3840, 'modbus_tcp', '192.168.0.10', 502, 205)")
                ->execute([$admId]);
        }
    } catch (Exception $e2) {
        error_log("[DB] Kritická chyba SQLite: " . $e2->getMessage());
    }
}

// cm5_config tabuľka
if (file_exists(__DIR__ . '/cm5_config_table.php') && $pdo) {
    try {
        require_once __DIR__ . '/cm5_config_table.php';
        if (function_exists('ensure_cm5_config_table')) {
            ensure_cm5_config_table($pdo);
        }
    } catch (Exception $e) { /* ignore */ }
}
