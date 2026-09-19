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
        $payload = json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $message_html,
        ]);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $api_key . "\r\n",
            'content' => $payload,
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);
        if ($resp === false) return false;
        $ok = (strpos($resp, '"id"') !== false);
        if (!$ok) error_log('[RESEND] Chyba: ' . substr($resp, 0, 200));
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
            'timeout' => 8,
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
        $message_html = '
        <!DOCTYPE html>
        <html lang="sk">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="color-scheme" content="dark">
            <meta name="supported-color-schemes" content="dark">
            <title>' . htmlspecialchars($subject) . '</title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #05070f; font-family: system-ui, -apple-system, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #05070f; padding: 36px 14px;">
                <tr>
                    <td align="center">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background: linear-gradient(160deg, #0b1226 0%, #070b18 100%); border-radius: 22px; overflow: hidden; border: 1px solid rgba(255,255,255,0.09); box-shadow: 0 20px 60px rgba(0,0,0,0.55);">
                            <!-- Horný gradient prúžok -->
                            <tr>
                                <td style="background: linear-gradient(90deg, #10b981 0%, #06b6d4 50%, #6366f1 100%); height: 5px; line-height: 5px; font-size: 5px;">&nbsp;</td>
                            </tr>
                            <!-- Hlavička s logom -->
                            <tr>
                                <td align="center" style="padding: 30px 38px 20px 38px; background-color: transparent; border-bottom: 1px solid rgba(255,255,255,0.06);">
                                    <img src="https://adamdz.alwaysdata.net/templates/ElvosolarLogo1.png" alt="ElvoControll" style="max-height: 40px; width: auto; display: block;" border="0">
                                    <div style="margin-top: 10px; font-size: 9px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: #34d399; font-family: monospace;">SMART EMS</div>
                                </td>
                            </tr>
                            <!-- Hlavný obsah -->
                            <tr>
                                <td style="padding: 34px 38px 30px 38px;">
                                    <h1 style="margin: 0 0 16px 0; font-size: 21px; font-weight: 800; color: #f9fafb; letter-spacing: -0.02em; line-height: 1.3;">' . $title . '</h1>
                                    <div style="font-size: 14px; line-height: 1.7; color: #cbd5e1;">
                                        ' . $content_html . '
                                    </div>
                                </td>
                            </tr>
                            <!-- Pätička správy -->
                            <tr>
                                <td style="padding: 24px 38px; background-color: rgba(255,255,255,0.02); border-top: 1px solid rgba(255,255,255,0.06); text-align: center;">
                                    <p style="margin: 0 0 6px 0; font-size: 10px; color: #64748b; line-height: 1.6;">
                                        Toto je automaticky generovaná správa z portálu ElvoControll.
                                    </p>
                                    <p style="margin: 0; font-size: 10px; color: #475569; line-height: 1.6;">
                                        &copy; 2011&ndash;' . $year . ' Elvosolar s.r.o. Všetky práva vyhradené.
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <div style="margin-top: 14px; font-size: 10px; color: #475569;">Powered by ElvoControll Smart EMS</div>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ';

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

            $socket = @stream_socket_client($socket_host . ':' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
            
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