<?php
// === DIAGNOSTIKA SMTP (docasne - zmazat po overeni!) ===
header('Content-Type: text/plain; charset=utf-8');

echo "=== SMTP DIAG ===\n";

require_once __DIR__ . '/mail_helper.php';

echo "HOST: " . SMTP_HOST . "\n";
echo "PORT: " . SMTP_PORT . "\n";
echo "USER: " . SMTP_USER . "\n";
echo "PASS len: " . strlen(SMTP_PASS) . "\n";
echo "ENCRYPTION: " . SMTP_ENCRYPTION . "\n";
echo "MAIL_RELAY_URL: " . (getenv('MAIL_RELAY_URL') ?: '(nie)') . "\n\n";

// Rychly socket test
$host = SMTP_HOST;
$port = SMTP_PORT;
$start = microtime(true);
$socket = @stream_socket_client($host . ':' . $port, $errno, $errstr, 6);
$dt = round((microtime(true) - $start) * 1000);
if (!$socket) {
    echo "SOCKET FAIL: $errstr ($errno) po {$dt}ms\n";
    exit;
}
echo "SOCKET OK ({$dt}ms)\n";
$banner = fgets($socket, 512);
echo "BANNER: " . trim($banner) . "\n";
fwrite($socket, "EHLO elvosolar.sk\r\n");
echo "EHLO: " . trim(fgets($socket, 512)) . "\n";
fwrite($socket, "STARTTLS\r\n");
echo "STARTTLS: " . trim(fgets($socket, 512)) . "\n";
stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
fwrite($socket, "EHLO elvosolar.sk\r\n");
fgets($socket, 512);
fwrite($socket, "AUTH LOGIN\r\n");
fgets($socket, 512);
fwrite($socket, base64_encode(SMTP_USER) . "\r\n");
fgets($socket, 512);
fwrite($socket, base64_encode(SMTP_PASS) . "\r\n");
$auth = fgets($socket, 512);
echo "AUTH: " . trim($auth) . "\n";
fwrite($socket, "QUIT\r\n");
fclose($socket);
echo "\n(cakaj: 235 = auth OK, 535 = zle heslo)\n";

echo "\n=== DB DIAG ===\n";
echo "MYSQLHOST env: " . (getenv('MYSQLHOST') ?: '(nie)') . "\n";
echo "DB_HOST env: " . (getenv('DB_HOST') ?: '(nie)') . "\n";
echo "DB pouzivany: " . (defined('DB_HOST') ? DB_HOST : '(?)') . "\n";
$t0 = microtime(true);
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $t1 = microtime(true);
    echo "CONNECT OK za " . round(($t1 - $t0) * 1000) . " ms\n";
    $t2 = microtime(true);
    $pdo->query("SELECT 1")->fetch();
    $t3 = microtime(true);
    echo "SELECT 1 za " . round(($t3 - $t2) * 1000) . " ms\n";
    $t4 = microtime(true);
    $pdo->query("SELECT done_date FROM migrations_state WHERE id = 1")->fetch();
    $t5 = microtime(true);
    echo "migration check za " . round(($t5 - $t4) * 1000) . " ms\n";
} catch (Exception $e) {
    echo "DB FAIL: " . $e->getMessage() . "\n";
}
