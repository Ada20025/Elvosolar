<?php
// mail_helper.php - posielanie mailov cez WebSupport
// 1. SMTP schránky, 2. PHP mail()
// odosielateľ musí byť existujúca schránka, inak WebSupport mail zahodí
//
// údaje schránky patria do config.mail.php (nie je v gite):
//   WebSupport panel → Emaily → Prihlasovacie údaje
//   return [
//       'mail_from'       => 'info@elvosolar.sk',   // MUSÍ byť existujúca schránka na doméne
//       'smtp_host'       => 'smtp.m1.websupport.sk',
//       'smtp_port'       => 465,
//       'smtp_encryption' => 'ssl',                 // 'ssl' (465) alebo 'tls' (587)
//       'smtp_user'       => 'info@elvosolar.sk',   // celá e-mailová adresa schránky
//       'smtp_pass'       => 'heslo-schranky',
//   ];
// hodnoty so "SEM_" = nevyplnené

$GLOBALS['elvo_mail_cfg'] = [];
foreach (['config.mail.php', 'config.local.php'] as $elvo_mail_file) {
    $elvo_mail_path = __DIR__ . '/' . $elvo_mail_file;
    if (!is_file($elvo_mail_path)) continue;
    $elvo_mail_data = @include $elvo_mail_path;
    if (!is_array($elvo_mail_data)) continue;
    foreach (['mail_from', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_pass'] as $elvo_mail_k) {
        if (!isset($GLOBALS['elvo_mail_cfg'][$elvo_mail_k]) && isset($elvo_mail_data[$elvo_mail_k])) {
            $GLOBALS['elvo_mail_cfg'][$elvo_mail_k] = $elvo_mail_data[$elvo_mail_k];
        }
    }
}

if (!function_exists('elvo_mail_conf')) {
    /** hodnota z configu, potom z prostredia, inak predvolená */
    function elvo_mail_conf($key, $env, $default = '') {
        $v = $GLOBALS['elvo_mail_cfg'][$key] ?? '';
        if (is_string($v) && strpos(trim($v), 'SEM_') === 0) $v = '';
        if ($v === '' || $v === null) $v = getenv($env);
        if ($v === false || $v === null || trim((string)$v) === '') $v = $default;
        return $v;
    }
}

// ==========================================
// --- NASTAVENIE ODOSIELANIA MAILOV ---
// ==========================================
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', (string)elvo_mail_conf('mail_from', 'MAIL_FROM', 'info@elvosolar.sk'));
}
if (!defined('USE_SMTP')) {
    define('USE_SMTP', true);                  // false = posielať iba cez mail()
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', (string)elvo_mail_conf('smtp_host', 'SMTP_HOST', 'smtp.m1.websupport.sk'));
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)elvo_mail_conf('smtp_port', 'SMTP_PORT', 465));
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', (string)elvo_mail_conf('smtp_user', 'SMTP_USER', ''));   // celá adresa schránky
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', (string)elvo_mail_conf('smtp_pass', 'SMTP_PASS', ''));   // nikdy nie priamo v kóde
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', strtolower((string)elvo_mail_conf('smtp_encryption', 'SMTP_ENCRYPTION', 'ssl')));
}
// ==========================================

if (!function_exists('elvo_mail_smtp')) {
    /**
     * pošle mail cez SMTP; true = odoslané, chyba v elvo_mail_last_error
     */
    function elvo_mail_smtp($to, $subject_encoded, $message_html, $from_email, $domain) {
        $host = (string)SMTP_HOST;
        $port = (int)SMTP_PORT;
        $user = (string)SMTP_USER;
        $pass = (string)SMTP_PASS;
        $encryption = strtolower((string)SMTP_ENCRYPTION);

        // Poistenie proti zámene: 587 = STARTTLS (tls), 465 = SSL hneď po pripojení.
        // Pri zlej kombinácii sa spojenie neotvorí a mail sa ticho stratí.
        if ($port === 587 && $encryption === 'ssl') {
            $encryption = 'tls';
            error_log('[MAIL] port 587 so ssl → prepínam na STARTTLS (tls).');
        } elseif ($port === 465 && $encryption === 'tls') {
            $encryption = 'ssl';
            error_log('[MAIL] port 465 s tls → prepínam na SSL.');
        }

        // odosielateľ = účet schránky, inak MAIL_FROM
        $sender = $user !== '' ? $user : ($from_email !== '' ? $from_email : MAIL_FROM);
        $sender_email = $sender;
        if (preg_match('/<([^>]+)>/', $sender, $mSender)) $sender_email = trim($mSender[1]);

        $socket_host = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]);
        $socket = @stream_socket_client($socket_host . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            error_log('[MAIL] SMTP pripojenie zlyhalo (' . $host . ':' . $port . '): ' . $errstr . ' (' . $errno . ')');
            $GLOBALS['elvo_mail_last_error'] = 'SMTP pripojenie na ' . $host . ':' . $port . ' zlyhalo (' . $errstr . ').';
            return false;
        }
        stream_set_timeout($socket, 8);

        $read = function ($socket) {
            $response = '';
            while (($line = fgets($socket, 512)) !== false) {
                $response .= $line;
                if (strlen($line) > 3 && substr($line, 3, 1) === ' ') break;
            }
            return $response;
        };
        $cmd = function ($socket, $line) use ($read) {
            @fwrite($socket, $line . "\r\n");
            return $read($socket);
        };

        $read($socket);                                  // banner 220
        $cmd($socket, 'EHLO ' . $domain);

        if ($encryption === 'tls') {
            $st = $cmd($socket, 'STARTTLS');
            if (strpos($st, '220') === false) {
                error_log('[MAIL] STARTTLS odmietnutý: ' . trim($st));
                $GLOBALS['elvo_mail_last_error'] = 'Server odmietol STARTTLS (skús port 465 so ssl).';
                fclose($socket);
                return false;
            }
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('[MAIL] TLS handshake zlyhal.');
                $GLOBALS['elvo_mail_last_error'] = 'TLS handshake so SMTP serverom zlyhal.';
                fclose($socket);
                return false;
            }
            $cmd($socket, 'EHLO ' . $domain);
        }

        if ($user !== '' && $pass !== '') {
            $auth = $cmd($socket, 'AUTH LOGIN');
            if (strpos($auth, '334') === false) {
                error_log('[MAIL] AUTH LOGIN odmietnutý: ' . trim($auth));
                $GLOBALS['elvo_mail_last_error'] = 'SMTP server odmietol AUTH LOGIN.';
                fclose($socket);
                return false;
            }
            $cmd($socket, base64_encode($user));
            $authRes = $cmd($socket, base64_encode($pass));
            if (strpos($authRes, '235') === false) {
                error_log('[MAIL] SMTP autentifikácia zlyhala pre ' . $user . ': ' . trim($authRes));
                $GLOBALS['elvo_mail_last_error'] = 'Prihlásenie schránky ' . $user . ' zlyhalo — skontroluj heslo v config.mail.php.';
                fclose($socket);
                return false;
            }
        }

        $fromRes = $cmd($socket, 'MAIL FROM: <' . $sender_email . '>');
        if (strpos($fromRes, '250') === false) {
            error_log('[MAIL] MAIL FROM odmietnutý (' . $sender_email . '): ' . trim($fromRes));
            $GLOBALS['elvo_mail_last_error'] = 'Server odmietol odosielateľa ' . $sender_email . ' — musí to byť existujúca schránka na hostingu.';
            fclose($socket);
            return false;
        }
        $rcptRes = $cmd($socket, 'RCPT TO: <' . $to . '>');
        if (strpos($rcptRes, '250') === false) {
            error_log('[MAIL] RCPT TO odmietnutý (' . $to . '): ' . trim($rcptRes));
            $GLOBALS['elvo_mail_last_error'] = 'Server odmietol príjemcu ' . $to . '.';
            fclose($socket);
            return false;
        }
        $dataRes = $cmd($socket, 'DATA');
        if (strpos($dataRes, '354') === false) {
            error_log('[MAIL] DATA odmietnuté: ' . trim($dataRes));
            $GLOBALS['elvo_mail_last_error'] = 'Server odmietol telo správy (DATA).';
            fclose($socket);
            return false;
        }

        $message_id = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: ElvoControll <" . $sender_email . ">\r\n";
        $headers .= "Reply-To: support@" . $domain . "\r\n";
        $headers .= "To: <" . $to . ">\r\n";
        $headers .= "Subject: " . $subject_encoded . "\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: " . $message_id . "\r\n";
        $headers .= "X-Mailer: ElvoControll/" . phpversion() . "\r\n";
        $headers .= "\r\n";

        // Bodkovanie na začiatku riadkov (SMTP dot-stuffing)
        $body = preg_replace('/^\./m', '..', $message_html);
        @fwrite($socket, $headers . $body . "\r\n.\r\n");
        $sent = $read($socket);
        $cmd($socket, 'QUIT');
        fclose($socket);

        if (strpos($sent, '250') === false) {
            error_log('[MAIL] SMTP server správu neprijal: ' . trim($sent));
            $GLOBALS['elvo_mail_last_error'] = 'SMTP server správu neprijal: ' . substr(trim($sent), 0, 150);
            return false;
        }
        return true;
    }
}

if (!function_exists('send_elvo_email')) {
    function send_elvo_email($to, $subject, $title, $content_html, $accent_color = '#007aff') {
        $domain = $_SERVER['SERVER_NAME'] ?? 'elvosolar.sk';
        if (substr($domain, 0, 4) === 'www.') {
            $domain = substr($domain, 4);
        }
        
        // WebSupport neodošle správu s neexistujúcim odosielateľom — preto musí byť
        // MAIL_FROM existujúca schránka na doméne (config.mail.php).
        // Odosielateľ MUSÍ byť existujúca schránka na doméne, inak WebSupport
        // správu ticho zahodí (mail() pritom vráti true). Preto nikdy
        // "no-reply@" — vždy reálna schránka.
        $from_email = MAIL_FROM !== '' ? MAIL_FROM : ("info@" . $domain);
        if (MAIL_FROM === '') {
            error_log('[MAIL] MAIL_FROM nie je vyplnené — používam ' . $from_email . ' (doplň config.mail.php, ak máš inú schránku).');
        }
        $GLOBALS['elvo_mail_last_error'] = '';
        $GLOBALS['elvo_mail_channel'] = '';

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
      <div style="font-size:11px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;color:#34d399;font-family:Consolas,'Courier New',monospace;">ElvoControll</div>
      <p style="margin:12px 0 5px 0;font-size:11px;color:#8494ab;line-height:1.7;">Automaticky generovaná správa z portálu ElvoControll.</p>
      <p style="margin:0;font-size:10px;color:#55637a;line-height:1.6;">&copy; 2011&ndash;%YEAR% Elvosolar s.r.o. &middot; Všetky práva vyhradené</p>
    </td>
  </tr>
</table>
<!--[if mso]></v:textbox></v:rect><![endif]-->
<div style="margin-top:14px;font-size:10px;color:#55637a;">Powered by <span style="color:#34d399;font-weight:700;">ElvoControll</span></div>
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

        // 1. SMTP WebSupportu — smtp.m1.websupport.sk (údaje schránky v config.mail.php)
        $smtp_ready = defined('USE_SMTP') && USE_SMTP === true
            && trim((string)SMTP_USER) !== '' && trim((string)SMTP_PASS) !== ''
            && trim((string)SMTP_HOST) !== '';
        if ($smtp_ready) {
            if (elvo_mail_smtp($to, $subject_encoded, $message_html, $from_email, $domain)) {
                $GLOBALS['elvo_mail_channel'] = 'smtp';
                return true;
            }
            error_log('[MAIL] SMTP WebSupport zlyhal, skúšam natívne mail() pre ' . $to);
        }

        // 2. NATIVE MAIL — poštový server WebSupportu (klasická funkcia mail() v PHP)
        $eol = "\n"; 
        $message_id = "<" . bin2hex(random_bytes(16)) . "@" . $domain . ">";
        
        $headers = "MIME-Version: 1.0" . $eol;
        $headers .= "Content-Type: text/html; charset=UTF-8" . $eol;
        $headers .= "From: ElvoSolar Control <" . $from_email . ">" . $eol;
        $headers .= "Reply-To: support@" . $domain . $eol;
        $headers .= "Message-ID: " . $message_id . $eol;
        $headers .= "X-Mailer: PHP/" . phpversion();

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
            if (empty($GLOBALS['elvo_mail_last_error'])) {
                $GLOBALS['elvo_mail_last_error'] = 'PHP mail() cez poštový server hostingu zlyhal — skontroluj logy a nastavenie schránky ' . $from_email . '.';
            }
        } else {
            $GLOBALS['elvo_mail_channel'] = 'mail';
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