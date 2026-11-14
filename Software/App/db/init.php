<?php
// db/init.php — ručné vytvorenie všetkých tabuliek
// Použitie (Railway Console alebo lokálne):
//     php db/init.php
// Skript je idempotentný, existujúce tabuľky a dáta necháva tak.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tento skript je len pre CLI: php db/init.php\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_bootstrap.php';

if (!isset($pdo) || !$pdo) {
    fwrite(STDERR, "❌ DB spojenie nie je k dispozícii (skontroluj MYSQL_PRIVATE_URL / MYSQLHOST).\n");
    exit(1);
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
echo "→ Pripojené: " . $driver . " " . (DB_HOST ? DB_HOST . '/' . DB_NAME : '(SQLite fallback)') . "\n";

if ($driver !== 'mysql') {
    echo "ℹ️  SQLite fallback už má základné tabuľky z config.php.\n";
} else {
    $ok = elvo_db_bootstrap($pdo, true);
    echo $ok ? "✅ Schéma vytvorená / overená.\n" : "⚠️  Niektoré príkazy zlyhali — pozri error_log.\n";
}

try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tabuľky (" . count($tables) . "): " . implode(', ', $tables) . "\n";
} catch (Throwable $e) {
    echo "Tabuľky sa nedajú vypísať: " . $e->getMessage() . "\n";
}
