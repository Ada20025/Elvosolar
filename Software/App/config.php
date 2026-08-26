<?php
// config.php — ElvoControl
// Automaticky detekuje prostredie (Railway / alwaysdata / lokálne)

// === RAILWAY (env premenné) ===
// Railway používa tieto premenne: MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQL_PASSWORD, MYSQL_DATABASE
// Tiež podporuje: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT
function get_env_val($keys, $default = '') {
    foreach ($keys as $key) {
        $val = $_ENV[$key] ?? getenv($key) ?: '';
        if ($val !== '' && $val !== false) return $val;
    }
    return $default;
}

$railway_host = get_env_val(['MYSQLHOST', 'DB_HOST', 'DATABASE_HOST'], '');
$railway_db   = get_env_val(['MYSQL_DATABASE', 'DB_NAME', 'DATABASE_NAME'], '');
$railway_user = get_env_val(['MYSQLUSER', 'DB_USER', 'DATABASE_USER'], '');
$railway_pass = get_env_val(['MYSQL_PASSWORD', 'DB_PASS', 'DATABASE_PASSWORD'], '');
$railway_port = get_env_val(['MYSQLPORT', 'DB_PORT', 'DATABASE_PORT'], '3306');

// === ALWAYSDATA (fallback) ===
$alwaysdata_host = 'mysql-adamdz.alwaysdata.net';
$alwaysdata_db   = 'adamdz_solar';
$alwaysdata_user = 'adamdz_admin';
$alwaysdata_pass = '1Adamko.';

// Vyber prostredie
if ($railway_host && $railway_db) {
    // RAILWAY
    define('DB_HOST', $railway_host);
    define('DB_NAME', $railway_db);
    define('DB_USER', $railway_user);
    define('DB_PASS', $railway_pass);
} else {
    // ALWAYSDATA (alebo lokálne)
    define('DB_HOST', $alwaysdata_host);
    define('DB_NAME', $alwaysdata_db);
    define('DB_USER', $alwaysdata_user);
    define('DB_PASS', $alwaysdata_pass);
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . (getenv("MYSQLPORT") ?: "3306") . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Chyba pripojenia k databáze: " . $e->getMessage());
}
