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
