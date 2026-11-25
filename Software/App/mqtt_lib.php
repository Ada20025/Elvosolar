<?php
// mqtt_lib.php - MQTT klient pre web
// pripojí sa k brokeru, pošle príkaz do stroje/{serial}/prikazy, prečíta stroje/+/stav
// príkazy majú CSRF, whitelist, audit a limit 30/min
//
// nastavenie: premenné prostredia alebo mqtt_config.local.php:
//   <?php return ['host'=>'mqtt.example.sk','password'=>'...'];
//
// Broker má nešifrované spojenie zakázané -> TLS na porte 8883 je predvolené.
// CA certifikát: MQTT_CAFILE, inak isrgrootx1.pem vedľa tohto súboru,
// inak systémový store (WebSupport ho má).

if (!defined('ELVO_MQTT_LIB')) {
define('ELVO_MQTT_LIB', 1);

// ---------------------------------------------------------------------------
// NASTAVENIE
// ---------------------------------------------------------------------------
if (!function_exists('elvo_mqtt_config')) {
function elvo_mqtt_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [
        'host'      => getenv('MQTT_BROKER_HOST') ?: '',
        'port'      => (int)(getenv('MQTT_BROKER_PORT') ?: 8883),
        'tls'       => (getenv('MQTT_BROKER_TLS') ?? '1') !== '0',
        'username'  => getenv('MQTT_WEB_USER') ?: 'webapp',
        'password'  => getenv('MQTT_WEB_PASS') ?: '',
        'cafile'    => getenv('MQTT_CAFILE') ?: '',
        'verify'    => (getenv('MQTT_VERIFY_TLS') ?? '1') !== '0',
        'timeout'   => 5,
        'states_ttl' => 5,
    ];
    if ($cfg['cafile'] === '') {
        $localCa = __DIR__ . '/isrgrootx1.pem';
        if (is_file($localCa)) $cfg['cafile'] = $localCa;
    }
    $local = __DIR__ . '/mqtt_config.local.php';
    if (is_file($local)) {
        $override = include $local;
        if (is_array($override)) $cfg = array_merge($cfg, $override);
    }
    return $cfg;
}
}

// ---------------------------------------------------------------------------
// MQTT 3.1.1 KLIENT
// ---------------------------------------------------------------------------
if (!class_exists('ElvoMqttClient')) {
class ElvoMqttClient {
    private $sock = null;
    private $cfg;
    private $packetId = 0;

    public function __construct($cfg) { $this->cfg = $cfg; }

    private function nextPacketId() { $this->packetId = ($this->packetId % 65535) + 1; return $this->packetId; }

    private function str($s) { return pack('n', strlen($s)) . $s; }

    private function remainingLength($n) {
        $out = '';
        do { $d = $n % 128; $n = intdiv($n, 128); if ($n > 0) $d |= 0x80; $out .= chr($d); } while ($n > 0);
        return $out;
    }

    public function connect() {
        $cfg = $this->cfg;
        if ($cfg['host'] === '') throw new RuntimeException('MQTT broker nie je nakonfigurovaný (mqtt_config.local.php / env)');
        if ($cfg['password'] === '') throw new RuntimeException('MQTT heslo webu nie je nastavené');
        $scheme = $cfg['tls'] ? 'tls' : 'tcp';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $cfg['verify'],
            'verify_peer_name' => $cfg['verify'],
            'allow_self_signed' => !$cfg['verify'],
            'SNI_enabled' => true,
            'peer_name' => $cfg['host'],
        ]]);
        if ($cfg['tls'] && $cfg['cafile'] !== '') stream_context_set_option($ctx, 'ssl', 'cafile', $cfg['cafile']);
        $errno = 0; $errstr = '';
        $this->sock = @stream_socket_client(
            $scheme . '://' . $cfg['host'] . ':' . $cfg['port'],
            $errno, $errstr, $cfg['timeout'], STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new RuntimeException('Nepodarilo sa pripojiť k brokeru (chyba ' . $errno . '): ' . $errstr);
        stream_set_timeout($this->sock, $cfg['timeout']);

        $clientId = 'elvoweb-' . substr(bin2hex(random_bytes(8)), 0, 12);
        $flags = 0x02; // clean session
        if ($cfg['username'] !== '') $flags |= 0x80;
        if ($cfg['password'] !== '') $flags |= 0x40;
        $vh = $this->str('MQTT') . chr(0x04) . chr($flags) . pack('n', 30);
        $payload = $this->str($clientId);
        if ($cfg['username'] !== '') $payload .= $this->str($cfg['username']);
        if ($cfg['password'] !== '') $payload .= $this->str($cfg['password']);
        $this->writePacket(0x10, $vh . $payload);
        $ack = $this->readPacket(3);
        if ($ack === null || $ack['type'] !== 0x20) throw new RuntimeException('Broker neodpovedal CONNACK');
        $rc = ord(substr($ack['body'], 1, 1));
        if ($rc !== 0) throw new RuntimeException('Broker odmietol prihlásenie (kód ' . $rc . ')');
        return true;
    }

    public function publish($topic, $payload, $qos = 1, $retain = true) {
        $body = $this->str($topic);
        $pid = 0;
        if ($qos > 0) { $pid = $this->nextPacketId(); $body .= pack('n', $pid); }
        $body .= $payload;
        $hdr = 0x30 | (($qos & 0x3) << 1) | ($retain ? 0x01 : 0x00);
        $this->writePacket($hdr, $body);
        if ($qos > 0) {
            $deadline = microtime(true) + 3;
            while (microtime(true) < $deadline) {
                $p = $this->readPacket(1);
                if ($p === null) continue;
                if ($p['type'] === 0x40 && substr($p['body'], 0, 2) === pack('n', $pid)) return true;
            }
            throw new RuntimeException('Broker nepotvrdil príkaz (PUBACK timeout)');
        }
        return true;
    }

    public function subscribe($topic, $qos = 1) {
        $pid = $this->nextPacketId();
        $body = pack('n', $pid) . $this->str($topic) . chr($qos);
        $this->writePacket(0x82, $body);
        return $pid;
    }

    public function readMessage($waitSeconds) {
        $p = $this->readPacket($waitSeconds);
        if ($p === null) return null;
        if (($p['type'] & 0xF0) !== 0x30) return null;
        $body = $p['body'];
        if (strlen($body) < 2) return null;
        $tlen = unpack('n', substr($body, 0, 2))[1];
        $topic = substr($body, 2, $tlen);
        $rest = substr($body, 2 + $tlen);
        $qos = ($p['type'] >> 1) & 0x03;
        if ($qos > 0 && strlen($rest) >= 2) {
            $pid = unpack('n', substr($rest, 0, 2))[1];
            $rest = substr($rest, 2);
            try { $this->writePacket(0x40, pack('n', $pid)); } catch (Exception $e) { /* ignore */ }
        }
        return [$topic, $rest];
    }

    public function disconnect() {
        if ($this->sock) {
            @fwrite($this->sock, chr(0xE0) . chr(0x00));
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    private function writePacket($hdr, $body) {
        if (!$this->sock) throw new RuntimeException('Spojenie s brokerom nie je otvorené');
        $data = chr($hdr) . $this->remainingLength(strlen($body)) . $body;
        $n = @fwrite($this->sock, $data);
        if ($n === false || (int)$n !== strlen($data)) throw new RuntimeException('Zápis do spojenia s brokerom zlyhal');
    }

    private function readBytes($n, $timeoutSec) {
        $sec = (int)$timeoutSec;
        $usec = (int)(($timeoutSec - $sec) * 1000000);
        stream_set_timeout($this->sock, $sec, $usec);
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = @fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                if (feof($this->sock)) throw new RuntimeException('Broker ukončil spojenie');
                return null; // timeout
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function readPacket($waitSeconds) {
        $b1 = $this->readBytes(1, $waitSeconds);
        if ($b1 === null) return null;
        $type = ord($b1);
        $len = 0; $mul = 1; $d = 0; $i = 0;
        do {
            $b = $this->readBytes(1, 2);
            if ($b === null) return null;
            $d = ord($b);
            $len += ($d & 0x7F) * $mul;
            $mul *= 128; $i++;
        } while (($d & 0x80) !== 0 && $i < 4);
        $body = $len > 0 ? $this->readBytes($len, max(2, $waitSeconds)) : '';
        if ($len > 0 && $body === null) return null;
        return ['type' => $type, 'body' => $body];
    }
}
}

// ---------------------------------------------------------------------------
// BEZPEČNOSŤ: CSRF, validácie, audit, rate limit
// ---------------------------------------------------------------------------
if (!function_exists('elvo_mqtt_csrf_token')) {
function elvo_mqtt_csrf_token() {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['mqtt_csrf'])) $_SESSION['mqtt_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['mqtt_csrf'];
}
}

if (!function_exists('elvo_mqtt_csrf_verify')) {
function elvo_mqtt_csrf_verify($token) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    return !empty($_SESSION['mqtt_csrf']) && is_string($token) && hash_equals($_SESSION['mqtt_csrf'], $token);
}
}

if (!function_exists('elvo_mqtt_valid_serial')) {
function elvo_mqtt_valid_serial($s) {
    return is_string($s) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_\.\-]{2,63}$/', $s) === 1;
}
}

if (!function_exists('elvo_mqtt_valid_command')) {
function elvo_mqtt_valid_command($c) {
    return in_array($c, ['STROJ_START', 'STROJ_STOP', 'STROJ_AUTO', 'STROJ_STATUS'], true);
}
}

if (!function_exists('elvo_mqtt_current_user')) {
function elvo_mqtt_current_user($pdo) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user_id'])) return null;
    try {
        $st = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ? LIMIT 1');
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch();
        if ($u && in_array(($u['role'] ?? ''), ['admin', 'manazer'], true)) return $u;
    } catch (Exception $e) { /* DB chyba -> neprihlásený */ }
    return null;
}
}

if (!function_exists('elvo_mqtt_audit')) {
function elvo_mqtt_audit($pdo, $userId, $username, $action, $serial, $command, $status, $detail = '') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mqtt_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL,
            user_id INT NULL,
            username VARCHAR(120) NULL,
            action VARCHAR(32) NOT NULL,
            serial VARCHAR(64) NULL,
            command VARCHAR(32) NULL,
            status VARCHAR(16) NOT NULL,
            detail VARCHAR(255) NULL,
            INDEX idx_user_time (user_id, created_at),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare('INSERT INTO mqtt_audit (created_at, user_id, username, action, serial, command, status, detail)
                             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$userId, $username, $action, $serial, $command, $status, substr((string)$detail, 0, 250)]);
    } catch (Exception $e) { /* audit nesmie zrušiť operáciu */ }
}
}

if (!function_exists('elvo_mqtt_rate_ok')) {
function elvo_mqtt_rate_ok($pdo, $userId, $maxPerMin = 30) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) AS c FROM mqtt_audit
                              WHERE user_id = ? AND action = 'publish' AND created_at > (NOW() - INTERVAL 1 MINUTE)");
        $st->execute([$userId]);
        $row = $st->fetch();
        return ((int)($row['c'] ?? 0)) < $maxPerMin;
    } catch (Exception $e) { return true; }
}
}

// ---------------------------------------------------------------------------
// VYSOKOROZHRANIE: publish príkazu + čítanie stavov
// ---------------------------------------------------------------------------
if (!function_exists('elvo_mqtt_publish_command')) {
function elvo_mqtt_publish_command($serial, $command, &$error = null) {
    if (!elvo_mqtt_valid_serial($serial)) { $error = 'Neplatné ID stroja'; return false; }
    if (!elvo_mqtt_valid_command($command)) { $error = 'Neplatný príkaz'; return false; }
    try {
        $client = new ElvoMqttClient(elvo_mqtt_config());
        $client->connect();
        $client->publish('stroje/' . $serial . '/prikazy', $command, 1, true);
        $client->disconnect();
        return true;
    } catch (Exception $e) {
        $error = $e->getMessage();
        return false;
    }
}
}

if (!function_exists('elvo_mqtt_collect_states')) {
function elvo_mqtt_collect_states($waitSeconds = 2, &$error = null) {
    $cfg = elvo_mqtt_config();
    $cacheFile = sys_get_temp_dir() . '/elvo-mqtt-states-' . md5($cfg['host'] . ':' . $cfg['port']) . '.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < (int)$cfg['states_ttl']) {
        $j = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($j)) return $j;
    }
    $states = [];
    try {
        $client = new ElvoMqttClient(elvo_mqtt_config());
        $client->connect();
        $client->subscribe('stroje/+/stav', 1);
        $deadline = microtime(true) + $waitSeconds;
        while (microtime(true) < $deadline) {
            $m = $client->readMessage(0.3);
            if ($m === null) continue;
            if (preg_match('#^stroje/([^/]+)/stav$#', (string)$m[0], $mm)) {
                $states[$mm[1]] = ['state' => substr((string)$m[1], 0, 64), 'ts' => time()];
            }
        }
        $client->disconnect();
        @file_put_contents($cacheFile, json_encode($states), LOCK_EX);
        return $states;
    } catch (Exception $e) {
        $error = $e->getMessage();
        if (is_file($cacheFile)) {
            $j = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($j)) return $j;
        }
        return [];
    }
}
}
}
