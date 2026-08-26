<?php
// config.php — ElvoControl
// Automaticky detekuje prostredie (Railway / alwaysdata / lokálne)

// === RAILWAY (env premenné) ===
// Railway používa tieto premenne: MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQL_PASSWORD, MYSQL_DATABASE
// Tiež podporuje: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT
// Priame čítanie - najspoľahlivejšie pre Docker/Railway
$railway_host = getenv('MYSQLHOST') ?: '';
$railway_db   = getenv('MYSQL_DATABASE') ?: '';
$railway_user = getenv('MYSQLUSER') ?: '';
$railway_pass = getenv('MYSQL_ROOT_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';
$railway_port = getenv('MYSQLPORT') ?: '3306';

error_log("DB: host=$railway_host port=$railway_port db=$railway_db user=$railway_user pass_len=" . strlen($railway_pass));

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
