<?php
// config.railway.php — Railway.app
// Railway MySQL plugin dáva: MYSQL_DATABASE, MYSQL_PRIVATE_URL, MYSQL_ROOT_PASSWORD
// (staršie: MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD)
// Tento súbor je ekvivalent config.php priamo pre Railway — heslá sú iba v premenných.

if (!function_exists('elvo_parse_db_url')) {
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

$db_cfg = null;
foreach (['MYSQL_URL', 'MYSQL_PRIVATE_URL', 'DATABASE_URL', 'DB_URL'] as $env_key) {
    $parsed = elvo_parse_db_url(getenv($env_key));
    if ($parsed) { $db_cfg = $parsed; break; }
}
if (!$db_cfg) {
    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '';
    $name = getenv('MYSQL_DATABASE') ?: getenv('DB_NAME') ?: '';
    if ($host && $name) {
        $db_cfg = [
            'host' => $host,
            'port' => getenv('MYSQLPORT') ?: '3306',
            'user' => getenv('MYSQLUSER') ?: 'root',
            'pass' => getenv('MYSQL_ROOT_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '',
            'name' => $name,
        ];
    }
}
if (!$db_cfg) {
    die("Chyba: chýba MYSQL_PRIVATE_URL alebo MYSQLHOST/MYSQL_DATABASE v premenných prostredia.");
}
if ($db_cfg['user'] === '') $db_cfg['user'] = 'root';
if ($db_cfg['pass'] === '') $db_cfg['pass'] = getenv('MYSQL_ROOT_PASSWORD') ?: '';

define('DB_HOST', $db_cfg['host']);
define('DB_NAME', $db_cfg['name']);
define('DB_USER', $db_cfg['user']);
define('DB_PASS', $db_cfg['pass']);
define('DB_PORT', $db_cfg['port']);

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]
    );
} catch (PDOException $e) {
    die("Chyba pripojenia k databáze: " . $e->getMessage());
}

// Automatické vytvorenie všetkých tabuliek (prvé nasadenie = prázdna DB)
try {
    require_once __DIR__ . '/db_bootstrap.php';
    if (function_exists('elvo_db_bootstrap')) elvo_db_bootstrap($pdo);
} catch (Throwable $e) {
    error_log('[DB] Bootstrap: ' . $e->getMessage());
}
