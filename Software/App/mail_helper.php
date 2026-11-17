<?php
// mail_helper.php

// ==========================================
// --- NASTAVENIE ODOSIELANIA E-MAILOV ---
// ==========================================
if (!defined('USE_SMTP')) {
    define('USE_SMTP', true);                  // Ak nefunguje klasický mail, prepíšte na: true
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', getenv('SMTP_HOST') ?: ''); // nastav v Railway premenných (napr. smtp-adamdz.alwaysdata.net)
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);                   // Port (najcastejsie 587 pre TLS, 465 pre SSL)
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', getenv('SMTP_USER') ?: '');   // Prihlasovacie meno — IBA z premennej prostredia
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: '');   // Heslo — NIKDY v kóde, iba z premennej prostredia
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
        // Obrázky v maily musia byť na verejne dostupnej adrese bez prihlásenia
        // (mailový klient ich sťahuje z internetu). Predtým tu bol mŕtvy host
        // adamdz.alwaysdata.net, preto sa logo v mailoch nenačítalo.
        // Overené: oba odkazy nižšie vracajú 200 + správny Content-Type.
        // Prepísať sa dá premennými MAIL_LOGO_URL / MAIL_BG_IMAGE.
        $logo_url = (string)(getenv('MAIL_LOGO_URL') ?: 'https://raw.githubusercontent.com/dzurkoadam1-la/Hardware/main/Software/App/templates/ElvosolarLogo1.png');
        $img_url  = (string)(getenv('MAIL_BG_IMAGE') ?: 'https://raw.githubusercontent.com/dzurkoadam1-la/Hardware/main/Software/Web/Png/Fotovoltika1.jpg');

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

        // 1. REŽIM SMTP (iba keď je host aj heslo nastavené - inak by spojenie viselo)
        $has_pass = defined('SMTP_PASS') && trim(SMTP_PASS) !== '' && trim((string)SMTP_HOST) !== '';
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

// ==========================================================
// --- SAMOSTATNE LAYOUTY JEDNOTLIVYCH TYPOV E-MAILOV ---
// Kazdy typ spravy ma vlastnu strukturu (nie jednu spolocnu sablonu):
//   * overovaci kod ....... tmavy "terminalovy" panel s velkym kodom + meta o zariadeni
//   * nove zariadenie ..... oranzovy vystrazny panel + tabulka udajov + CTA
//   * privitanie .......... krok-za-krokom onboarding s cislovanymi krokmi
// Vsetky vracaju HTML, ktory sa vklada do spolocneho rámčeka send_elvo_email().
// ==========================================================

if (!function_exists('elvo_mail_meta_rows')) {
    /** Zoznam "stitek -> hodnota" (meta udaje). Kazdy riadok oddeleny linkou. */
    function elvo_mail_meta_rows($rows, $label_color = '#94a3b8') {
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0 0;border-collapse:collapse;">';
        $i = 0;
        foreach ($rows as $label => $value) {
            $border = ($i > 0) ? 'border-top:1px solid rgba(255,255,255,0.07);' : '';
            $out .= '<tr>'
                 . '<td style="padding:10px 0;' . $border . 'width:38%;font-size:9px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:' . $label_color . ';font-family:Consolas,\'Courier New\',monospace;">' . $label . '</td>'
                 . '<td style="padding:10px 0;' . $border . 'font-size:12px;color:#e2e8f0;line-height:1.6;">' . $value . '</td>'
                 . '</tr>';
            $i++;
        }
        return $out . '</table>';
    }
}

if (!function_exists('elvo_mail_tpl_code')) {
    /** Overovaci kod / 2FA: jeden velky kod, okolo neho udaje o zariadeni a moznost zablokovat prihlasenie. */
    function elvo_mail_tpl_code($code, $unsub_url = '', $heading = '') {
        $ua = htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Neznáme zariadenie', 0, 70));
        $ip = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '-');

        $html  = '<div style="display:inline-block;padding:5px 13px;border-radius:999px;background-color:rgba(99,102,241,0.14);border:1px solid rgba(129,140,248,0.35);font-size:9px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;color:#a5b4fc;font-family:Consolas,\'Courier New\',monospace;">2FA &middot; Overenie prihlásenia</div>';
        if ($heading !== '') {
            $html .= '<p style="margin:14px 0 0 0;font-size:13px;line-height:1.7;color:#94a3b8;">' . htmlspecialchars($heading) . '</p>';
        }
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0 0;"><tr>'
               . '<td align="center" style="padding:26px 16px;border-radius:20px;border:1px dashed rgba(52,211,153,0.45);background-color:#07130f;background-image:linear-gradient(135deg,rgba(16,185,129,0.10) 0%,rgba(4,8,18,0.92) 100%);">'
               . '<div style="font-size:9px;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:#6ee7b7;font-family:Consolas,\'Courier New\',monospace;">Váš jednorazový kód</div>'
               . '<div style="margin-top:14px;font-size:42px;line-height:1.05;font-weight:700;letter-spacing:12px;color:#f8fafc;font-family:Consolas,\'Courier New\',monospace;padding-left:12px;">' . htmlspecialchars($code) . '</div>'
               . '<div style="margin-top:14px;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:#64748b;">Platí 10 minút &middot; jednorazový</div>'
               . '</td></tr></table>'
               . elvo_mail_meta_rows([
                    'Zariadenie' => $ua,
                    'IP adresa' => $ip,
                    'Čas' => date('d.m.Y H:i'),
                 ])
               . '<p style="margin:18px 0 0 0;font-size:12px;line-height:1.7;color:#94a3b8;">Kód zadajte na overovacej stránke. Nikomu ho neposielajte &mdash; ani zamestnancovi Elvosolaru.</p>';

        if ($unsub_url !== '') {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0 0;"><tr><td align="center">'
                   . '<a href="' . htmlspecialchars($unsub_url) . '" style="display:inline-block;padding:12px 26px;border-radius:10px;background-color:rgba(244,63,94,0.16);border:1px solid rgba(244,63,94,0.45);color:#fda4af;text-decoration:none;font-size:12px;font-weight:800;">To nie som ja &mdash; zablokovať prihlásenie</a>'
                   . '</td></tr></table>'
                   . '<p style="margin:10px 0 0 0;font-size:11px;line-height:1.6;color:#64748b;text-align:center;">Kliknutím prihlásenie z tohto zariadenia odmietnete.</p>';
        }
        return $html;
    }
}

if (!function_exists('elvo_mail_tpl_new_device')) {
    /** Nove zariadenie: vystrazny panel, tabulka udajov a vyzva skontrolovat ucet. */
    function elvo_mail_tpl_new_device($device, $ip, $when = '', $account = '', $action_url = '/profile') {
        if ($when === '') { $when = date('d.m.Y H:i'); }
        $rows = [
            'Zariadenie' => htmlspecialchars($device),
            'IP adresa' => htmlspecialchars($ip),
            'Čas' => htmlspecialchars($when),
        ];
        if ($account !== '') { $rows['Účet'] = htmlspecialchars($account); }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
             . '<td style="padding:20px 22px;border-radius:18px;background-color:rgba(245,158,11,0.10);border:1px solid rgba(245,158,11,0.40);">'
             . '<div style="font-size:9px;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:#fbbf24;font-family:Consolas,\'Courier New\',monospace;">&#9888; Bezpečnostné upozornenie</div>'
             . '<div style="margin:10px 0 6px 0;font-size:21px;font-weight:900;color:#ffffff;line-height:1.25;">Nové zariadenie v účte</div>'
             . '<div style="font-size:13px;line-height:1.65;color:#cbd5e1;">Do vášho účtu ElvoControll sa prihlásilo zariadenie, ktoré sme u vás ešte nevideli.</div>'
             . '</td></tr></table>'
             . elvo_mail_meta_rows($rows, '#fbbf24')
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0 0;"><tr><td align="center">'
             . '<a href="' . htmlspecialchars($action_url) . '" style="display:inline-block;padding:13px 30px;border-radius:12px;background-color:#f59e0b;color:#1a1204;text-decoration:none;font-size:13px;font-weight:900;">Skontrolovať prihlásenia</a>'
             . '</td></tr></table>'
             . '<p style="margin:14px 0 0 0;font-size:12px;line-height:1.7;color:#fda4af;">Ak ste to neboli vy, okamžite si zmeňte heslo v Profile a odhláste toto zariadenie.</p>';
    }
}

if (!function_exists('elvo_mail_tpl_welcome')) {
    /** Privitanie: onboarding v troch krokoch, zelena CTA. Uplne iny layout ako 2FA. */
    function elvo_mail_tpl_welcome($username, $login_url) {
        $steps = [
            ['Prihláste sa', 'E-mailom a heslom, ktoré ste si zvolili pri registrácii.'],
            ['Pridajte zariadenie', 'Striedač alebo SmartLogger cez sprievodcu v aplikácii.'],
            ['Sledujte úsporu', 'Výroba, batéria a spotový trh na jednom dashboarde.'],
        ];
        // JEDNO privitanie: stitok "Ucet aktivovany" + jediny pozdrav (ziadne duplicitne nadpisy).
        $html  = '<div style="font-size:9px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;color:#34d399;font-family:Consolas,\'Courier New\',monospace;">Účet aktivovaný</div>'
               . '<p style="margin:12px 0 0 0;font-size:16px;font-weight:700;line-height:1.6;color:#e2e8f0;">Dobrý deň, ' . htmlspecialchars($username) . ' — váš účet v ElvoControll je pripravený.</p>'
               . '<p style="margin:8px 0 0 0;font-size:13px;line-height:1.7;color:#94a3b8;">Toto sú prvé tri kroky:</p>';

        foreach ($steps as $i => $step) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;"><tr>'
                   . '<td width="34" valign="top" style="width:34px;">'
                   . '<div style="width:26px;height:26px;border-radius:50%;background-color:rgba(16,185,129,0.16);border:1px solid rgba(52,211,153,0.40);color:#34d399;font-size:12px;font-weight:900;line-height:24px;text-align:center;font-family:Consolas,\'Courier New\',monospace;">' . ($i + 1) . '</div>'
                   . '</td>'
                   . '<td valign="top" style="padding-left:10px;">'
                   . '<div style="font-size:13px;font-weight:800;color:#f1f5f9;">' . $step[0] . '</div>'
                   . '<div style="margin-top:2px;font-size:12px;line-height:1.6;color:#94a3b8;">' . $step[1] . '</div>'
                   . '</td></tr></table>';
        }

        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0 0;"><tr><td align="center">'
               . '<a href="' . htmlspecialchars($login_url) . '" style="display:inline-block;padding:14px 34px;border-radius:12px;background-color:#10b981;color:#04241a;text-decoration:none;font-size:13px;font-weight:900;">Otvoriť ElvoControll</a>'
               . '</td></tr></table>'
               . '<p style="margin:16px 0 0 0;font-size:11px;line-height:1.7;color:#64748b;text-align:center;">Tip: aplikáciu si pridáte na plochu telefónu cez &bdquo;Pridať na plochu&ldquo; v prehliadači.</p>';

        return $html;
    }
}