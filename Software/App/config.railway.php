<?php
// config.php — Railway.app prostredie
// DB údaje sa načítajú z premenných prostredia (Railway MySQL plugin)

// Pokus o načítanie z env premenných (Railway)
$db_host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('MYSQL_DATABASE') ?: getenv('DB_NAME') ?: 'railway';
$db_user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
$db_pass = getenv('MYSQL_PASSWORD') ?: getenv('DB_PASS') ?: '';

// Fallback pre prípad že sa používajú vlastné premené
if (!$db_host || $db_host === 'localhost') {
    $db_host = getenv('DATABASE_HOST') ?: 'localhost';
    $db_name = getenv('DATABASE_NAME') ?: 'railway';
    $db_user = getenv('DATABASE_USER') ?: 'root';
    $db_pass = getenv('DATABASE_PASSWORD') ?: '';
}

define('DB_HOST', $db_host);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Chyba pripojenia k databáze: " . $e->getMessage());
}
