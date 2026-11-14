<?php
/**
 * send.php - ELVOSOLAR web (InfinityFree / PHP mail())
 *
 * Akcie (JSON POST):
 *   action=contact     -> kontaktny formular             -> mail adam.dzurko5@gmail.com + potvrdenie odosielatelovi
 *   action=cart        -> dopytovy kosik (podstranky)    -> mail + potvrdenie
 *   action=newsletter  -> odber notifikacii (novinky)    -> mail + potvrdenie odberatelovi
 *
 * Ochrany: len POST + JSON, kontrola Origin/Referer (same-origin), limit veľkosti tela,
 * neviditeľná kontrola proti botom (skryté pole "elvo_hp" + čas od načítania stránky
 * + príznak JavaScriptu — bez zaškrtávacieho poľa), rate-limit 1 požiadavka / 20 s / IP.
 * Odosielanie (v tomto poradí, prvé funkčné vyhráva):
 *   1. Resend HTTPS API (RESEND_API_KEY) — funguje aj na Railway, kde je SMTP blokované
 *   2. Brevo HTTPS API (BREVO_API_KEY) — voliteľná záloha
 *   3. SMTP s autentifikáciou (klasický hosting, na Railway len Pro plán)
 *   4. PHP mail() (obmedzený hosting, bez autentifikácie)
 *   5. záložná adresa s predmetom [ZÁLOHA]
 *   6. uloženie do undelivered.log, aby sa dopyt nestratil
 * Dopyty sa ukladajú do ELVO_DATA_DIR (predvolene _data/) a zobrazuje ich admin.php.
 * Maily su profesionalne HTML sablony (logo, tabulka udajov, paticka).
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header_remove('X-Powered-By');

// === KONFIGURACIA ===
// Voliteľná súkromná konfigurácia (API kľúč, adresy). Súbor sa nedá
// stiahnuť z webu — .htaccess blokuje všetko začínajúce podtržníkom.
// Jedno miesto konfigurácie: .env v koreni projektu + premenné prostredia.
require_once __DIR__ . '/_bootstrap.php';

$configFile = __DIR__ . '/_mail-config.php';
$config = is_file($configFile) ? require $configFile : [];
if (!is_array($config)) { $config = []; }

$to_email      = elvo_opt('MAIL_TO', $config, 'to_email', 'info@elvosolar.sk'); // kam chodia dopyty
$from_email    = elvo_opt('MAIL_FROM', $config, 'from_email', 'info@elvosolar.sk'); // overený odosielateľ
$from_name     = elvo_opt('MAIL_FROM_NAME', $config, 'from_name', 'Elvosolar, a.s.');
$site_name     = 'Elvosolar, a.s.';
$site_url      = elvo_opt('SITE_URL', $config, 'site_url', 'https://www.elvosolar.sk');
$logo_url      = rtrim($site_url, '/') . '/Png/ElvosolarLogoCele.png';
$resend_key    = elvo_opt('RESEND_API_KEY', $config, 'resend_key');
$brevo_key     = elvo_opt('BREVO_API_KEY', $config, 'brevo_key');
$reply_to      = elvo_opt('MAIL_REPLY_TO', $config, 'reply_to', $from_email);
$backup_email  = elvo_opt('MAIL_BACKUP', $config, 'backup_email');
// SMTP schránky na hostingu (WebAdmin/Websupport). Na Railway je SMTP
// dostupné len na Pro pláne — preto je prvou voľbou Resend HTTPS API.
$smtp_host     = elvo_opt('SMTP_HOST', $config, 'smtp_host', 'smtp.m1.websupport.sk');
$smtp_port     = (int)(elvo_env('SMTP_PORT') !== '' ? elvo_env('SMTP_PORT') : ($config['smtp_port'] ?? 465));
$smtp_secure   = strtolower(elvo_opt('SMTP_SECURE', $config, 'smtp_secure', 'ssl')); // ssl | tls | none
$smtp_user     = elvo_opt('SMTP_USER', $config, 'smtp_user', $from_email);
$smtp_pass     = elvo_env('SMTP_PASS') !== '' ? elvo_env('SMTP_PASS') : (string)($config['smtp_pass'] ?? '');
$rate_seconds  = 20; // min. interval medzi odoslaniami z jednej IP
// ====================

/** Jednotná JSON odpoveď s HTTP statusom. */
function elvo_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Bezpečne prečíta textové pole z JSONu (polia, čísla a null ignoruje). */
function field(array $data, string $key, int $max = 300, bool $singleLine = true): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) { return ''; }
    $value = (string)$value;
    if ($singleLine) {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);
    } else {
        $value = str_replace("\0", '', $value);
    }
    return mb_substr(strip_tags(trim($value)), 0, $max);
}

/* ---------- Protokolové ochrany (pred spracovaním údajov) ---------- */
// 1) Len POST — formulár sa nikdy neodosiela cez GET.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    elvo_json(405, ["success" => false, "message" => "Metóda nie je povolená."]);
}

// 2) Len požiadavky z vlastného webu (blokuje cudzie stránky a CSRF zneužitie).
$host   = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$checkUrl = $origin !== '' ? $origin : $referer;
if ($checkUrl !== '') {
    $checkHost = parse_url($checkUrl, PHP_URL_HOST);
    if (!is_string($checkHost) || strtolower($checkHost) !== $host) {
        elvo_json(403, ["success" => false, "message" => "Požiadavka bola zamietnutá."]);
    }
}

// 3) Len JSON telo s rozumnou veľkosťou (ochrana proti zahlteniu).
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') === false) {
    elvo_json(415, ["success" => false, "message" => "Nepodporovaný formát požiadavky."]);
}

$maxBody = 20000;
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxBody) {
    elvo_json(413, ["success" => false, "message" => "Požiadavka je príliš veľká."]);
}

// Čítame najviac $maxBody + 1 bajtov, aby sa telo nedalo zneužiť na vyčerpanie pamäte.
$input_raw = file_get_contents('php://input', false, null, 0, $maxBody + 1);
if (!is_string($input_raw) || $input_raw === '' || strlen($input_raw) > $maxBody) {
    elvo_json(400, ["success" => false, "message" => "Neplatné alebo chýbajúce údaje."]);
}

$data = json_decode($input_raw, true);
if (!is_array($data) || !isset($data['action'])) {
    elvo_json(400, ["success" => false, "message" => "Neplatné alebo chýbajúce údaje."]);
}
$action = field($data, 'action', 20);
if (!in_array($action, ['contact', 'cart', 'newsletter'], true)) {
    elvo_json(400, ["success" => false, "message" => "Neznáma požiadavka."]);
}

/* ---------- Spolocne ochrany ---------- */
// Neviditelna kontrola proti botom (bez zaskrtavacieho pola, pouzivatel nic nerobi):
//  a) skryte navnadove pole "elvo_hp" musi ostat prazdne - vyplnaju ho len boti,
//  b) poziadavka musi prist z prehliadaca s JavaScriptom (form_js = 1),
//  c) od nacitania stranky musi uplynut aspon 2 s (bot odosiela okamzite)
//     a najviac 12 h (stara zabudnuta karta).
// Pri podozreni vratime falosny uspech, aby bot nezistil, ze je filtrovany.
$honeypot = field($data, 'elvo_hp', 200);
$hasJs    = (int)($data['form_js'] ?? 0) === 1;
$elapsed  = (int)($data['form_time'] ?? 0);
if ($honeypot !== '' || !$hasJs || $elapsed < 2 || $elapsed > 43200) {
    echo json_encode(["success" => true]);
    exit;
}

// Jednoduchy rate-limit na subore (bez DB) - max 1 poziadavka / $rate_seconds / IP / akcia.
// IP hlavicky validujeme, aby sa do kľúča nedostal ľubovoľný reťazec.
$ip = '';
foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $ipKey) {
    $candidate = trim(explode(',', (string)($_SERVER[$ipKey] ?? ''))[0]);
    if (filter_var($candidate, FILTER_VALIDATE_IP)) { $ip = $candidate; break; }
}
if ($ip === '') { $ip = 'unknown'; }
$stampFile = sys_get_temp_dir() . '/elvo_rate_' . md5($ip . '|' . $action) . '.txt';
if (file_exists($stampFile) && (time() - (int)filemtime($stampFile)) < $rate_seconds) {
    elvo_json(429, ["success" => false, "message" => "Požiadavky posielajte s odstupom — skúste to o chvíľu znova."]);
}
@touch($stampFile);

/* ---------- HTML sablony ---------- */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Očistí vstup od riadiacich znakov (napr. CRLF) a oreže ho na max. dĺžku. */
function clean(string $s, int $max = 300): string {
    $s = str_replace(["\r", "\n", "\0"], ' ', $s);
    return mb_substr(strip_tags(trim($s)), 0, $max);
}

/** Obsah okolo: logo hlavicka + obsah + paticka */
function elvo_layout(string $preheader, string $content): string {
    global $logo_url, $site_url, $site_name;
    return '<!DOCTYPE html><html lang="sk"><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . h($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">'
        // hlavicka s logom
        . '<tr><td style="background:#0f172a;padding:22px 32px;">'
        . '<a href="' . $site_url . '" style="text-decoration:none;">'
        . '<img src="' . $logo_url . '" alt="Elvosolar" width="180" style="display:block;width:180px;height:auto;border:0;">'
        . '</a></td></tr>'
        // zeleny akcent
        . '<tr><td style="height:4px;background:linear-gradient(90deg,#10b981,#3b82f6);font-size:0;line-height:0;">&nbsp;</td></tr>'
        // obsah
        . '<tr><td style="padding:32px;">' . $content . '</td></tr>'
        // paticka
        . '<tr><td style="background:#0f172a;padding:20px 32px;font-size:12px;line-height:1.7;color:#94a3b8;">'
        . '<strong style="color:#e2e8f0;">' . h($site_name) . '</strong><br>'
        . 'E-mail: <a href="mailto:info@elvosolar.sk" style="color:#10b981;text-decoration:none;">info@elvosolar.sk</a> · '
        . '<a href="' . $site_url . '" style="color:#10b981;text-decoration:none;">www.elvosolar.sk</a><br>'
        . 'Tento e-mail bol automaticky odoslaný cez kontaktný formulár na stránke ' . h($site_url) . '.'
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

/** Tabulka udajov (klice => hodnoty) */
function elvo_table(array $rows): string {
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;">';
    $i = 0;
    foreach ($rows as $k => $v) {
        $bg = ($i % 2 === 0) ? '#f8fafc' : '#ffffff';
        $html .= '<tr>'
            . '<td style="padding:10px 14px;background:' . $bg . ';border:1px solid #e2e8f0;font-size:13px;color:#64748b;width:38%;">' . h((string)$k) . '</td>'
            . '<td style="padding:10px 14px;background:' . $bg . ';border:1px solid #e2e8f0;font-size:14px;color:#0f172a;font-weight:bold;">' . h((string)$v) . '</td>'
            . '</tr>';
        $i++;
    }
    return $html . '</table>';
}

function elvo_h1(string $text): string {
    return '<div style="font-size:22px;font-weight:bold;color:#0f172a;margin:0 0 6px 0;">' . h($text) . '</div>';
}
function elvo_p(string $text): string {
    return '<p style="font-size:15px;line-height:1.7;color:#334155;margin:12px 0;">' . h($text) . '</p>';
}
function elvo_quote(string $label, string $text): string {
    return '<div style="background:#f0fdf4;border-left:4px solid #10b981;border-radius:0 8px 8px 0;padding:14px 18px;margin:16px 0;">'
        . '<div style="font-size:12px;font-weight:bold;color:#10b981;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">' . h($label) . '</div>'
        . '<div style="font-size:14px;line-height:1.7;color:#0f172a;white-space:pre-wrap;">' . h($text) . '</div>'
        . '</div>';
}
function elvo_btn(string $text, string $url): string {
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 6px 0;"><tr>'
        . '<td style="background:#10b981;border-radius:8px;">'
        . '<a href="' . h($url) . '" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px;">' . h($text) . '</a>'
        . '</td></tr></table>';
}

function utf8_subject(string $s): string {
    return "=?utf-8?B?" . base64_encode($s) . "?=";
}

/** Formátuje meno + adresu do hlavičky From (diakritika v mene). */
function elvo_mail_address(string $name, string $email): string {
    if ($name === '') { return $email; }
    $encoded = preg_match('/[^\x20-\x7E]/', $name) ? '=?utf-8?B?' . base64_encode($name) . '?=' : $name;
    return $encoded . ' <' . $email . '>';
}

/** Odošle e-mail cez Brevo HTTPS API (bez SMTP portov). */
function elvo_brevo_send(string $to, string $subject, string $html, string $replyTo): bool
{
    global $from_email, $from_name, $brevo_key;

    $payload = [
        'sender'      => ['email' => $from_email, 'name' => $from_name],
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ];
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['replyTo'] = ['email' => $replyTo];
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) { return false; }

    $url = 'https://api.brevo.com/v3/smtp/email';
    $headers = [
        'api-key: ' . $brevo_key,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) { return true; }
        error_log('Brevo mail failed: HTTP ' . $code . ' ' . ($error !== '' ? $error : substr((string)$response, 0, 300)));
        return false;
    }

    /* Záloha bez cURL (allow_url_fopen). */
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 20,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    if ($code >= 200 && $code < 300) { return true; }
    error_log('Brevo mail failed (stream): HTTP ' . $code . ' ' . substr((string)$response, 0, 300));
    return false;
}

/** Odošle e-mail cez Resend HTTPS API (žiadne SMTP porty — ide aj na Railway). */
function elvo_resend_send(string $to, string $subject, string $html, string $replyTo): bool
{
    global $from_email, $from_name, $resend_key;

    if ($resend_key === '') { return false; }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $payload = [
        'from'    => elvo_mail_address($from_name, $from_email),
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['reply_to'] = $replyTo;
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) { return false; }

    $url = 'https://api.resend.com/emails';
    $headers = [
        'Authorization: Bearer ' . $resend_key,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) { return true; }
        error_log('Resend mail failed: HTTP ' . $code . ' ' . ($error !== '' ? $error : substr((string)$response, 0, 300)));
        return false;
    }

    /* Záloha bez cURL (allow_url_fopen). */
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 20,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    if ($code >= 200 && $code < 300) { return true; }
    error_log('Resend mail failed (stream): HTTP ' . $code . ' ' . substr((string)$response, 0, 300));
    return false;
}

/** Prečíta viacriadkovú odpoveď SMTP: [kód, text]. */
function elvo_smtp_response($fp): array
{
    $lines = [];
    $code = 0;
    while (($line = fgets($fp, 2048)) !== false) {
        $line = rtrim($line, "\r\n");
        $lines[] = $line;
        if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $m)) {
            $code = (int)$m[1];
            if ($m[2] === ' ') { break; }
            continue;
        }
        break;
    }
    return [$code, implode(' | ', $lines)];
}

/** Pošle príkaz do SMTP a vráti [kód, text] odpovede. */
function elvo_smtp_cmd($fp, string $command): array
{
    @fwrite($fp, $command . "\r\n");
    return elvo_smtp_response($fp);
}

/** Zostaví MIME správu (hlavičky + base64 telo) pre SMTP. */
function elvo_smtp_message(string $to, string $subject, string $html, string $replyTo): string
{
    global $from_email, $from_name;

    $domain = substr((string)strrchr($from_email, '@'), 1) ?: 'localhost';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . elvo_mail_address($from_name, $from_email),
        'To: <' . $to . '>',
        'Subject: ' . utf8_subject($subject),
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=utf-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Elvosolar web',
    ];
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n");
}

/** Odoslanie cez SMTP s prihlásením (SSL 465 alebo STARTTLS 587). */
function elvo_smtp_send(string $to, string $subject, string $html, string $replyTo): bool
{
    global $from_email, $smtp_host, $smtp_port, $smtp_secure, $smtp_user, $smtp_pass;

    if ($smtp_host === '' || $smtp_pass === '' || !function_exists('stream_socket_client')) {
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $domain = strtolower((string)substr((string)strrchr($from_email, '@'), 1)) ?: 'localhost';
    $scheme = $smtp_secure === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false,
        'peer_name'         => $smtp_host,
    ]]);

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($scheme . $smtp_host . ':' . $smtp_port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($fp)) {
        error_log('SMTP connect failed (' . $smtp_host . ':' . $smtp_port . '): ' . $errno . ' ' . $errstr);
        return false;
    }
    stream_set_timeout($fp, 10);

    /* Vnorená pomocná funkcia: neúspech uzavrie spojenie a vráti false. */
    $fail = static function (string $stage, int $code, string $text) use ($fp): bool {
        error_log('SMTP failed in ' . $stage . ': ' . $code . ' ' . mb_substr($text, 0, 200));
        @fclose($fp);
        return false;
    };

    [$code, $text] = elvo_smtp_response($fp);
    if ($code !== 220) { return $fail('greeting', $code, $text); }

    $ehlo = 'EHLO ' . $domain;
    [$code, $text] = elvo_smtp_cmd($fp, $ehlo);
    if ($code !== 250) { return $fail('ehlo', $code, $text); }

    if ($smtp_secure === 'tls') {
        [$code, $text] = elvo_smtp_cmd($fp, 'STARTTLS');
        if ($code !== 220) { return $fail('starttls', $code, $text); }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail('tls-handshake', 0, 'crypto failed');
        }
        [$code, $text] = elvo_smtp_cmd($fp, $ehlo);
        if ($code !== 250) { return $fail('ehlo-after-tls', $code, $text); }
    }

    if ($smtp_user !== '') {
        [$code, $text] = elvo_smtp_cmd($fp, 'AUTH LOGIN');
        if ($code === 334) {
            [$code, $text] = elvo_smtp_cmd($fp, base64_encode($smtp_user));
            if ($code === 334) {
                [$code, $text] = elvo_smtp_cmd($fp, base64_encode($smtp_pass));
            }
        }
        if ($code !== 235) {
            /* Niektoré servery ponúkajú len AUTH PLAIN. */
            [$code, $text] = elvo_smtp_cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $smtp_user . "\0" . $smtp_pass));
        }
        if ($code !== 235) { return $fail('auth', $code, $text); }
    }

    [$code, $text] = elvo_smtp_cmd($fp, 'MAIL FROM:<' . $from_email . '>');
    if ($code !== 250) { return $fail('mail-from', $code, $text); }
    [$code, $text] = elvo_smtp_cmd($fp, 'RCPT TO:<' . $to . '>');
    if ($code !== 250 && $code !== 251) { return $fail('rcpt-to', $code, $text); }
    [$code, $text] = elvo_smtp_cmd($fp, 'DATA');
    if ($code !== 354) { return $fail('data', $code, $text); }

    $message = elvo_smtp_message($to, $subject, $html, $replyTo);
    @fwrite($fp, $message . "\r\n.\r\n");
    [$code, $text] = elvo_smtp_response($fp);
    if ($code !== 250) { return $fail('send', $code, $text); }

    @elvo_smtp_cmd($fp, 'QUIT');
    @fclose($fp);
    return true;
}

/** Jednotné odoslanie: Resend API → Brevo API → SMTP → PHP mail(). */
function elvo_mail(string $to, string $subject, string $html, string $replyTo = ''): bool
{
    global $from_email, $from_name, $resend_key, $brevo_key, $reply_to;

    if ($replyTo === '') { $replyTo = $reply_to; }

    /* 1) Resend HTTPS API — na Railway (Free/Hobby) jediná funkčná cesta. */
    if ($resend_key !== '' && elvo_resend_send($to, $subject, $html, $replyTo)) {
        return true;
    }
    /* 2) Brevo HTTPS API — voliteľná záloha bez SMTP portov. */
    if ($brevo_key !== '' && elvo_brevo_send($to, $subject, $html, $replyTo)) {
        return true;
    }
    /* 3) SMTP s prihlásením — klasický hosting (na Railway len Pro plán). */
    if (elvo_smtp_send($to, $subject, $html, $replyTo)) {
        return true;
    }
    error_log('Žiadny e-mailový kanál (Resend/Brevo/SMTP) neprešiel, skúšam mail() pre ' . $to);

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=utf-8\r\n";
    $headers .= "From: " . elvo_mail_address($from_name, $from_email) . "\r\n";
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers .= "Reply-To: " . $replyTo . "\r\n";
    }
    $headers .= "X-Mailer: Elvosolar web\r\n";

    if (filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        return @mail($to, utf8_subject($subject), $html, $headers, '-f' . $from_email);
    }
    return @mail($to, utf8_subject($subject), $html, $headers);
}

/** Administrátorská notifikácia: hlavná adresa + voliteľná záložná adresa.
 *  Ak zlyhá aj záložná adresa, správa sa uloží do undelivered.log,
 *  aby sa dopyt nestratil.
 *  Návrat: 2 = e-mail odoslaný, 1 = e-mail nešiel, ale dopyt je uložený,
 *          0 = nepodarilo sa ani odoslať, ani uložiť. */
function elvo_admin_mail(string $subject, string $html, string $replyTo = ''): int
{
    global $to_email, $backup_email;

    if (elvo_mail($to_email, $subject, $html, $replyTo)) {
        return 2;
    }
    if ($backup_email !== '' && strcasecmp($backup_email, $to_email) !== 0) {
        error_log('Hlavna adresa zlyhala, posielam na zaloznu: ' . $backup_email);
        if (elvo_mail($backup_email, '[ZÁLOHA] ' . $subject, $html, $replyTo)) {
            return 2;
        }
    }
    return elvo_store_undelivered($subject, $html, $replyTo) ? 1 : 0;
}

/** Posledná záchrana: uloží správu na server, keď neprešiel ani jeden e-mail.
 *  Súbor _undelivered.log je blokovaný v .htaccess (podtržník aj .log).
 *  Zálohu si stiahnete cez správcu súborov hostingu. */
function elvo_store_undelivered(string $subject, string $html, string $replyTo): bool
{
    $record = [
        'time'     => date('c'),
        'subject'  => $subject,
        'reply_to' => $replyTo,
        'ip'       => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'html'     => $html,
    ];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) { return false; }
    $written = @file_put_contents(elvo_data_dir() . '/undelivered.log', $line . "\n", FILE_APPEND | LOCK_EX);
    if ($written === false) {
        error_log('Mail aj zaloha zlyhali a undelivered.log sa neda zapisat: ' . $subject);
        return false;
    }
    error_log('Mail aj zaloha zlyhali, sprava ulozena do undelivered.log: ' . $subject);
    return true;
}

/**
 * Uloží prijatý dopyt do súkromného denníka, ktorý zobrazuje admin.php.
 * Zápis nesmie nikdy zablokovať odoslanie e-mailu — chyby len zaloguje.
 */
function elvo_store_submission(array $record): void
{
    $dir = elvo_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('Dopyt sa nepodarilo ulozit: chyba priecinka ' . $dir);
        return;
    }
    $record = ['id' => date('Ymd-His') . '-' . bin2hex(random_bytes(4)), 'time' => date('c')] + $record;
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) { return; }
    if (@file_put_contents($dir . '/submissions.jsonl', $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        error_log('Dopyt sa nepodarilo ulozit do ' . $dir . '/submissions.jsonl');
    }
}

/** Potvrdenie odosielatelovi - "Ozveme sa vám čo najskôr" + znenie jeho správy */
function elvo_confirmation(string $to, string $name, string $subject_line, string $message, string $kind): bool {
    $content = elvo_h1("Ďakujeme, " . $name . "!")
        . elvo_p("Vaša správa bola úspešne odoslaná. Ozveme sa vám čo najskôr — zvyčajne do 24 hodín v pracovných dňoch.")
        . elvo_quote("Znenie vašej správy", $message)
        . elvo_p("Ak by ste potrebovali odpoveď ešte skôr, neváhajte nás kontaktovať priamo na info@elvosolar.sk alebo telefonicky.")
        . elvo_btn("Navštíviť našu stránku", site_url())
        . '<div style="font-size:13px;color:#94a3b8;margin-top:14px;">S pozdravom<br><strong style="color:#0f172a;">' . h($GLOBALS['site_name']) . '</strong><br>Energy systems — tepelné čerpadlá, fotovoltika, solárny ohrev, ORC</div>';
    return elvo_mail($to, $subject_line, elvo_layout($kind . " — prijaté", $content), $GLOBALS['reply_to']);
}
function site_url(): string { return $GLOBALS['site_url']; }

/* ---------- 1. KONTAKTNY FORMULAR ---------- */
if ($action === 'contact') {
    $name    = field($data, 'name', 120);
    $email   = filter_var(trim(field($data, 'email', 200)), FILTER_VALIDATE_EMAIL);
    $message = field($data, 'message', 5000, false);

    if ($name === '' || !$email || trim(strip_tags($message)) === '') {
        echo json_encode(["success" => false, "message" => "Všetky polia sú povinné a e-mail musí byť v správnom formáte."]);
        exit;
    }
    if (mb_strlen($name) > 120 || mb_strlen($message) > 5000) {
        echo json_encode(["success" => false, "message" => "Správa je príliš dlhá."]);
        exit;
    }
    $message = mb_substr(strip_tags($message), 0, 5000);
    $page = clean((string)($_SERVER['HTTP_REFERER'] ?? $site_url), 300);
    $time = date('j. n. Y H:i');

    // a) Majitel: strukturovany HTML mail
    $subject = "Nový kontaktný dopyt: " . $name;
    $content = elvo_h1("Nový kontaktný dopyt")
        . '<div style="font-size:13px;color:#94a3b8;margin:0 0 4px 0;">Prijaté ' . h($time) . ' · www.elvosolar.sk</div>'
        . elvo_table([
            "Meno a priezvisko" => $name,
            "E-mail"            => $email,
            "Stránka"           => $page,
            "Dátum a čas"       => $time,
        ])
        . elvo_quote("Znenie správy odosielateľa", $message)
        . elvo_btn("Odpovedať klientovi", "mailto:" . $email);
    $mailState = elvo_admin_mail($subject, elvo_layout("Nový kontaktný dopyt od " . $name, $content), $email);
    $okAdmin   = $mailState === 2;   // e-mail naozaj odišiel
    $leadSaved = $mailState >= 1;   // dopyt je bezpečne uložený (aj keď mail nešiel)

    // b) Odosielatel: potvrdenie
    $okUser = elvo_confirmation($email, $name, "Potvrdenie prijatia správy — Elvosolar", $message, "Kontaktný formulár");

    elvo_store_submission([
        'action'    => 'contact',
        'name'      => $name,
        'email'     => (string)$email,
        'page'      => $page,
        'subject'   => $subject,
        'message'   => $message,
        'sent'      => $okAdmin,
        'sent_user' => $okUser,
    ]);

    if ($okAdmin || $okUser || $leadSaved) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "Serveru sa nepodarilo odoslať e-mail. Skontrolujte RESEND_API_KEY alebo SMTP nastavenie."]);
    }
    exit;
}

/* ---------- 2. DOPYTOVY KOSIK (vsetky podstranky) ---------- */
if ($action === 'cart') {
    $name  = field($data, 'name', 120);
    $email = filter_var(trim(field($data, 'email', 200)), FILTER_VALIDATE_EMAIL);
    $items = field($data, 'cart_items', 4000, false);
    $total = field($data, 'total_price', 40);
    $page  = field($data, 'page', 300);

    if ($name === '' || !$email || $items === '') {
        echo json_encode(["success" => false, "message" => "Chýbajúce údaje potrebné pre odoslanie košíka."]);
        exit;
    }
    $time = date('j. n. Y H:i');
    $src  = $page !== '' ? $page : clean((string)($_SERVER['HTTP_REFERER'] ?? $site_url), 300);

    // a) Majitel
    $subject = "Nový dopyt: " . $name . ($page !== '' ? " [$page]" : '');
    $content = elvo_h1("Nový dopytový košík")
        . '<div style="font-size:13px;color:#94a3b8;margin:0 0 4px 0;">Prijaté ' . h($time) . '</div>'
        . elvo_table([
            "Meno / Firma"    => $name,
            "E-mail klienta"  => $email,
            "Stránka"         => $src,
            "Hodnota dopytu"  => ($total !== '' ? $total : '—'),
        ])
        . elvo_quote("Požadované položky", $items)
        . elvo_btn("Odpovedať klientovi", "mailto:" . $email);
    $mailState = elvo_admin_mail($subject, elvo_layout("Nový dopyt od " . $name, $content), $email);
    $okAdmin   = $mailState === 2;   // e-mail naozaj odišiel
    $leadSaved = $mailState >= 1;   // dopyt je bezpečne uložený (aj keď mail nešiel)

    // b) Odosielatel
    $msgUser = "Ďakujeme za váš dopyt. Vaše požadované položky sme prijali a ozveme sa vám čo najskôr s cenovou ponukou.\n\nPoložky:\n" . $items
             . ($total !== '' ? "\nOrientačná hodnota: $total" : '');
    $okUser = elvo_confirmation($email, $name, "Potvrdenie dopytu — Elvosolar", $msgUser, "Dopytový košík");

    elvo_store_submission([
        'action'    => 'cart',
        'name'      => $name,
        'email'     => (string)$email,
        'page'      => $src,
        'subject'   => $subject,
        'message'   => $items,
        'total'     => $total,
        'sent'      => $okAdmin,
        'sent_user' => $okUser,
    ]);

    if ($okAdmin || $okUser || $leadSaved) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "Serveru sa nepodarilo odoslať e-mail. Skontrolujte RESEND_API_KEY alebo SMTP nastavenie."]);
    }
    exit;
}

/* ---------- 3. ODBER NOTIFIKACII (novinky e-mailom) ---------- */
if ($action === 'newsletter') {
    $email = filter_var(trim(field($data, 'email', 200)), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        echo json_encode(["success" => false, "message" => "Zadajte platnú e-mailovú adresu."]);
        exit;
    }
    if (mb_strlen($email) > 200) {
        echo json_encode(["success" => false, "message" => "E-mailová adresa je príliš dlhá."]);
        exit;
    }

    $page = field($data, 'page', 300);
    $time = date('j. n. Y H:i');
    $src  = $page !== '' ? $page : clean((string)($_SERVER['HTTP_REFERER'] ?? $site_url), 300);

    // a) Notifikacia majitelovi
    $subjAdmin = "Nový odber notifikácií: " . $email;
    $contentA = elvo_h1("Nový odber notifikácií")
        . elvo_table([
            "E-mail"   => $email,
            "Stránka"  => $src,
            "Dátum"    => $time,
        ])
        . elvo_p("Uložte si adresu do svojho mailing listu (napr. Google Contacts, Mailjet free plan).");
    $mailState = elvo_admin_mail($subjAdmin, elvo_layout("Nový odber: " . $email, $contentA), $email);
    $okAdmin   = $mailState === 2;   // e-mail naozaj odišiel
    $leadSaved = $mailState >= 1;   // dopyt je bezpečne uložený (aj keď mail nešiel)

    // b) Potvrdenie odberatelovi
    $contentU = elvo_h1("Ste prihlásený/á na odber noviniek")
        . elvo_p("Práve ste sa prihlásil/á na odber noviniek a notifikácií stránky " . $site_name . ". Budeme vás informovať o novinkách, dotáciách (Zelená podnikom) a akciách.")
        . elvo_p("Ak ste o to nestáli, jednoducho odpovedajte na tento e-mail a odber zrušíme.")
        . elvo_btn("Prejsť na www.elvosolar.sk", $site_url);
    $okUser = elvo_mail($email, "Potvrdenie odberu noviniek — Elvosolar", elvo_layout("Potvrdenie odberu", $contentU));

    elvo_store_submission([
        'action'    => 'newsletter',
        'name'      => '',
        'email'     => (string)$email,
        'page'      => $src,
        'subject'   => $subjAdmin,
        'message'   => '',
        'sent'      => $okAdmin,
        'sent_user' => $okUser,
    ]);

    if ($okAdmin || $okUser || $leadSaved) {
        echo json_encode(["success" => true, "message" => "Úspešne ste prihlásený/á na odber notifikácií. Potvrdenie posielame na váš e-mail."]);
    } else {
        echo json_encode(["success" => false, "message" => "Serveru sa nepodarilo odoslať e-mail. Skúste to neskôr."]);
    }
    exit;
}

echo json_encode(["success" => false, "message" => "Neznáma požiadavka."]);
