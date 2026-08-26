<?php
// config.php
// UPRAVENÝ SÚBOR PRE ALWAYSDATA ÚČET: adamdz

define('DB_HOST', 'mysql-adamdz.alwaysdata.net'); 
define('DB_NAME', 'adamdz_solar'); 
define('DB_USER', 'adamdz_admin'); 
define('DB_PASS', '1Adamko.'); 

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Chyba pripojenia k databáze: " . $e->getMessage());
}