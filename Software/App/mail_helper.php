<?php
// mail_helper.php

// ==========================================
// --- NASTAVENIE ODOSIELANIA E-MAILOV ---
// ==========================================
if (!defined('USE_SMTP')) {
    define('USE_SMTP', false);                  // Ak nefunguje klasický mail, prepíšte na: true
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.alwaysdata.com'); // SMTP server (napr. smtp.alwaysdata.com alebo smtp.gmail.com)
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);                   // Port (najčastejšie 587 pre TLS, 465 pre SSL)
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', 'no-reply@elvosolar.sk');  // Prihlasovacie meno (váš e-mail)
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', 'vase_heslo');          // Heslo k e-mailu
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'tls');           // Šifrovanie: 'tls', 'ssl' alebo 'none'
}
// ==========================================

if (!function_exists('send_elvo_email')) {
    function send_elvo_email($to, $subject, $title, $content_html, $accent_color = '#007aff') {
        $domain = $_SERVER['SERVER_NAME'] ?? 'elvosolar.sk';
        if (substr($domain, 0, 4) === 'www.') {
            $domain = substr($domain, 4);
        }
        
        $from_email = "no-reply@" . $domain;

        // Moderná a čistá HTML šablóna e-mailu s vylepšeným dizajnom
        $message_html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . htmlspecialchars($subject) . '</title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: system-ui, -apple-system, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f1f5f9; padding: 40px 16px;">
                <tr>
                    <td align="center">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 540px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(15, 23, 42, 0.05); border: 1px solid #e2e8f0;">
                            <!-- Horný akcentový prúžok -->
                            <tr>
                                <td style="background-color: ' . $accent_color . '; height: 6px; line-height: 6px; font-size: 6px;">&nbsp;</td>
                            </tr>
                            <!-- Hlavička s logom -->
                            <tr>
                                <td align="center" style="padding: 32px 40px 24px 40px; background-color: #ffffff; border-bottom: 1px solid #f1f5f9;">
                                    <img src="https://adamdz.alwaysdata.net/templates/ElvosolarLogo1.png" alt="ElvoSolar Logo" style="max-height: 38px; width: auto; display: block;" border="0">
                                </td>
                            </tr>
                            <!-- Hlavný obsah -->
                            <tr>
                                <td style="padding: 40px 40px 36px 40px;">
                                    <h1 style="margin: 0 0 18px 0; font-size: 20px; font-weight: 700; color: #0f172a; letter-spacing: -0.025em; line-height: 1.3;">' . $title . '</h1>
                                    <div style="font-size: 14px; line-height: 1.65; color: #334155;">
                                        ' . $content_html . '
                                    </div>
                                </td>
                            </tr>
                            <!-- Pätička správy -->
                            <tr>
                                <td style="padding: 28px 40px; background-color: #f8fafc; border-top: 1px solid #f1f5f9; text-align: center;">
                                    <p style="margin: 0 0 6px 0; font-size: 11px; color: #94a3b8; line-height: 1.5;">
                                        Toto je automaticky generovaná správa z portálu ElvoSolar Control.
                                    </p>
                                    <p style="margin: 0; font-size: 11px; color: #94a3b8; line-height: 1.5;">
                                        &copy; 2011&ndash;2026 Elvosolar. Všetky práva vyhradené.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ';

        // Zakódovanie predmetu správy do formátu RFC Base64 pre bezchybnú diakritiku a antispam
        $subject_encoded = "=?UTF-8?B?" . base64_encode($subject) . "?=";

        // 1. REŽIM SMTP (Priame socket spojenie s mailovým serverom)
        if (defined('USE_SMTP') && USE_SMTP === true) {
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

            $socket = @stream_socket_client($socket_host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
            
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
            fwrite($socket, "MAIL FROM: <" . $sender . ">\r\n");
            $read_response($socket);
            fwrite($socket, "RCPT TO: <" . $to . ">\r\n");
            $read_response($socket);
            fwrite($socket, "DATA\r\n");
            $read_response($socket);

            $message_id = "<" . bin2hex(random_bytes(16)) . "@" . $domain . ">";

            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: ElvoSolar Control <" . $sender . ">\r\n";
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
            if (!$success) {
                error_log("SMTP Server odmietol odoslať správu: " . $data_res);
            }
            return $success;
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