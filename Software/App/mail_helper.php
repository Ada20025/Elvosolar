<?php
// mail_helper.php

// ==========================================
// --- NASTAVENIE ODOSIELANIA E-MAILOV ---
// ==========================================
if (!defined('USE_SMTP')) {
    define('USE_SMTP', true);                  // Ak nefunguje klasický mail, prepíšte na: true
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp-adamdz.alwaysdata.net'); // Vlastny alwaysdata SMTP (overene - funguje)
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);                   // Port (najcastejsie 587 pre TLS, 465 pre SSL)
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', getenv('SMTP_USER') ?: 'adamdz@alwaysdata.net');  // Prihlasovacie meno (overene)
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: '1Adamko.');          // Heslo k e-mailu (overene)
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'tls');           // Šifrovanie: 'tls', 'ssl' alebo 'none'
}
// ==========================================

if (!function_exists('elvo_mail_relay')) {
    // Posle mail cez alwaysdata relay (mail() tam funguje). Vrati true/false.
    // Ak RELAY_URL nie je nastavene, vrati false (ziaden timeout).
    // Posle mail cez Resend HTTPS API - FUNGUJE NA RAILWAY (SMTP blokuju, HTTPS nie).
    // Nastav na Railway premennu RESEND_API_KEY (resend.com - 3000 mailov/mesiac zdarma).
    // Volitelne RESEND_FROM (default: onboarding@resend.dev).
    function elvo_mail_resend($to, $subject, $message_html) {
        $api_key = getenv('RESEND_API_KEY');
        if (!$api_key || trim($api_key) === '') return false;
        $from = getenv('RESEND_FROM') ?: 'ElvoControll <onboarding@resend.dev>';
        // Predmet s diakritikou — Resend akceptuje plain UTF-8, ale bez RFC encoding
        $payload = json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $message_html,
        ], JSON_UNESCAPED_UNICODE);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $api_key . "\r\n",
            'content' => $payload,
            'timeout' => 3,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);
        if ($resp === false) { error_log('[RESEND] Spojenie zlyhalo (timeout/DNS)'); return false; }
        $ok = (strpos($resp, '"id"') !== false);
        if (!$ok) {
            // 403 = restricted key / From doména nie je overená (resend.dev posiela len na vlastný účet)
            if (strpos($resp, '403') !== false || strpos($resp, 'restricted') !== false || strpos($resp, 'verified') !== false) {
                error_log('[RESEND] RESTRICTED (From doména neoverená) pre ' . $to . ': ' . substr($resp, 0, 200));
                $GLOBALS['elvo_mail_last_error'] = 'RESEND: odosielateľ nie je overený (From doména). Over doménu v Resende alebo nastav RESEND_FROM.';
            } else {
                error_log('[RESEND] Chyba pre ' . $to . ': ' . substr($resp, 0, 300));
                $GLOBALS['elvo_mail_last_error'] = 'RESEND: ' . substr($resp, 0, 150);
            }
        }
        return $ok;
    }
    function elvo_mail_relay($to, $subject, $message_html, $accent_color = '#007aff') {
        $relay = getenv('MAIL_RELAY_URL');
        if (!$relay || trim($relay) === '') return false;
        $payload = json_encode([
            'to' => $to,
            'subject' => $subject,
            'html' => $message_html,
            'accent' => $accent_color,
        ]);
        $relay_key = getenv('MAIL_RELAY_KEY') ?: 'elvo-relay-2026';
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Relay-Key: " . $relay_key . "\r\n",
            'content' => $payload,
            'timeout' => 3,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($relay, false, $ctx);
        return ($resp !== false && strpos($resp, '"success":true') !== false);
    }
}

if (!function_exists('send_elvo_email')) {
    function send_elvo_email($to, $subject, $title, $content_html, $accent_color = '#007aff') {
        $domain = $_SERVER['SERVER_NAME'] ?? 'elvosolar.sk';
        if (substr($domain, 0, 4) === 'www.') {
            $domain = substr($domain, 4);
        }
        
        $from_email = "no-reply@" . $domain;

        // Moderná tmavá šablóna - ladi s aplikaciou ElvoControll
        $year = date('Y');
                // LUXUSNA tmava sablona — foto pozadie (VML pre Outlook), sklenené panely, gradient.
        // Nowdoc <<<'ELVOTPL' = ziadne PHP escapovanie (predchadzajuce \" rozbijali background-image).
        $year = date('Y');
        $img_url = 'https://adamdz.alwaysdata.net/templates/Fotovoltika1.jpg';
        $logo_url = 'https://adamdz.alwaysdata.net/templates/ElvosolarLogo1.png';

        $tmpl = <<<'ELVOTPL'
<!DOCTYPE html>
<html lang="sk" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>ElvoControll</title>
</head>
<body style="margin:0;padding:0;background-color:#04060d;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#04060d;">
<tr><td align="center" style="padding:30px 12px;">
<!--[if mso]>
<v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false" style="width:600px;"><v:fill type="frame" src="%IMG%" color="#0b1226" /><v:textbox inset="0,0,0,0"><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;border-radius:24px;overflow:hidden;background-color:#0b1226;background-image:url('%IMG%');background-size:cover;background-position:center;background-repeat:no-repeat;">
  <tr>
    <td style="height:6px;line-height:6px;font-size:6px;background-color:#10b981;background-image:linear-gradient(90deg,#10b981 0%,#06b6d4 50%,#818cf8 100%);">&nbsp;</td>
  </tr>
  <tr>
    <td align="center" bgcolor="#04070f" style="padding:34px 36px 20px 36px;background-color:rgba(4,7,15,0.74);">
      <img src="%LOGO%" alt="ElvoControll" width="190" style="max-height:52px;width:auto;display:block;border:0;">
      <div style="margin-top:16px;display:inline-block;padding:7px 18px;border-radius:999px;border:1px solid rgba(52,211,153,0.35);background-color:#0a2b1f;background-color:rgba(16,185,129,0.14);font-size:10px;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:#34d399;font-family:Consolas,'Courier New',monospace;">Smart&nbsp;EMS</div>
    </td>
  </tr>
  <tr>
    <td style="padding:10px 24px 4px 24px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#050914" style="background-color:#050914;background-color:rgba(4,8,18,0.88);border-radius:18px;border:1px solid rgba(255,255,255,0.10);">
        <tr>
          <td style="padding:34px 32px 30px 32px;">
            <h1 style="margin:0 0 6px 0;font-size:26px;font-weight:900;color:#ffffff;letter-spacing:-0.02em;line-height:1.25;">%TITLE%</h1>
            <div style="width:56px;height:4px;border-radius:2px;background-color:#10b981;background-color:%ACCENT%;margin:14px 0 22px 0;">&nbsp;</div>
            <div style="font-size:15px;line-height:1.75;color:#d5deeb;">%CONTENT%</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td bgcolor="#03060d" style="padding:26px 30px 30px 30px;background-color:rgba(3,6,13,0.90);border-top:1px solid rgba(255,255,255,0.08);text-align:center;">
      <div style="font-size:11px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;color:#34d399;font-family:Consolas,'Courier New',monospace;">ElvoControll &middot; Smart EMS</div>
      <p style="margin:12px 0 5px 0;font-size:11px;color:#8494ab;line-height:1.7;">Automaticky generovaná správa z portálu ElvoControll.</p>
      <p style="margin:0;font-size:10px;color:#55637a;line-height:1.6;">&copy; 2011&ndash;%YEAR% Elvosolar s.r.o. &middot; Všetky práva vyhradené</p>
    </td>
  </tr>
</table>
<!--[if mso]></v:textbox></v:rect><![endif]-->
<div style="margin-top:14px;font-size:10px;color:#55637a;">Powered by <span style="color:#34d399;font-weight:700;">ElvoControll</span> Smart EMS</div>
</td></tr>
</table>
</body>
</html>
ELVOTPL;

        $message_html = str_replace(
            ['%TITLE%', '%CONTENT%', '%YEAR%', '%IMG%', '%LOGO%', '%ACCENT%'],
            [$title, $content_html, $year, $img_url, $logo_url, $accent_color],
            $tmpl
        );

        // Zakódovanie predmetu správy do formátu RFC Base64 pre bezchybnú diakritiku a antispam
        $subject_encoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";

        // 0a. RESEND (HTTPS API - jedina cesta ako posielat maily z Railway free planu)
        if (elvo_mail_resend($to, $subject, $message_html)) {
            return true;
        }

        // 0b. REŽIM RELAY (posielanie cez alwaysdata - bez SSL certifikatov a nastavovania)
        if (elvo_mail_relay($to, $subject, $message_html, $accent_color)) {
            return true;
        }

        // RYCHLY LOGIN: na Railway je SMTP blokovane (Free plan) - socket by visel 5 s pri kazdom prihlaseni.
        // Bez RESEND_API_KEY / MAIL_RELAY_URL na Railway SMTP vobec neskusame (kod sa zobrazi na obrazovke).
        $is_railway = (strpos(($_SERVER['SERVER_NAME'] ?? ''), 'railway.app') !== false || getenv('RAILWAY_ENVIRONMENT') !== false);
        $relay_configured = (getenv('MAIL_RELAY_URL') && trim(getenv('MAIL_RELAY_URL')) !== '');
        if ($is_railway && !$relay_configured) {
            error_log("[MAIL] Railway bez resend/relay - SMTP preskocene (rychly login), kod zobrazeny na obrazovke");
            return false;
        }

        // 1. REŽIM SMTP (iba ked je heslo nastavene - inac by Gmail spojenie travilo)
        $has_pass = defined('SMTP_PASS') && trim(SMTP_PASS) !== '';
        if (defined('USE_SMTP') && USE_SMTP === true && $has_pass) {
            $host = SMTP_HOST;
            $port = SMTP_PORT;
            $user = SMTP_USER;
            $pass = SMTP_PASS;
            $encryption = strtolower(SMTP_ENCRYPTION);

            $socket_host = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);

            $socket = @stream_socket_client($socket_host . ':' . $port, $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $context);
            
            if (!$socket) {
                error_log("SMTP Pripojenie zlyhalo: $errstr ($errno)");
                return false;
            }

            $read_response = function($socket) {
                $response = '';
                while (($line = fgets($socket, 512)) !== false) {
                    $response .= $line;
                    if (substr($line, 3, 1) == ' ') { break; }
                }
                return $response;
            };

            $read_response($socket);
            fwrite($socket, "EHLO " . $domain . "\r\n");
            $read_response($socket);

            if ($encryption === 'tls') {
                fwrite($socket, "STARTTLS\r\n");
                $starttls_res = $read_response($socket);
                if (strpos($starttls_res, '220') === false) {
                    error_log("STARTTLS príkaz zamietnutý serverom: " . $starttls_res);
                    fclose($socket);
                    return false;
                }
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    error_log("SMTP TLS handshake zlyhal.");
                    fclose($socket);
                    return false;
                }
                fwrite($socket, "EHLO " . $domain . "\r\n");
                $read_response($socket);
            }

            if (!empty($user) && !empty($pass)) {
                fwrite($socket, "AUTH LOGIN\r\n");
                $read_response($socket);
                fwrite($socket, base64_encode($user) . "\r\n");
                $read_response($socket);
                fwrite($socket, base64_encode($pass) . "\r\n");
                $auth_res = $read_response($socket);
                if (strpos($auth_res, '235') === false) {
                    error_log("SMTP Autentifikácia zlyhala: " . $auth_res);
                    fclose($socket);
                    return false;
                }
            }

            $sender = !empty($user) ? $user : $from_email;
            $from_name = 'ElvoControll';
            fwrite($socket, "MAIL FROM: <" . $sender . ">\r\n");
            $read_response($socket);
            fwrite($socket, "RCPT TO: <" . $to . ">\r\n");
            $read_response($socket);
            fwrite($socket, "DATA\r\n");
            $read_response($socket);

            $message_id = "<" . bin2hex(random_bytes(16)) . "@" . $domain . ">";

            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . $from_name . " <" . $sender . ">\r\n";
            $headers .= "Reply-To: support@" . $domain . "\r\n";
            $headers .= "To: <" . $to . ">\r\n";
            $headers .= "Subject: " . $subject_encoded . "\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "Message-ID: " . $message_id . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            $headers .= "\r\n";

            fwrite($socket, $headers . $message_html . "\r\n.\r\n");
            $data_res = $read_response($socket);

            fwrite($socket, "QUIT\r\n");
            fclose($socket);

            $success = (strpos($data_res, '250') !== false);
            if ($success) return true;
            error_log("SMTP Server odmietol správu: " . $data_res . " -> skúšam native mail()");
        }

        // 2. REŽIM NATIVE MAIL (Klasická funkcia mail() v PHP pre Linux)
        $eol = "\n"; 
        $message_id = "<" . bin2hex(random_bytes(16)) . "@" . $domain . ">";
        
        $headers = "MIME-Version: 1.0" . $eol;
        $headers .= "Content-Type: text/html; charset=UTF-8" . $eol;
        $headers .= "From: ElvoSolar Control <" . $from_email . ">" . $eol;
        $headers .= "Reply-To: support@" . $domain . $eol;
        $headers .= "Message-ID: " . $message_id . $eol;
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Mail s timeoutom - na Railway mail() casto hanguje
        $host_hint = $_SERVER['SERVER_NAME'] ?? '';
        $is_railway = (strpos($host_hint, 'railway.app') !== false || getenv('RAILWAY_ENVIRONMENT') !== false);
        if ($is_railway && !$has_pass) {
            // Na Railway bez SMTP: mail() zvykne hanguj 15 s a aj tak neodide.
            // Skusime raz s kratym timeoutom cez process, inak vzdame.
            error_log("[MAIL] Railway bez SMTP_PASS - native mail() preskoceny (hang prevention)");
            return false;
        }
        $start = time();
        $result = false;
        try {
            $result = @mail($to, $subject_encoded, $message_html, $headers, "-f " . $from_email);
        } catch (Exception $e) {
            error_log("Mail exception: " . $e->getMessage());
        }
        if (!$result && (time() - $start) < 2) {
            try {
                $result = @mail($to, $subject_encoded, $message_html, $headers);
            } catch (Exception $e) {}
        }
        
        if (!$result) {
            error_log("Chyba PHP mail(). Skontrolujte logy alebo aktivujte SMTP režim.");
        }
        
        return $result;
    }
}