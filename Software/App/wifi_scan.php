<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Sken WiFi smie spustiť len prihlásený admin/manažér — inak by to bola otvorená cesta do boxu.
require_once __DIR__ . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime' => 31536000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Neautorizované']);
    exit;
}
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['error' => 'Databáza nedostupná']);
    exit;
}
$stW = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stW->execute([(int)$_SESSION['user_id']]);
$wRow = $stW->fetch();
if (!$wRow || !in_array((string)($wRow['role'] ?? ''), ['admin', 'manazer'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Nemáte oprávnenie']);
    exit;
}

$networks = [];

// Primárne dopytovanie lokálneho FastAPI jadra bežiaceho na pozadí pod administrátorským účtom
$python_api_url = 'http://127.0.0.1:8000/api/system/wifi/scan';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $python_api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200 && $response) {
    $data = json_decode($response, true);
    if (is_array($data)) {
        echo json_encode($data);
        exit;
    }
}

// SECUNDÁRNY FALLBACK: Pokus o lokálny príkaz nmcli, ak Python proces neodpovedá
if (shell_exec('which nmcli 2>/dev/null')) {
    $output = shell_exec('nmcli -t -f SSID,SIGNAL dev wifi list 2>&1');
    if ($output) {
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $parts = explode(':', $line);
            if (count($parts) >= 2) {
                $ssid = trim($parts[0]);
                $signal = intval(trim($parts[1]));
                if (!empty($ssid) && $ssid !== '--') {
                    $networks[] = [
                        'ssid' => $ssid,
                        'signal' => $signal
                    ];
                }
            }
        }
    }
}

$unique_networks = [];
$seen = [];
foreach ($networks as $net) {
    if (!in_array($net['ssid'], $seen)) {
        $seen[] = $net['ssid'];
        $unique_networks[] = $net;
    }
}

echo json_encode($unique_networks);
exit;