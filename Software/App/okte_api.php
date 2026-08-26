<?php
// okte_api.php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('Europe/Bratislava');

// Získanie dátumu (predvolený je dnešný deň)
$today = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $today)) {
    $today = date('Y-m-d');
}

$cacheFile = __DIR__ . '/cache_okte_' . $today . '.json';

// 1. Kontrola, či existuje lokálna disková vyrovnávacia pamäť pre vybraný deň
if (file_exists($cacheFile)) {
    $cacheData = file_get_contents($cacheFile);
    if ($cacheData) {
        echo $cacheData;
        exit;
    }
}

// 2. Ak cache neexistuje, stiahneme reálne dáta zo servera OKTE
function fetchOkteDay($day) {
    $url = "https://isot.okte.sk/api/v1/dam/results?deliveryDayFrom=" . $day . "&deliveryDayTo=" . $day;
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // cURL metóda dopytovania
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Accept-Language: sk,cs;q=0.9,en;q=0.8'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $rawData = json_decode($response, true);
            if (is_array($rawData)) {
                return isset($rawData['results']) ? $rawData['results'] : $rawData;
            }
        }
    }
    
    // Záložná metóda cez súborový stream
    if (ini_get('allow_url_fopen')) {
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "User-Agent: " . $userAgent . "\r\nAccept: application/json\r\nAccept-Language: sk,cs;q=0.9,en;q=0.8\r\n",
                "timeout" => 10
            ],
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        if ($response) {
            $rawData = json_decode($response, true);
            if (is_array($rawData)) {
                return isset($rawData['results']) ? $rawData['results'] : $rawData;
            }
        }
    }
    
    return null;
}

$currentHour = (int)date('H');
$allResults = [];
$todayData = fetchOkteDay($today);

if (is_array($todayData) && !empty($todayData)) {
    $allResults = $todayData;

    // Po 13:00 hodine stiahneme aj nastávajúci deň
    if ($currentHour >= 13) {
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $tomorrowData = fetchOkteDay($tomorrow);
        if (is_array($tomorrowData) && !empty($tomorrowData)) {
            $allResults = array_merge($allResults, $tomorrowData);
        }
    }
}

if (!empty($allResults)) {
    // Chronologické radenie podľa dní a 15-minútových periód
    usort($allResults, function($a, $b) {
        if ($a['deliveryDay'] === $b['deliveryDay']) {
            return (int)$a['period'] - (int)$b['period'];
        }
        return strcmp($a['deliveryDay'], $b['deliveryDay']);
    });

    $prices = array_map(function($item) {
        return (float)$item['price'];
    }, $allResults);

    if (!empty($prices)) {
        $output = [
            'success' => true,
            'prices' => $prices,
            'timestamp' => time()
        ];
        
        $jsonOutput = json_encode($output);

        // Uloženie do lokálneho cache súboru pre budúce dopyty
        file_put_contents($cacheFile, $jsonOutput, LOCK_EX);

        // Premazanie starých nepotrebných cache súborov z minulých dní
        foreach (glob(__DIR__ . '/cache_okte_*.json') as $oldFile) {
            if ($oldFile !== $cacheFile) {
                @unlink($oldFile);
            }
        }

        echo $jsonOutput;
        exit;
    }
}

// Chybový návratový JSON
http_response_code(502);
echo json_encode([
    'success' => false,
    'error' => 'Chyba spojenia s OKTE serverom.'
]);