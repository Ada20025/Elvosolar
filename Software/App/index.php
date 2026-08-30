<?php
// index.php

// Diagnostika chýb na serveri (Alwaysdata)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'config.php';

// ==========================================
// --- NASTAVENIE ODOSIELANIA E-MAILOV ---
// ==========================================
// Resend API (HTTPS, funguje na Railway)
define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');
define('RESEND_FROM', getenv('RESEND_FROM') ?: 'noreply@elvosolar.sk');
// ==========================================

$request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$base_path = ($script_dir === '/' || $script_dir === '.') ? '' : rtrim($script_dir, '/');

if (!empty($base_path) && strpos($request_uri, $base_path) === 0) {
    $path = substr($request_uri, strlen($base_path));
} else {
    $path = $request_uri;
}

// Normalizácia cesty: odstránenie prebytočných lomiek na konci a začiatku
$path = '/' . ltrim(rtrim($path, '/'), '/');

if (strpos($path, '/index.php') === 0) {
    $path = substr($path, 10);
    $path = '/' . ltrim(rtrim($path, '/'), '/');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- CENTRÁLNA FUNKCIA NA ODOSIELANIE GRAFICKÝCH HTML E-MAILOV ---

// Resend API helper
function resend_send_email($to, $subject, $html_body) {
    $api_key = RESEND_API_KEY;
    if (empty($api_key)) {
        error_log("[EMAIL] RESEND_API_KEY nie je nastaveny");
        return false;
    }
    
    error_log("[EMAIL] API key dlzka: " . strlen($api_key) . " znakov");
    error_log("[EMAIL] From: " . RESEND_FROM);
    error_log("[EMAIL] To: $to");
    
    // Resend API - skusime najprv s overenou domenou, potom fallback
    $from_addresses = [RESEND_FROM, 'onboarding@resend.dev'];
    
    foreach ($from_addresses as $from) {
        $payload = json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html_body,
        ]);
        
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("[EMAIL] Response ($http_code) from $from: $response");
        
        if ($http_code === 200 || $http_code === 201) {
            error_log("[EMAIL] ✅ Email odoslany na $to z $from");
            return true;
        }
        
        // Ak chyba 403 (domena neoverena), skusime dalsiu
        if ($http_code === 403) {
            error_log("[EMAIL] Domena $from neoverena, skusam fallback...");
            continue;
        }
        
        // Ina chyba - koncime
        error_log("[EMAIL] ❌ Chyba ($http_code): $response");
        return false;
    }
    
    error_log("[EMAIL] ❌ Vsetky from adresy zlyhali");
    return false;
}

if (!function_exists('send_elvo_email')) {
    function send_elvo_email($to, $subject, $title, $content_html, $accent_color = '#007aff') {
        global $base_path;

        $host = $_SERVER['HTTP_HOST'] ?? 'elvosolar.sk';
        $domain = $_SERVER['SERVER_NAME'] ?? 'elvosolar.sk';
        if (substr($domain, 0, 4) === 'www.') {
            $domain = substr($domain, 4);
        }
        
        $from_email = "no-reply@" . $domain;
        $logo_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . $host . $base_path . "/templates/ElvosolarLogo1.png";

        $message_html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . htmlspecialchars($subject) . '</title>
        </head>
        <body style="margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; -webkit-font-smoothing: antialiased;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; padding: 40px 16px;">
                <tr>
                    <td align="center">
                        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; border-top: 6px solid ' . $accent_color . ';">
                            <tr>
                                <td align="center" style="padding: 35px 40px 30px 40px; background-color: #ffffff; border-bottom: 1px solid #f1f5f9;">
                                    <img src="' . $logo_url . '" alt="ElvoSolar Logo" style="max-height: 40px; width: auto; display: block;" border="0">
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 40px 40px 35px 40px;">
                                    <h1 style="margin: 0 0 20px 0; font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; line-height: 1.3;">' . $title . '</h1>
                                    <div style="font-size: 15px; line-height: 1.62; color: #334155;">
                                        ' . $content_html . '
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 30px 40px; background-color: #f8fafc; border-top: 1px solid #f1f5f9; text-align: center;">
                                    <p style="margin: 0 0 8px 0; font-size: 12px; color: #64748b; line-height: 1.5;">
                                        Toto je automaticky generovaná správa zo systému ElvoSolar Control.
                                    </p>
                                    <p style="margin: 0; font-size: 11px; color: #94a3b8; line-height: 1.5;">
                                        Autorské práva &copy; 2011&ndash;2026 Elvosolar. Všetky práva vyhradené.
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

        $subject_clean = str_replace(["\r", "\n"], '', $subject);
        $subject_encoded = "=?UTF-8?B?" . base64_encode($subject_clean) . "?=";
        $from_name_encoded = "=?UTF-8?B?" . base64_encode("ElvoSolar Control") . "?=";

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
                    fclose($socket);
                    return false;
                }
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
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
            $headers .= "Content-Transfer-Encoding: 8bit\r\n";
            $headers .= "From: " . $from_name_encoded . " <" . $sender . ">\r\n";
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

            return (strpos($data_res, '250') !== false);
        }

        $eol = "\n"; 
        $message_id = "<" . bin2hex(random_bytes(16)) . "@" . $domain . ">";
        
        $headers = "MIME-Version: 1.0" . $eol;
        $headers .= "Content-Type: text/html; charset=UTF-8" . $eol;
        $headers .= "Content-Transfer-Encoding: 8bit" . $eol;
        $headers .= "From: " . $from_name_encoded . " <" . $from_email . ">" . $eol;
        $headers .= "Reply-To: support@" . $domain . $eol;
        $headers .= "Message-ID: " . $message_id . $eol;

        // PRIMARY: Resend API (HTTPS, works on Railway)
        $resend_result = resend_send_email($to, $subject, $message_html);
        if ($resend_result) return true;
        
        // FALLBACK: PHP mail()
        $result = @mail($to, $subject_encoded, $message_html, $headers, "-f " . $from_email);
        return $result;
    }
}

if (!function_exists('send_json')) {
    function send_json($data, $status = 200) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('get_json_input')) {
    function get_json_input() {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
}

// INTEGRIZOVANÝ SAMOLIEČIACI RENDERER ŠABLÓN (AUTOMATICKY PREVERÍ APPl/App PODPRIEČINKY)
if (!function_exists('render_template')) {
    function render_template($view_name, $context = []) {
        global $base_path;
        extract($context);
        
        $possible_paths = [
            __DIR__ . '/App/templates/' . $view_name,
            __DIR__ . '/app/templates/' . $view_name,
            __DIR__ . '/templates/' . $view_name,
        ];
        
        $view_path = null;
        foreach ($possible_paths as $p) {
            if (file_exists($p)) {
                $view_path = $p;
                break;
            }
        }
        
        if ($view_path) {
            include $view_path;
        } else {
            http_response_code(404);
            echo "<h3>Chyba: Šablóna <strong>" . htmlspecialchars($view_name) . "</strong> nebola nájdená v priečinku App/templates/.</h3>";
        }
        exit;
    }
}

if (!function_exists('flash')) {
    function flash($message, $category = 'info') {
        $_SESSION['flash'][] = ['message' => $message, 'category' => $category];
    }
}

if (!function_exists('get_flash_messages')) {
    function get_flash_messages() {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }
}

if (!function_exists('get_user_devices')) {
    function get_user_devices($pdo, $user_id) {
        $stmt = $pdo->prepare("SELECT * FROM devices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
}

// Spracovanie statických súborov (manifest, sw, css, js)
if (preg_match('#\.(json|js|css|woff2?|ttf|svg|ico|pdf|woff)$#i', $path)) {
    $clean_path = ltrim($path, '/');
    if (strpos($clean_path, 'templates/') === 0) {
        $clean_path = str_replace('templates/', '', $clean_path);
    }
    $possible_paths = [
        __DIR__ . '/' . ltrim($path, '/'),
        __DIR__ . '/App/templates/' . $clean_path,
        __DIR__ . '/app/templates/' . $clean_path,
        __DIR__ . '/templates/' . $clean_path,
    ];
    foreach ($possible_paths as $static_file) {
        if (file_exists($static_file)) {
            $mime_types = [
                'json' => 'application/json',
                'js' => 'application/javascript',
                'css' => 'text/css',
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
                'pdf' => 'application/pdf',
            ];
            $ext = strtolower(pathinfo($static_file, PATHINFO_EXTENSION));
            $mime = $mime_types[$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=3600');
            readfile($static_file);
            exit;
        }
    }
}

// Spracovanie statických súborov a obrázkov z templates adresára
if (preg_match('#\.(png|jpg|jpeg|gif)$#i', $path)) {
    $clean_path = ltrim($path, '/');
    if (strpos($clean_path, 'templates/') === 0) {
        $clean_path = str_replace('templates/', '', $clean_path);
    }
    
    $possible_img_paths = [
        __DIR__ . '/App/templates/' . $clean_path,
        __DIR__ . '/app/templates/' . $clean_path,
        __DIR__ . '/templates/' . $clean_path,
    ];
    
    foreach ($possible_img_paths as $img_path) {
        if (file_exists($img_path)) {
            $ext = strtolower(pathinfo($img_path, PATHINFO_EXTENSION));
            $mime_types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
            header("Content-Type: " . ($mime_types[$ext] ?? 'image/png'));
            readfile($img_path);
            exit;
        }
    }
}

// --- SLOVENSKÝ KALENDÁR ---
function get_slovak_day_info_php($date_str = null) {
    if (!$date_str) $date_str = date('Y-m-d');
    $ts = strtotime($date_str);
    $m = (int)date('n', $ts);
    $d = (int)date('j', $ts);
    $y = (int)date('Y', $ts);
    $weekday = (int)date('N', $ts);
    
    $fixed_holidays = [
        '1-1' => 'Nový rok', '1-6' => 'Traja králi', '5-1' => 'Sviatok práce', '5-8' => 'Deň víťazstva',
        '7-5' => 'sv. Cyril a Metod', '8-29' => 'SNP', '9-1' => 'Deň Ústavy', '9-15' => 'Sedembolestná Panna Mária',
        '11-1' => 'Všetkých svätých', '11-17' => 'Deň boja za slobodu', '12-24' => 'Štedrý deň', '12-25' => '1. sviatok vianočný', '12-26' => '2. sviatok vianočný'
    ];
    
    $key = "$m-$d";
    $is_holiday = isset($fixed_holidays[$key]);
    $holiday_name = $is_holiday ? $fixed_holidays[$key] : '';
    
    $day_type = 'WORKDAY';
    $type_label_sk = 'Pracovný deň';
    if ($is_holiday) {
        $day_type = 'HOLIDAY';
        $type_label_sk = "Sviatok ($holiday_name)";
    } elseif ($weekday >= 6) {
        $day_type = 'WEEKEND';
        $type_label_sk = 'Víkend';
    }
    
    return [
        'date' => $date_str, 'day_type' => $day_type, 'type_label_sk' => $type_label_sk,
        'is_holiday' => $is_holiday, 'holiday_name' => $holiday_name, 'weekday_name' => $weekday
    ];
}

function get_device_ai_cache_file($device_id) {
    return __DIR__ . '/cache_ai_device_' . intval($device_id) . '.json';
}

function get_device_ai_state_php($device_id) {
    $file = get_device_ai_cache_file($device_id);
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (is_array($data)) return $data;
    }
    
    return [
        'learning_stage' => 'INITIAL_LEARNING',
        'confidence_percent' => 35.0,
        'days_learned' => 1,
        'total_samples' => 120,
        'holiday_mode' => [
            'enabled' => false,
            'until' => '',
            'preheat_hours' => 6,
            'target_temp' => 22.0,
            'target_boiler' => 50.0
        ],
        'profiles' => [
            'WORKDAY' => [350, 320, 300, 310, 420, 650, 1400, 1850, 1200, 650, 550, 500, 580, 520, 580, 850, 1350, 2100, 2650, 2400, 1950, 1450, 850, 450],
            'WEEKEND' => [400, 360, 340, 330, 350, 420, 680, 1100, 1650, 2100, 2300, 2450, 2200, 1600, 1400, 1350, 1600, 2150, 2700, 2550, 2100, 1650, 950, 550],
            'HOLIDAY' => [420, 380, 350, 340, 360, 450, 720, 1250, 1800, 2250, 2500, 2600, 2350, 1750, 1500, 1450, 1700, 2250, 2800, 2650, 2200, 1750, 1050, 600]
        ],
        'rules_config' => [
            'negative_price_protect' => true, 'negative_price_threshold' => 0.0, 'negative_price_charge_grid' => true,
            'precharge_enabled' => true, 'precharge_target_soc' => 80.0, 'precharge_price_ratio' => 0.75,
            'self_consumption_priority' => true, 'peak_export_enabled' => true, 'peak_price_ratio' => 1.35, 'peak_export_min_soc' => 70.0,
            'thermal_protection_enabled' => true, 'max_temp_limit' => 65.0, 'battery_capacity_kwh' => 10.0, 'battery_min_soc' => 15.0,
            'battery_max_soc' => 95.0, 'pv_installed_kwp' => 5.0
        ],
        'third_party_devices' => []
    ];
}

function save_device_ai_state_php($device_id, $data) {
    $file = get_device_ai_cache_file($device_id);
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --- SMEROVANIE (ROUTING) ---
if ($path === '/' || $path === '') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $base_path . "/login");
        exit;
    }
    $devices = get_user_devices($pdo, $_SESSION['user_id']);
    
    // Check if admin
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_row = $stmt->fetch();
    $is_admin = ($user_row && ($user_row['role'] ?? '') === 'admin');
    
    if ($is_admin) {
        // Admin: show ALL devices from DB
        $all_devices = $pdo->query("SELECT d.*, u.username FROM devices d LEFT JOIN users u ON d.user_id = u.id ORDER BY d.id DESC")->fetchAll();
        render_template('admin.html', ['devices' => $all_devices, 'all_devices' => $all_devices]);
    } elseif (count($devices) === 0) {
        render_template('no_devices.html');
    } else {
        header("Location: " . $base_path . "/dashboard");
        exit;
    }
}

elseif ($path === '/login') {
    if ($method === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: " . $base_path . "/");
            exit;
        } else {
            flash('Nesprávne prihlasovacie údaje.', 'error');
        }
    }
    render_template('prihlasenie.html', ['flash' => get_flash_messages()]);
}

elseif ($path === '/register') {
    if ($method === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashed]);
            
            // Welcome email
            @send_elvo_email($email, "Vitajte v ElvoControl!", "Vitajte v ElvoControl, " . htmlspecialchars($username) . "!",
                "<p>Váš účet bol úspešne vytvorený.</p>"
                . "<p><strong>Prihlasovací e-mail:</strong> " . htmlspecialchars($email) . "</p>"
                . "<p><a href='" . $base_path . "/login' style='display:inline-block;padding:12px 24px;background:#3b82f6;color:white;border-radius:8px;text-decoration:none;font-weight:bold;'>Prihlásiť sa</a></p>"
                . "<p style='color:#64748b;font-size:12px;margin-top:16px;'>Pre prístup k monitoringu fotovoltiky pripojte vašu riadiacu jednotku CM5.</p>"
            );
            flash('Účet vytvorený. Teraz sa môžete prihlásiť.', 'success');
            header("Location: " . $base_path . "/login");
            exit;
        } catch (PDOException $e) {
            flash('Meno alebo e-mail už existuje.', 'error');
        }
    }
    render_template('registracia.html', ['flash' => get_flash_messages()]);
}

elseif ($path === '/logout' && $method === 'GET') {
    session_destroy();
    header("Location: " . $base_path . "/login");
    exit;
}

// ZLÚČENÝ ROBUSTNÝ UKAZOVATEĽ PRE SETUP.HTML (Bypassuje chybné Alwaysdata presmerovania)
elseif ($path === '/setup' || $path === '/setup.html') {
    render_template('setup.html');
}

elseif ($path === '/dashboard' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $base_path . "/login");
        exit;
    }
    $devices = get_user_devices($pdo, $_SESSION['user_id']);
    render_template('dashboard.html', ['username' => $_SESSION['username'], 'devices' => $devices]);
}

// --- USER PROFILE ---
elseif ($path === '/profile' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $base_path . "/login");
        exit;
    }
    render_template('profile.html');
}

// --- USER ME (API) ---
elseif ($path === '/api/user/me' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    }
    $stmt = $pdo->prepare("SELECT id, username, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) send_json(['status' => 'error', 'message' => 'Pouzivatel nenajdeny'], 404);
    send_json(['status' => 'success', 'user_id' => $user['id'], 'username' => $user['username'], 'email' => $user['email'], 'created_at' => $user['created_at'] ?? '2026', 'email_verified' => true]);
}

// --- USER DEVICES (API) ---
elseif ($path === '/api/user/devices' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    }
    $stmt = $pdo->prepare("SELECT d.*, u.username FROM devices d LEFT JOIN users u ON d.user_id = u.id WHERE d.user_id = ? ORDER BY d.id DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $devices = $stmt->fetchAll();
    $result = [];
    foreach ($devices as $dev) {
        $last_seen = strtotime($dev['last_seen'] ?? '2000-01-01');
        $result[] = [
            'id' => $dev['id'], 'name' => $dev['name'] ?? '', 'serial_number' => $dev['serial_number'] ?? '',
            'brand_id' => $dev['brand_id'] ?? '', 'model_id' => $dev['model_id'] ?? '',
            'total_kwh' => (float)($dev['total_kwh'] ?? 0), 'total_saved_eur' => (float)($dev['total_saved_eur'] ?? 0),
            'is_online' => (time() - $last_seen) < 90,
        ];
    }
    send_json(['status' => 'success', 'devices' => $result]);
}

// --- DEVICE SETTINGS (min/max hodnoty, mody) ---
elseif ($path === '/api/user/device-settings' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    
    $stmt = $pdo->prepare("SELECT id, name, brand_id FROM devices WHERE user_id = ? ORDER BY id");
    $stmt->execute([$_SESSION['user_id']]);
    $devices = $stmt->fetchAll();
    
    $result = [];
    foreach ($devices as $dev) {
        $settings_file = __DIR__ . '/cache_device_settings_' . $dev['id'] . '.json';
        $settings = [
            'min_power_w' => 0,
            'max_power_w' => 10000,
            'min_soc_pct' => 10,
            'max_soc_pct' => 90,
            'auto_mode' => 'SMART',
            'night_sleep' => false,
            'target_temp' => 22.0,
            'group_id' => 0,
        ];
        if (file_exists($settings_file)) {
            $cached = json_decode(file_get_contents($settings_file), true);
            if ($cached) $settings = array_merge($settings, $cached);
        }
        $result[] = [
            'device_id' => $dev['id'],
            'name' => $dev['name'],
            'brand_id' => $dev['brand_id'],
            'settings' => $settings,
        ];
    }
    send_json(['status' => 'success', 'devices' => $result]);
}

// --- RENAME DEVICE ---
elseif (preg_match('#^/api/device/(\d+)/rename$#', $path, $matches) && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    $device_id = $matches[1];
    $data = get_json_input();
    $new_name = trim($data['name'] ?? '');
    if (!$new_name) send_json(['status' => 'error', 'message' => 'Nazov je povinny'], 400);
    
    // Over ze patri userovi
    $stmt = $pdo->prepare("SELECT id FROM devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$device_id, $_SESSION['user_id']]);
    if (!$stmt->fetch()) send_json(['status' => 'error', 'message' => 'Zariadenie nenajdene'], 404);
    
    $pdo->prepare("UPDATE devices SET name = ? WHERE id = ?")->execute([$new_name, $device_id]);
    
    // Posli na CM5
    $stmt = $pdo->prepare("SELECT serial_number FROM devices WHERE id = ?");
    $stmt->execute([$device_id]);
    $dev = $stmt->fetch();
    if ($dev) {
        $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'pending')")
            ->execute([$dev['serial_number'], json_encode(['action' => 'set_name', 'name' => $new_name])]);
    }
    
    send_json(['status' => 'success', 'name' => $new_name]);
}

// --- SAVE DEVICE SETTINGS (jedno alebo vsetky) ---
elseif ($path === '/api/user/device-settings' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    $data = get_json_input();
    
    $device_ids = $data['device_ids'] ?? []; // pole ID alebo 'all'
    $settings = $data['settings'] ?? [];
    
    if (empty($device_ids) || empty($settings)) {
        send_json(['status' => 'error', 'message' => 'Chybaju device_ids alebo settings'], 400);
    }
    
    // Ak 'all', nastav pre vsetky
    if ($device_ids === 'all' || (is_array($device_ids) && in_array('all', $device_ids))) {
        $stmt = $pdo->prepare("SELECT id FROM devices WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $device_ids = array_column($stmt->fetchAll(), 'id');
    }
    
    $saved = 0;
    foreach ($device_ids as $did) {
        $did = intval($did);
        // Over ze to patri userovi
        $stmt = $pdo->prepare("SELECT id FROM devices WHERE id = ? AND user_id = ?");
        $stmt->execute([$did, $_SESSION['user_id']]);
        if (!$stmt->fetch()) continue;
        
        $settings_file = __DIR__ . '/cache_device_settings_' . $did . '.json';
        $existing = [];
        if (file_exists($settings_file)) {
            $existing = json_decode(file_get_contents($settings_file), true) ?? [];
        }
        $merged = array_merge($existing, $settings);
        file_put_contents($settings_file, json_encode($merged, JSON_PRETTY_PRINT));
        
        // Posli prikaz na CM5
        $stmt = $pdo->prepare("SELECT serial_number FROM devices WHERE id = ?");
        $stmt->execute([$did]);
        $dev = $stmt->fetch();
        if ($dev) {
            $config = array_merge(['action' => 'update_settings'], $settings);
            $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'pending')")
                ->execute([$dev['serial_number'], json_encode($config)]);
        }
        $saved++;
    }
    
    send_json(['status' => 'success', 'saved' => $saved, 'message' => "Nastavenia ulozene pre $saved zariadeni"]);
}

// --- DEVICE GROUPS ---
elseif ($path === '/api/user/device-groups' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    $groups_file = __DIR__ . '/cache_groups_' . $_SESSION['user_id'] . '.json';
    $groups = [];
    if (file_exists($groups_file)) {
        $groups = json_decode(file_get_contents($groups_file), true) ?? [];
    }
    send_json(['status' => 'success', 'groups' => $groups]);
}

elseif ($path === '/api/user/device-groups' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    $data = get_json_input();
    $groups_file = __DIR__ . '/cache_groups_' . $_SESSION['user_id'] . '.json';
    $groups = file_exists($groups_file) ? (json_decode(file_get_contents($groups_file), true) ?? []) : [];
    
    $action = $data['action'] ?? 'save';
    if ($action === 'create') {
        $groups[] = [
            'id' => count($groups) + 1,
            'name' => $data['name'] ?? 'Skupina',
            'device_ids' => $data['device_ids'] ?? [],
        ];
    } elseif ($action === 'delete') {
        $gid = intval($data['group_id'] ?? 0);
        $groups = array_filter($groups, fn($g) => $g['id'] !== $gid);
        $groups = array_values($groups);
    } elseif ($action === 'save') {
        $gid = intval($data['group_id'] ?? 0);
        foreach ($groups as &$g) {
            if ($g['id'] === $gid) {
                $g['name'] = $data['name'] ?? $g['name'];
                $g['device_ids'] = $data['device_ids'] ?? $g['device_ids'];
                break;
            }
        }
    }
    file_put_contents($groups_file, json_encode($groups, JSON_PRETTY_PRINT));
    send_json(['status' => 'success', 'groups' => $groups]);
}

// --- CHANGE PASSWORD ---
elseif ($path === '/api/user/change-password' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    $data = get_json_input();
    $current = $data['current_password'] ?? '';
    $new_pass = $data['new_password'] ?? '';
    if (strlen($new_pass) < 6) send_json(['status' => 'error', 'message' => 'Nove heslo musi mat aspon 6 znakov']);
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($current, $user['password_hash'])) {
        send_json(['status' => 'error', 'message' => 'Nespravne aktualne heslo']);
    }
    $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hashed, $_SESSION['user_id']]);
    send_json(['status' => 'success', 'message' => 'Heslo bolo zmenene']);
}

// --- TEST EMAIL (Resend API) ---
elseif ($path === '/api/user/test-email' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    $stmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) send_json(['status' => 'error', 'message' => 'Pouzivatel nenajdeny']);
    $result = @send_elvo_email($user['email'], 'Test emailu ElvoControl', 'Test emailu', '<p>Ahoj <strong>' . htmlspecialchars($user['username']) . '</strong>,</p><p>Tento email bol uspesne odoslany z ElvoControl cez Resend API.</p><p style="color:#64748b;font-size:12px;">Ak toto citate, vsetko funguje!</p>', '#10b981');
    if ($result) {
        send_json(['status' => 'success', 'message' => 'Test email uspesne odoslany na ' . $user['email']]);
    } else {
        send_json(['status' => 'error', 'message' => 'Email sa nepodarilo odoslat. Skontrolujte Resend API key.']);
    }
}

// --- VERIFY EMAIL ---
elseif ($path === '/api/user/verify-email' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    send_json(['status' => 'success', 'message' => 'Email je platny. Overenie nie je potrebne pre testovanie.']);
}

// --- SAVE NOTIFICATION SETTINGS ---
elseif ($path === '/api/user/notifications' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny'], 401);
    $data = get_json_input();
    $file = __DIR__ . '/cache_user_notif_' . $_SESSION['user_id'] . '.json';
    @file_put_contents($file, json_encode($data));
    send_json(['status' => 'success', 'message' => 'Nastavenia notifikacii ulozene']);
}

elseif (preg_match('#^/device/([0-9]+)$#', $path, $matches) && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $base_path . "/login");
        exit;
    }
    $device_id = $matches[1];
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$device_id, $_SESSION['user_id']]);
    $device = $stmt->fetch();
    
    if (!$device) {
        header("Location: " . $base_path . "/");
        exit;
    }
    // Zisti ci je user admin
    $is_admin = false;
    if (isset($_SESSION['user_id'])) {
        $stmt_admin = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt_admin->execute([$_SESSION['user_id']]);
        $admin_row = $stmt_admin->fetch();
        $is_admin = ($admin_row && ($admin_row['role'] ?? '') === 'admin');
    }
    render_template('device_detail.html', ['device' => $device, 'is_admin' => $is_admin]);
}

// --- DOVOLENKOVÉ CLOUD API ENDPOINTY ---
elseif (preg_match('#^/api/device/([0-9]+)/holiday-mode$#', $path, $matches)) {
    if (!isset($_SESSION['user_id'])) {
        send_json(['error' => 'Unauthorized'], 401);
    }
    $device_id = $matches[1];
    $ai_state = get_device_ai_state_php($device_id);

    if ($method === 'GET') {
        $holiday = $ai_state['holiday_mode'] ?? [
            'enabled' => false, 'until' => '', 'preheat_hours' => 6, 'target_temp' => 22.0, 'target_boiler' => 50.0
        ];
        send_json(['status' => 'success', 'holiday_mode' => $holiday]);
    } 
    elseif ($method === 'POST') {
        $data = get_json_input();
        $ai_state['holiday_mode'] = [
            'enabled' => !empty($data['enabled']),
            'until' => trim($data['until'] ?? ''),
            'preheat_hours' => intval($data['preheat_hours'] ?? 6),
            'target_temp' => floatval($data['target_temp'] ?? 22.0),
            'target_boiler' => floatval($data['target_boiler'] ?? 50.0)
        ];
        save_device_ai_state_php($device_id, $ai_state);
        send_json(['status' => 'success', 'message' => 'Dovolenkový režim uložený.', 'holiday_mode' => $ai_state['holiday_mode']]);
    }
}

// --- SMART METER: ŽIVÉ DÁTA ---
elseif (preg_match('#^/api/device/([0-9]+)/meter$#', $path, $matches)) {
    if (!isset($_SESSION['user_id'])) send_json(['error' => 'Unauthorized'], 401);
    $device_id = $matches[1];
    
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$device_id, $_SESSION['user_id']]);
    $device = $stmt->fetch();
    if (!$device) send_json(['error' => 'Device not found'], 404);

    if ($method === 'GET') {
        // Načítanie živých meter dát z cache (naplnené cez cloud sync)
        $meter_cache_file = __DIR__ . '/cache_meter_' . $device_id . '.json';
        $meter_data = [
            'house_consumption_w' => 0.0,
            'house_consumption_kwh_today' => 0.0,
            'grid_import_w' => 0.0,
            'grid_export_w' => 0.0,
            'fve_surplus_w' => 0.0,
            'control_mode' => 'SMART',
            'meter_mode' => 'NONE',
            'avg_consumption_w' => 0.0,
            'peak_consumption_w' => 0.0,
            'min_consumption_w' => 0.0,
            'history' => []
        ];
        
        if (file_exists($meter_cache_file)) {
            $cached = json_decode(file_get_contents($meter_cache_file), true);
            if ($cached) $meter_data = array_merge($meter_data, $cached);
        }
        
        // Načítanie histórie spotreby (posledných 48 hodinových záznamov)
        $stmt = $pdo->prepare("SELECT timestamp, power_ac, battery_soc, temp FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 48");
        $stmt->execute([$device_id]);
        $history = [];
        foreach (array_reverse($stmt->fetchAll()) as $row) {
            $history[] = [
                'timestamp' => (int)$row['timestamp'],
                'power_ac' => (float)$row['power_ac'],
                'battery_soc' => (float)$row['battery_soc'],
                'temp' => (float)$row['temp']
            ];
        }
        $meter_data['history'] = $history;
        
        send_json(['status' => 'success', 'meter' => $meter_data]);
    }
    elseif ($method === 'POST') {
        // Nastavenie režimu riadenia (UNLIMITED, SELF_CONSUMPTION, SMART)
        $data = get_json_input();
        $new_mode = strtoupper(trim($data['control_mode'] ?? ''));
        
        $allowed = ['UNLIMITED', 'SELF_CONSUMPTION', 'SMART', 'ZERO', 'PLUS', 'CUSTOM'];
        if (!in_array($new_mode, $allowed)) {
            send_json(['error' => 'Neplatný režim. Povolené: ' . implode(', ', $allowed)], 400);
        }
        
        $ai_state = get_device_ai_state_php($device_id);
        if (!isset($ai_state['smart_meter'])) $ai_state['smart_meter'] = [];
        $ai_state['smart_meter']['control_mode'] = $new_mode;
        save_device_ai_state_php($device_id, $ai_state);
        
        send_json(['status' => 'success', 'control_mode' => $new_mode, 'message' => 'Režim riadenia nastavený na ' . $new_mode]);
    }
}

// --- SMART METER: KONFIGURÁCIA ---
elseif (preg_match('#^/api/device/([0-9]+)/meter/config$#', $path, $matches)) {
    $device_id = $matches[1];

    if ($method === 'GET') {
        $ai_state = get_device_ai_state_php($device_id);
        $meter_config = $ai_state['smart_meter_config'] ?? [];
        $meter_config = array_merge([
            'meter_mode' => 'NONE',
            'meter_slave_id' => 1,
            'meter_baudrate' => 9600,
            'meter_parity' => 'N',
            'reg_import_wh' => '',
            'reg_export_wh' => '',
            'reg_import_w' => '',
            'reg_export_w' => '',
            'reg_consumption_w' => '',
            's0_impulses_per_kwh' => 1000,
            'cloud_api_url' => '',
            // CUSTOM režim nastavenia
            'target_consumption_w' => 0,
            'surplus_action' => 'CHARGE_BATTERY',
            'zero_action' => 'PRODUCE_HOUSE',
            'grid_export_limit_w' => 0,
            'battery_priority' => 'SMART',
        ], $meter_config);
        send_json(['status' => 'success', 'config' => $meter_config]);
    }
    elseif ($method === 'POST') {
        $data = get_json_input();
        $ai_state = get_device_ai_state_php($device_id);
        $ai_state['smart_meter_config'] = [
            'meter_mode' => trim($data['meter_mode'] ?? 'NONE'),
            'meter_slave_id' => intval($data['meter_slave_id'] ?? 1),
            'meter_baudrate' => intval($data['meter_baudrate'] ?? 9600),
            'meter_parity' => trim($data['meter_parity'] ?? 'N'),
            'reg_import_wh' => trim($data['reg_import_wh'] ?? ''),
            'reg_export_wh' => trim($data['reg_export_wh'] ?? ''),
            'reg_import_w' => trim($data['reg_import_w'] ?? ''),
            'reg_export_w' => trim($data['reg_export_w'] ?? ''),
            'reg_consumption_w' => trim($data['reg_consumption_w'] ?? ''),
            's0_impulses_per_kwh' => intval($data['s0_impulses_per_kwh'] ?? 1000),
            'cloud_api_url' => trim($data['cloud_api_url'] ?? ''),
            // CUSTOM režim nastavenia
            'target_consumption_w' => floatval($data['target_consumption_w'] ?? 0),
            'surplus_action' => trim($data['surplus_action'] ?? 'CHARGE_BATTERY'),
            'zero_action' => trim($data['zero_action'] ?? 'PRODUCE_HOUSE'),
            'grid_export_limit_w' => floatval($data['grid_export_limit_w'] ?? 0),
            'battery_priority' => trim($data['battery_priority'] ?? 'SMART'),
        ];
        save_device_ai_state_php($device_id, $ai_state);
        send_json(['status' => 'success', 'message' => 'Konfigurácia smart meradla uložená.']);
    }
}

// --- TELEMETRIA SYNC ---
elseif ($path === '/api/cloud/sync-telemetry' && $method === 'POST') {
    $data = get_json_input();
    $serial_number = $data['serial_number'] ?? '';
    $power_ac = (float)($data['power_ac'] ?? 0.0);
    $battery_soc = (float)($data['battery_soc'] ?? 0.0);
    $temp = (float)($data['temp'] ?? 25.0);
    $freq = (float)($data['freq'] ?? 50.0);
    $status_msg = $data['status_msg'] ?? '';

    $stmt = $pdo->prepare("SELECT id, total_saved_eur, total_kwh, manual_override, active_model_id, night_sleep FROM devices WHERE serial_number = ?");
    $stmt->execute([$serial_number]);
    $device = $stmt->fetch();
    
    if (!$device) {
        send_json(['status' => 'error', 'message' => 'Neregistrované zariadenie'], 404);
    }
    
    $device_id = $device['id'];
    $new_saved = (float)$device['total_saved_eur'] + ($power_ac * 0.0000001);
    $new_kwh = (float)$device['total_kwh'] + ($power_ac * 0.000001);
    $timestamp = date('Y-m-d H:i:s');

    $okte_price = 85.0; 
    $cacheFile = __DIR__ . '/cache_okte_' . date('Y-m-d') . '.json';
    if (file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if ($cacheData && isset($cacheData['prices']) && is_array($cacheData['prices'])) {
            $index = (int)date('H');
            if (isset($cacheData['prices'][$index])) {
                $okte_price = (float)$cacheData['prices'][$index];
            }
        }
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE devices SET total_saved_eur = ?, total_kwh = ?, last_seen = ? WHERE id = ?");
        $stmt->execute([$new_saved, $new_kwh, $timestamp, $device_id]);
        $stmt = $pdo->prepare("INSERT INTO telemetry (device_id, power_ac, battery_soc, temp, freq, status_msg) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$device_id, $power_ac, $battery_soc, $temp, $freq, $status_msg]);
        $pdo->commit();
        
        $ai_state = get_device_ai_state_php($device_id);
        if (isset($data['ai_info']) && is_array($data['ai_info'])) {
            $ai_state = array_merge($ai_state, $data['ai_info']);
            save_device_ai_state_php($device_id, $ai_state);
        }

        // Uloženie smart meter dát do cache
        if (isset($data['house_consumption_w']) || isset($data['meter_control_mode'])) {
            $meter_cache = [
                'house_consumption_w' => floatval($data['house_consumption_w'] ?? 0),
                'grid_import_w' => floatval($data['grid_import_w'] ?? 0),
                'grid_export_w' => floatval($data['grid_export_w'] ?? 0),
                'control_mode' => $data['meter_control_mode'] ?? 'SMART',
                'meter_mode' => $data['meter_mode'] ?? 'NONE',
                'fve_surplus_w' => max(0, $power_ac - floatval($data['house_consumption_w'] ?? 0)),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            @file_put_contents(__DIR__ . '/cache_meter_' . $device_id . '.json', json_encode($meter_cache));
        }

        send_json([
            'status' => 'success',
            'timestamp' => $timestamp,
            'control' => [
                'manual_override' => $device['manual_override'] ?? 'AUTO',
                'active_model_id' => $device['active_model_id'] ?? 'AI',
                'night_sleep'     => (int)($device['night_sleep'] ?? 1),
                'live_okte_price' => $okte_price,
                'holiday_mode'    => $ai_state['holiday_mode'] ?? null,
                'rules_config'    => $ai_state['rules_config'] ?? null,
                'custom_rules'    => $ai_state['custom_rules'] ?? null,
                'meter_control_mode' => ($ai_state['smart_meter']['control_mode'] ?? 'SMART')
            ]
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        send_json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

elseif (preg_match('#^/api/device/([0-9]+)/telemetry$#', $path, $matches) && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) send_json(['error' => 'Unauthorized'], 401);
    $device_id = $matches[1];
    
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$device_id, $_SESSION['user_id']]);
    $device = $stmt->fetch();
    
    if (!$device) send_json(['error' => 'Device not found'], 404);
    
    $stmt = $pdo->prepare("SELECT timestamp, power_ac FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 48");
    $stmt->execute([$device_id]);
    $rows = $stmt->fetchAll();
    
    $history = [];
    foreach (array_reverse($rows) as $row) {
        $history[] = ['timestamp' => $row['timestamp'], 'power_ac' => (float)$row['power_ac']];
    }
    
    $stmt = $pdo->prepare("SELECT temp, freq, battery_soc, status_msg FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$device_id]);
    $latest = $stmt->fetch();

    $ai_state = get_device_ai_state_php($device_id);

    send_json([
        'total_saved_eur' => (float)$device['total_saved_eur'],
        'total_kwh' => (float)$device['total_kwh'],
        'has_battery' => ($device['has_battery'] ?? 'true') === 'true',
        'avg_live_soc' => $latest ? (float)$latest['battery_soc'] : 0.0,
        'night_sleep' => (int)$device['night_sleep'],
        'total_live_power' => count($history) > 0 ? $history[count($history)-1]['power_ac'] : 0.0,
        'history' => $history,
        'active_model' => $device['active_model_id'] ?? 'AI',
        'manual_override' => $device['manual_override'] ?? 'AUTO',
        'temp' => $latest ? (float)$latest['temp'] : 25.0,
        'freq' => $latest ? (float)$latest['freq'] : 50.0,
        'ai_info' => $ai_state
    ]);
}

elseif ($path === '/api/devices/list' && $method === 'GET') {
    // Kazda znacka moze mat viacero kategorii (striedace + smartlogger)
    send_json([
        '1' => ['znacka' => 'HUAWEI', 'zapojenie' => 'Modul CH1: R/A(+) a T/B(-).', 'kategorie' => [
            '1' => ['meno' => 'Striedače SUN2000', 'typ' => 'striedac', 'popis' => 'Jednofázové aj trojfázové', 'modely' => [
                '1' => ['meno' => 'Všetky modely SUN2000']]],
            '2' => ['meno' => 'SmartLogger', 'typ' => 'smartlogger', 'popis' => 'Monitorovací zariadenie', 'modely' => [
                '1' => ['meno' => 'SmartLogger 3000A / 1000']]]]],
        '2' => ['znacka' => 'FRONIUS', 'zapojenie' => 'D+ na A(+), D- na B(-).', 'kategorie' => [
            '1' => ['meno' => 'Rezidenčné (Galvo/Symo/Primo)', 'typ' => 'striedac', 'popis' => 'Jedno- aj trojfázové', 'modely' => [
                '1' => ['meno' => 'Všetky Fronius modely']]]]],
        '3' => ['znacka' => 'GOODWE', 'zapojenie' => 'Pin A na A(+), Pin B na B(-).', 'kategorie' => [
            '1' => ['meno' => 'Striedače (XS/DNS/EH/ET)', 'typ' => 'striedac', 'popis' => 'Domáce aj komerčné', 'modely' => [
                '1' => ['meno' => 'Všetky GoodWe modely']]],
            '2' => ['meno' => 'EzLogger', 'typ' => 'smartlogger', 'popis' => 'Monitorovací zariadenie', 'modely' => [
                '1' => ['meno' => 'EzLogger Pro / 3000C']]]]],
        '4' => ['znacka' => 'SOLAX', 'zapojenie' => 'RJ45 pin 4 na A(+), pin 5 na B(-).', 'kategorie' => [
            '1' => ['meno' => 'Jedno- aj trojfázové (X1/X3)', 'typ' => 'striedac', 'popis' => 'Sieťové aj hybridné', 'modely' => [
                '1' => ['meno' => 'Všetky SolaX modely']]]]],
        '5' => ['znacka' => 'VICTRON', 'zapojenie' => 'USB-RS485: Oranžový=A(+), Žlty=B(-).', 'kategorie' => [
            '1' => ['meno' => 'MultiPlus / Quattro', 'typ' => 'striedac', 'popis' => 'Menič/Nabíjač', 'modely' => [
                '1' => ['meno' => 'Všetky Victron modely']]]]],
        '6' => ['znacka' => 'GROWATT', 'zapojenie' => 'SYS COM: pin 3=A(+), pin 4=B(-).', 'kategorie' => [
            '1' => ['meno' => 'MIN-XE / MOD / SPH', 'typ' => 'striedac', 'popis' => 'Sieťové aj hybridné', 'modely' => [
                '1' => ['meno' => 'Všetky Growatt modely']]]]],
        '7' => ['znacka' => 'SOFAR', 'zapojenie' => 'Pin 1=A(+), Pin 2=B(-).', 'kategorie' => [
            '1' => ['meno' => 'Trojfázové hybridy HYD', 'typ' => 'striedac', 'popis' => 'Séria HYD', 'modely' => [
                '1' => ['meno' => 'Všetky Sofar modely']]]]],
        '8' => ['znacka' => 'DEYE', 'zapojenie' => 'RS485: pin 7=A(+), pin 8=B(-).', 'kategorie' => [
            '1' => ['meno' => 'Nízkonapäťové hybridy', 'typ' => 'striedac', 'popis' => 'Séria SG04LP3', 'modely' => [
                '1' => ['meno' => 'Všetky Deye modely']]]]],
        '9' => ['znacka' => 'SUNGROW', 'zapojenie' => 'A2 na A(+), B2 na B(-).', 'kategorie' => [
            '1' => ['meno' => 'SH-RT / SG-RT', 'typ' => 'striedac', 'popis' => 'Hybridné aj sieťové', 'modely' => [
                '1' => ['meno' => 'Všetky Sungrow modely']]]]],
    ]);
}

// --- REGISTRÁCIA ZARIADENIA ---
elseif ($path === '/api/devices/register' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial_number'] ?? '');
    $name = trim($data['name'] ?? 'Moje zariadenie');
    $brand = trim($data['brand'] ?? 'Huawei');
    $model = trim($data['model'] ?? 'SUN2000');
    
    // User ID - bud zo session alebo z requestu
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id && isset($data['user_id'])) {
        $user_id = intval($data['user_id']);
    }
    if (!$user_id && isset($data['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        $row = $stmt->fetch();
        if ($row) $user_id = $row['id'];
    }
    
    if (!$serial) {
        send_json(['status' => 'error', 'message' => 'Sériové číslo je povinné'], 400);
    }
    
    // Skontroluj či už neexistuje
    $stmt = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ?");
    $stmt->execute([$serial]);
    if ($stmt->fetch()) {
        send_json(['status' => 'error', 'message' => 'Zariadenie s týmto sériovým číslom už existuje'], 409);
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO devices (user_id, name, serial_number, brand_id, model_id, last_seen) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $name, $serial, $brand, $model]);
        $device_id = $pdo->lastInsertId();
        
        // Email notifikácia o pridaní zariadenia
        $user_email = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
        $user_email->execute([$user_id]);
        $u = $user_email->fetch();
        if ($u) {
            @send_elvo_email($u['email'], 'Nové zariadenie v ElvoControl', 'Zariadenie bolo pridané',
                '<p>Ahoj <strong>' . htmlspecialchars($u['username']) . '</strong>,</p>'
                . '<p>Pridali ste nové zariadenie do ElvoControl:</p>'
                . '<div style="background:#f1f5f9;padding:16px;border-radius:12px;margin:16px 0;font-family:monospace;font-size:13px;">'
                . '<p>📦 Názov: <strong>' . htmlspecialchars($name) . '</strong></p>'
                . '<p>🔢 Sériové číslo: <strong>' . htmlspecialchars($serial) . '</strong></p>'
                . '<p>🏭 Značka: <strong>' . htmlspecialchars($brand) . ' ' . htmlspecialchars($model) . '</strong></p>'
                . '</div>'
                . '<p style="color:#64748b;font-size:12px;">Zariadenie sa prihlási automaticky keď bude online.</p>'
            );
        }
        
        send_json(['status' => 'success', 'device_id' => $device_id, 'message' => 'Zariadenie úspešne zaregistrované']);
    } catch (PDOException $e) {
        send_json(['status' => 'error', 'message' => 'Chyba pri registrácii zariadenia'], 500);
    }
}

elseif ($path === '/forgot-password' && $method === 'GET') {
    $_SESSION['reset_step'] = 1;
    render_template('forgot-password.html');
}

elseif ($path === '/forgot-password' && $method === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $_SESSION['flash'][] = ['category' => 'error', 'message' => 'Zadajte e-mailovú adresu.'];
        header('Location: ' . $base_path . '/forgot-password');
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $_SESSION['flash'][] = ['category' => 'error', 'message' => 'Účet s touto e-mailovou adresou nebol nájdený.'];
        header('Location: ' . $base_path . '/forgot-password');
        exit;
    }
    
    // Vytvor 6-miestny kód
    $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    
    // Ulož do DB (vymaž staré kody pre tento email)
    $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);
    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, code, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$user['id'], $code, $expires]);
    
    // Posli email (s fallback ak nefunguje mail())
    $subject = "ElvoControl - Obnovenie hesla";
    $title = "Váš overovací kód";
    $html = "<p>Ahoj <strong>" . htmlspecialchars($user['username']) . "</strong>,</p>"
         . "<p>Váš 6-miestny overovací kód pre obnovenie hesla:</p>"
         . "<div style='text-align:center;margin:24px 0;'><span style='font-size:32px;font-weight:900;letter-spacing:8px;color:#3b82f6;font-family:monospace;background:#f1f5f9;padding:16px 32px;border-radius:12px;'>" . $code . "</span></div>"
         . "<p style='color:#64748b;font-size:12px;'>Kód platí 15 minút. Ak ste o obnovenie hesla nepožiadali, tento e-mail ignorujte.</p>";
    
    // Vždy zobraz kód (email je bonus, nefunguje na Railway)
    $_SESSION['reset_code'] = $code;
    $_SESSION['flash'][] = ['category' => 'success', 'message' => 'Váš overovací kód: <strong style="font-size:18px;color:#3b82f6;letter-spacing:4px;">' . $code . '</strong><br><small style="color:#64748b;">Zadajte ho vo formulári nižšie.</small>'];
    
    // Pokus o email (bonus)
    @send_elvo_email($email, $subject, $title, $html);
    
    $_SESSION['reset_email'] = $email;
    $_SESSION['reset_user_id'] = $user['id'];
    $_SESSION['reset_step'] = 2;
    $_SESSION['flash'][] = ['category' => 'success', 'message' => 'Overovací kód odoslaný na ' . $email];
    header('Location: ' . $base_path . '/forgot-password');
    exit;
}

elseif ($path === '/verify-reset-code' && $method === 'POST') {
    $code = trim($_POST['verification_code'] ?? '');
    $new_pass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $user_id = $_SESSION['reset_user_id'] ?? null;
    
    if (!$user_id || !$code || !$new_pass) {
        $_SESSION['flash'][] = ['category' => 'error', 'message' => 'Vyplňte všetky polia.'];
        header('Location: ' . $base_path . '/forgot-password');
        exit;
    }
    
    if ($new_pass !== $confirm) {
        $_SESSION['flash'][] = ['category' => 'error', 'message' => 'Heslá sa nezhodujú.'];
        header('Location: ' . $base_path . '/forgot-password');
        exit;
    }
    
    if (strlen($new_pass) < 6) {
        $_SESSION['flash'][] = ['category' => 'error', 'message' => 'Heslo musí mať aspoň 6 znakov.'];
        header('Location: ' . $base_path . '/forgot-password');
        exit;
    }
    
    // Over kód - z session alebo z DB
    $session_code = $_SESSION['reset_code'] ?? '';
    $stmt = $pdo->prepare("SELECT id FROM password_resets WHERE user_id = ? AND code = ? AND expires_at > NOW()");
    $stmt->execute([$user_id, $code]);
    $reset = $stmt->fetch();
    
    // Fallback: ak DB nefunguje, over cez session
    if (!$reset && $session_code && $session_code === $code) {
        $reset = ['id' => 0]; // dummy
    }
    
    if (!$reset) {
        $_SESSION['flash'][] = ['category' => 'error', 'message' => 'Neplatný alebo expirovaný kód.'];
        header('Location: ' . $base_path . '/forgot-password');
        exit;
    }
    
    // Aktualizuj heslo
    $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hashed, $user_id]);
    
    // Vymaž kód
    $pdo->prepare("DELETE FROM password_resets WHERE id = ?")->execute([$reset['id']]);
    
    // Vymaž session
    unset($_SESSION['reset_email'], $_SESSION['reset_user_id'], $_SESSION['reset_step']);
    
    $_SESSION['flash'][] = ['category' => 'success', 'message' => 'Heslo bolo úspešne zmenené! Môžete sa prihlásiť.'];
    header('Location: ' . $base_path . '/login');
    exit;
}

elseif ($path === '/debug-resend' && $method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== RESEND DEBUG ===
";
    echo "API KEY: " . (RESEND_API_KEY ? 'SET (' . strlen(RESEND_API_KEY) . ' chars)' : 'NOT SET') . "
";
    echo "FROM: " . RESEND_FROM . "
";
    
    // Test curl
    if (RESEND_API_KEY) {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['from' => 'onboarding@resend.dev', 'to' => ['test@test.com'], 'subject' => 'Test', 'html' => '<p>Test</p>']),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "TEST RESPONSE ($http_code): $response
";
    }
    exit;
}

elseif ($path === '/debug-env' && $method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== ENV DEBUG ===
";
    echo "MYSQLHOST: " . ($_ENV['MYSQLHOST'] ?? getenv('MYSQLHOST') ?: 'NOT SET') . "
";
    echo "MYSQLPORT: " . ($_ENV['MYSQLPORT'] ?? getenv('MYSQLPORT') ?: 'NOT SET') . "
";
    echo "MYSQLUSER: " . ($_ENV['MYSQLUSER'] ?? getenv('MYSQLUSER') ?: 'NOT SET') . "
";
    echo "MYSQLPASSWORD: " . (isset($_ENV['MYSQLPASSWORD']) || getenv('MYSQLPASSWORD') ? 'SET (hidden)' : 'NOT SET') . "
";
    echo "MYSQLDATABASE: " . ($_ENV['MYSQLDATABASE'] ?? getenv('MYSQLDATABASE') ?: 'NOT SET') . "
";
    echo "MYSQL_DATABASE: " . ($_ENV['MYSQL_DATABASE'] ?? getenv('MYSQL_DATABASE') ?: 'NOT SET') . "
";
    echo "MYSQL_ROOT_PASSWORD: " . (isset($_ENV['MYSQL_ROOT_PASSWORD']) || getenv('MYSQL_ROOT_PASSWORD') ? 'SET (hidden)' : 'NOT SET') . "
";
    echo "
=== \$_ENV dump ===
";
    foreach ($_ENV as $k => $v) {
        if (strpos($k, 'MYSQL') === 0) {
            echo "$k = " . (strpos($k, 'PASSWORD') !== false ? '***' : $v) . "
";
        }
    }
    echo "
=== getenv dump ===
";
    foreach (['MYSQLHOST','MYSQLPORT','MYSQLUSER','MYSQLPASSWORD','MYSQLDATABASE'] as $k) {
        $v = @getenv($k);
        echo "$k = " . ($v !== false ? (strpos($k, 'PASSWORD') !== false ? '***' : $v) : 'NOT SET') . "
";
    }
    exit;
}

elseif ($path === '/setup_database' && $method === 'GET') {
    // Database setup - spustiť len raz!
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    require __DIR__ . '/setup_database.php';
    exit;
}

elseif ($path === '/setup_database.php' && $method === 'GET') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    require __DIR__ . '/setup_database.php';
    exit;
}

// === SMTP DEBUG ===
elseif ($path === '/debug-smtp' && $method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== SMTP TEST ===
";
    echo "Host: " . SMTP_HOST . "
";
    echo "Port: " . SMTP_PORT . "
";
    echo "User: " . SMTP_USER . "
";
    echo "Pass: " . str_repeat('*', strlen(SMTP_PASS)) . " (" . strlen(SMTP_PASS) . " chars)
";
    echo "Encryption: " . SMTP_ENCRYPTION . "

";
    
    $socket_host = (strtolower(SMTP_ENCRYPTION) === 'ssl') ? 'ssl://' . SMTP_HOST : SMTP_HOST;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    
    $start = microtime(true);
    $socket = @stream_socket_client($socket_host . ':' . SMTP_PORT, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
    $ms = round((microtime(true) - $start) * 1000);
    
    if (!$socket) { echo "CONNECTION FAILED: $errstr ($errno) in {$ms}ms
"; exit; }
    echo "Connected in {$ms}ms
";
    
    $rd = function($s) { $r=''; while(($l=fgets($s,512))!==false) { $r.=$l; if(substr($l,3,1)==' ') break; } return $r; };
    $rd($socket);
    fwrite($socket, "EHLO elvosolar.sk

");
    echo "EHLO: " . $rd($socket) . "
";
    
    if (strtolower(SMTP_ENCRYPTION) === 'tls') {
        fwrite($socket, "STARTTLS

");
        $tls = $rd($socket);
        echo "STARTTLS: $tls
";
        if (strpos($tls, '220') !== false) {
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fwrite($socket, "EHLO elvosolar.sk

");
            echo "EHLO (TLS): " . $rd($socket) . "
";
        }
    }
    
    fwrite($socket, "AUTH LOGIN

");
    echo "AUTH: " . $rd($socket) . "
";
    fwrite($socket, base64_encode(SMTP_USER) . "

");
    echo "USER: " . $rd($socket) . "
";
    fwrite($socket, base64_encode(SMTP_PASS) . "

");
    $auth = $rd($socket);
    echo "PASS: $auth
";
    
    if (strpos($auth, '235') !== false) {
        echo "
SMTP AUTH OK - Sending test email...
";
        $sender = SMTP_USER;
        fwrite($socket, "MAIL FROM: <$sender>

"); $rd($socket);
        fwrite($socket, "RCPT TO: <$sender>

"); $rd($socket);
        fwrite($socket, "DATA

"); $rd($socket);
        $msg = "Subject: =?UTF-8?B?" . base64_encode("ElvoControl SMTP Test") . "?=

";
        $msg .= "From: $sender

";
        $msg .= "Content-Type: text/plain; charset=utf-8



";
        $msg .= "SMTP funguje z Railway!

.

";
        fwrite($socket, $msg);
        echo "DATA: " . $rd($socket) . "
";
        echo "
SMTP TEST PASSED!
";
    } else {
        echo "
SMTP AUTH FAILED
";
    }
    fwrite($socket, "QUIT

");
    fclose($socket);
}

// =============================================================================
// CM5 PROXY ENDPOINTY - Setup wizard posiela config na CM5 cez cloud
// =============================================================================

// --- CM5: Nahlásenie IP adresy ---
elseif ($path === '/api/report-ip' && $method === 'POST') {
    $data = get_json_input();
    $ip = trim($data['ip'] ?? '');
    $serial = trim($data['serial'] ?? '');
    if ($ip && $serial) {
        $stmt = $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'online') ON DUPLICATE KEY UPDATE updated_at = NOW()");
        $stmt->execute([$serial, json_encode(['ip' => $ip])]);
    }
    send_json(['status' => 'success']);
}

// --- CM5: Stiahni pending config ---
elseif ($path === '/api/cm5/poll' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial'] ?? '');
    if (!$serial) {
        send_json(['status' => 'error', 'message' => 'Chýba serial_number'], 400);
    }
    // Najprv hladaj prikaz pre tento specific serial, potom CM5-DEFAULT (fallback)
    $stmt = $pdo->prepare("SELECT id, config_json FROM cm5_config WHERE serial_number = ? AND status = 'pending' ORDER BY created_at ASC LIMIT 1");
    $stmt->execute([$serial]);
    $row = $stmt->fetch();
    if (!$row) {
        $stmt = $pdo->prepare("SELECT id, config_json FROM cm5_config WHERE serial_number = 'CM5-DEFAULT' AND status = 'pending' ORDER BY created_at ASC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
    }
    if ($row) {
        $stmt2 = $pdo->prepare("UPDATE cm5_config SET status = 'applied' WHERE id = ?");
        $stmt2->execute([$row['id']]);
        send_json(['status' => 'success', 'config' => json_decode($row['config_json'], true), 'command_id' => $row['id']]);
    } else {
        send_json(['status' => 'no_pending']);
    }
}

// --- CM5: Odosli vysledok scanu ---
elseif ($path === '/api/cm5/result' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial'] ?? '');
    $command_id = intval($data['command_id'] ?? 0);
    $result = $data['result'] ?? [];
    if ($command_id) {
        $stmt = $pdo->prepare("UPDATE cm5_config SET result_json = ?, status = 'applied' WHERE id = ?");
        $stmt->execute([json_encode($result), $command_id]);
    }
    send_json(['status' => 'success']);
}

// --- Frontend: Čakaj na výsledok z CM5 (NON-BLOCKING) ---
elseif ($path === '/api/cm5/wait-result' && $method === 'GET') {
    $serial = $_GET['serial'] ?? '';
    $cmd_id = intval($_GET['command_id'] ?? 0);
    if (!$serial && !$cmd_id) {
        send_json(['status' => 'error', 'message' => 'Chýba serial alebo command_id'], 400);
    }
    // Jednorazovy check - ziadne blokovanie
    if ($cmd_id) {
        $stmt = $pdo->prepare("SELECT result_json, status FROM cm5_config WHERE id = ?");
        $stmt->execute([$cmd_id]);
        $row = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare("SELECT result_json, status FROM cm5_config WHERE serial_number = ? AND result_json IS NOT NULL ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$serial]);
        $row = $stmt->fetch();
    }
    if ($row && $row['result_json']) {
        send_json(['status' => 'success', 'result' => json_decode($row['result_json'], true)]);
    } else {
        send_json(['status' => 'pending']);
    }
}

// --- ADMIN DEVICE DETAIL ---
elseif (preg_match('#^/admin/device/(\d+)$#', $path, $matches) && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) { header("Location: " . $base_path . "/login"); exit; }
    render_template('admin-device.html', ['device_id' => $matches[1]]);
}

// --- ADMIN: UPDATE DEVICE ---
elseif (preg_match('#^/api/admin/device/(\d+)$#', $path, $matches) && $method === 'POST') {
    $device_id = $matches[1];
    $data = get_json_input();
    $name = trim($data['name'] ?? '');
    $mode = trim($data['operation_mode'] ?? 'AUTO');
    $sleep = intval($data['night_sleep'] ?? 0);
    
    if ($name) {
        $pdo->prepare("UPDATE devices SET name = ? WHERE id = ?")->execute([$name, $device_id]);
    }
    
    // Send settings to CM5
    $stmt = $pdo->prepare("SELECT serial_number FROM devices WHERE id = ?");
    $stmt->execute([$device_id]);
    $dev = $stmt->fetch();
    if ($dev) {
        $config = ['action' => 'set_mode', 'mode' => $mode, 'night_sleep' => $sleep];
        $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'pending')")->execute([$dev['serial_number'], json_encode($config)]);
    }
    
    send_json(['status' => 'success', 'message' => 'Nastavenia uložené']);
}

// --- ADMIN: DELETE DEVICE ---
elseif (preg_match('#^/api/admin/device/(\d+)$#', $path, $matches) && $method === 'DELETE') {
    $device_id = $matches[1];
    $pdo->prepare("DELETE FROM devices WHERE id = ?")->execute([$device_id]);
    $pdo->prepare("DELETE FROM telemetry WHERE device_id = ?")->execute([$device_id]);
    send_json(['status' => 'success', 'message' => 'Zariadenie odstránené']);
}

// --- ADMIN: DEVICE LOGS ---
elseif (preg_match('#^/api/admin/device/(\d+)/logs$#', $path, $matches) && $method === 'GET') {
    $device_id = $matches[1];
    $stmt = $pdo->prepare("SELECT timestamp, power_ac, battery_soc, temp, freq, status_msg FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 50");
    $stmt->execute([$device_id]);
    send_json(['status' => 'success', 'logs' => $stmt->fetchAll()]);
}

// --- PUSH NOTIFICATION SUBSCRIBE ---
elseif ($path === '/api/user/push-subscribe' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) send_json(['status' => 'error', 'message' => 'Not logged in'], 401);
    $data = get_json_input();
    $endpoint = $data['endpoint'] ?? '';
    $keys = $data['keys'] ?? [];
    if ($endpoint) {
        $file = __DIR__ . '/cache_push_subscriptions.json';
        $subs = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $subs[] = ['user_id' => $_SESSION['user_id'], 'endpoint' => $endpoint, 'keys' => $keys, 'created' => date('c')];
        file_put_contents($file, json_encode($subs, JSON_PRETTY_PRINT));
    }
    send_json(['status' => 'success', 'message' => 'Push subscription uložená']);
}

// --- SN AUTO-REGISTRATION ---
elseif ($path === '/api/cm5/register' && $method === 'POST') {
    $data = get_json_input();
    $serial = trim($data['serial_number'] ?? '');
    $brand = trim($data['brand'] ?? '');
    $model = trim($data['model'] ?? '');
    $slave_id = intval($data['slave_id'] ?? 1);
    $user_id = intval($data['user_id'] ?? 0);
    
    if (!$serial) send_json(['status' => 'error', 'message' => 'Chýba serial_number'], 400);
    
    // Check if already exists
    $stmt = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ?");
    $stmt->execute([$serial]);
    if ($stmt->fetch()) {
        // Update last_seen
        $pdo->prepare("UPDATE devices SET last_seen = NOW() WHERE serial_number = ?")->execute([$serial]);
        send_json(['status' => 'success', 'message' => 'Zariadenie aktualizované', 'action' => 'updated']);
        return;
    }
    
    // Auto-register with user_id if provided
    if (!$user_id) {
        // Find first admin user
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $admin = $stmt->fetch();
        $user_id = $admin ? $admin['id'] : 1;
    }
    
    $name = ($brand ?: 'Zariadenie') . ' (' . $serial . ')';
    $pdo->prepare("INSERT INTO devices (user_id, name, serial_number, brand_id, model_id, slave_id, last_seen) VALUES (?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$user_id, $name, $serial, $brand, $model, $slave_id]);
    
    $device_id = $pdo->lastInsertId();
    
    // Send push notification to user
    $stmt = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if ($user) {
        @send_elvo_email($user['email'], 'Nové zariadenie pripojené', 'Nové zariadenie',
            '<p>Ahoj <strong>' . htmlspecialchars($user['username']) . '</strong>,</p>'
            . '<p>Pripojilo sa nové zariadenie:</p>'
            . '<div style="background:#f1f5f9;padding:16px;border-radius:12px;margin:16px 0;font-family:monospace;font-size:13px;">'
            . '<p>📦 Názov: <strong>' . htmlspecialchars($name) . '</strong></p>'
            . '<p>🔢 SN: <strong>' . htmlspecialchars($serial) . '</strong></p>'
            . '<p>🏭 Značka: <strong>' . htmlspecialchars($brand) . '</strong></p>'
            . '</div>'
            . '<p style="color:#64748b;font-size:12px;">Zariadenie bolo automaticky zaregistrované.</p>'
        );
    }
    
    send_json(['status' => 'success', 'device_id' => $device_id, 'message' => 'Zariadenie zaregistrované', 'action' => 'registered']);
}

// --- ADMIN PANEL DATA ---
elseif ($path === '/api/admin/devices' && $method === 'GET') {
    // Vrati vsetky zariadenia s live datami pre admin panel
    $stmt = $pdo->query("SELECT d.*, u.username FROM devices d LEFT JOIN users u ON d.user_id = u.id ORDER BY d.id DESC");
    $devices = $stmt->fetchAll();
    
    $result = [];
    foreach ($devices as $dev) {
        $device_id = $dev['id'];
        
        // Telemetry
        $stmt = $pdo->prepare("SELECT power_ac, battery_soc, temp, freq, status_msg, timestamp FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$device_id]);
        $telemetry = $stmt->fetch();
        
        // AI state
        $ai_state = get_device_ai_state_php($device_id);
        
        // Last seen
        $last_seen = strtotime($dev['last_seen'] ?? '2000-01-01');
        $now = time();
        $is_online = ($now - $last_seen) < 90;
        
        $result[] = [
            'id' => $dev['id'],
            'name' => $dev['name'] ?? 'Zariadenie',
            'serial_number' => $dev['serial_number'] ?? '',
            'brand_id' => $dev['brand_id'] ?? '',
            'model_id' => $dev['model_id'] ?? '',
            'slave_id' => $dev['slave_id'] ?? 0,
            'user_id' => $dev['user_id'] ?? 0,
            'username' => $dev['username'] ?? '',
            'total_saved_eur' => (float)($dev['total_saved_eur'] ?? 0),
            'total_kwh' => (float)($dev['total_kwh'] ?? 0),
            'last_seen' => $last_seen,
            'is_online' => $is_online,
            'power_ac' => $telemetry ? (float)$telemetry['power_ac'] : 0,
            'battery_soc' => $telemetry ? (float)$telemetry['battery_soc'] : 0,
            'temp' => $telemetry ? (float)$telemetry['temp'] : 0,
            'freq' => $telemetry ? (float)$telemetry['freq'] : 0,
            'status_msg' => $telemetry ? $telemetry['status_msg'] : '',
            'telemetry_time' => $telemetry ? $telemetry['timestamp'] : '',
        ];
    }
    
    send_json(['status' => 'success', 'devices' => $result]);
}

// --- ADMIN: SEND COMMAND TO DEVICE ---
elseif ($path === '/api/admin/command' && $method === 'POST') {
    $data = get_json_input();
    $device_id = intval($data['device_id'] ?? 0);
    $action = trim($data['action'] ?? '');
    $params = $data['params'] ?? [];
    
    if (!$device_id || !$action) {
        send_json(['status' => 'error', 'message' => 'Chýba device_id alebo action'], 400);
    }
    
    // Zisti serial number zariadenia
    $stmt = $pdo->prepare("SELECT serial_number FROM devices WHERE id = ?");
    $stmt->execute([$device_id]);
    $dev = $stmt->fetch();
    if (!$dev) send_json(['status' => 'error', 'message' => 'Zariadenie nenájdené'], 404);
    
    $serial = $dev['serial_number'];
    $config = array_merge(['action' => $action], $params);
    
    // Uloz prikaz do cm5_config
    $stmt = $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$serial, json_encode($config)]);
    $cmd_id = $pdo->lastInsertId();
    
    send_json(['status' => 'success', 'command_id' => $cmd_id, 'message' => "Prikaz '$action' odoslany na $serial"]);
}

// --- ADMIN: DEVICE LOGS ---
elseif (preg_match('#^/api/admin/device/(\d+)/logs$#', $path, $matches) && $method === 'GET') {
    $device_id = $matches[1];
    $stmt = $pdo->prepare("SELECT serial_number FROM devices WHERE id = ?");
    $stmt->execute([$device_id]);
    $dev = $stmt->fetch();
    if (!$dev) send_json(['status' => 'error', 'message' => 'Zariadenie nenájdené'], 404);
    
    // Vrati telemetry history
    $stmt = $pdo->prepare("SELECT timestamp, power_ac, battery_soc, temp, freq, status_msg FROM telemetry WHERE device_id = ? ORDER BY id DESC LIMIT 50");
    $stmt->execute([$device_id]);
    $rows = $stmt->fetchAll();
    
    send_json(['status' => 'success', 'logs' => $rows]);
}

// --- ADMIN CLAIM (cloud verzia) ---
elseif ($path === '/api/admin/claim' && $method === 'POST') {
    $data = get_json_input();
    $serial = 'CM5-DEFAULT';
    $config = [
        'action' => 'claim',
        'admin_username' => $data['admin_username'] ?? 'admin',
        'admin_password' => $data['admin_password'] ?? '',
        'comm_mode' => $data['comm_mode'] ?? 'LOCAL_MODBUS',
        'cloud_username' => $data['cloud_username'] ?? '',
        'cloud_password' => $data['cloud_password'] ?? '',
    ];
    $stmt = $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$serial, json_encode($config)]);
    send_json(['status' => 'success', 'message' => 'Config uložený, CM5 ho stiahne pri ďalšom pripojení.']);
}

// --- USER CLAIM DEVICE (cloud verzia) ---
elseif ($path === '/api/user/claim-device' && $method === 'POST') {
    $data = get_json_input();
    $serial = 'CM5-DEFAULT';
    $config = [
        'action' => 'claim_device',
        'brand_id' => $data['brand_id'] ?? '',
        'category_id' => $data['category_id'] ?? '',
        'model_id' => $data['model_id'] ?? '',
        'slave_id' => intval($data['slave_id'] ?? 1),
        'has_battery' => boolval($data['has_battery'] ?? true),
        'name' => $data['name'] ?? '',
        'device_name' => $data['device_name'] ?? '',
        'smart_meter' => $data['smart_meter'] ?? [],
    ];
    $stmt = $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$serial, json_encode($config)]);
    
    // Uloz zariadenie do DB aj s user_id
    if (isset($_SESSION['user_id'])) {
        $brand = $data['brand_id'] ?? '';
        $model = $data['model_id'] ?? '';
        $name = $data['device_name'] ?? $data['name'] ?? 'ElvoControll';
        $stmt2 = $pdo->prepare("INSERT INTO devices (user_id, name, serial_number, brand_id, model_id, last_seen) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt2->execute([$_SESSION['user_id'], $name, $serial, $brand, $model]);
    }
    
    send_json(['status' => 'success']);
}

// --- HEALTHCHECK (pre Railway - NIKDY NEBLOKUJE) ---
elseif ($path === '/healthcheck' || $path === '/health') {
    send_json(['status' => 'ok', 'time' => date('c')]);
}

// --- SYSTEM DISCOVER (cloud verzia - NON-BLOCKING) ---
elseif ($path === '/api/system/discover' && $method === 'GET') {
    $brand = $_GET['brand'] ?? '';
    $category = $_GET['category'] ?? '';
    $model = $_GET['model'] ?? '';
    $serial = 'CM5-DEFAULT';
    
    // Skus najst skutocne CM5 serial z DB
    try {
        $stmt_cm5 = $pdo->prepare("SELECT serial_number FROM devices WHERE serial_number LIKE 'SN-CM5-%' ORDER BY last_seen DESC LIMIT 1");
        $stmt_cm5->execute();
        $cm5_row = $stmt_cm5->fetch();
        if ($cm5_row) {
            $serial = $cm5_row['serial_number'];
            error_log("[DISCOVER] Pouzivam CM5 serial: $serial");
        }
    } catch (Exception $e) {}
    
    // Uloz prikaz na scan
    $config = [
        'action' => 'discover',
        'brand_id' => $brand,
        'category_id' => $category,
        'model_id' => $model,
    ];
    $stmt = $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$serial, json_encode($config)]);
    $cmd_id = $pdo->lastInsertId();
    
    error_log("[DISCOVER] Prikaz ulozeny (cmd_id=$cmd_id, serial=$serial) - vraciam okamzite");
    
    // OKAMZITE vrat cmd_id - frontend bude polluj cez /api/cm5/poll-result
    send_json(['status' => 'queued', 'command_id' => $cmd_id, 'message' => 'Discover prikaz odoslany na CM5. Cakajte na vysledok.', 'serial' => $serial]);
}

// --- FRONTEND: Poll pre vysledok discover (NON-BLOCKING) ---
elseif ($path === '/api/cm5/poll-result' && $method === 'GET') {
    $cmd_id = intval($_GET['command_id'] ?? 0);
    if (!$cmd_id) {
        send_json(['status' => 'error', 'message' => 'Chybne command_id'], 400);
    }
    $stmt = $pdo->prepare("SELECT result_json, status FROM cm5_config WHERE id = ?");
    $stmt->execute([$cmd_id]);
    $row = $stmt->fetch();
    if ($row && $row['result_json']) {
        $result = json_decode($row['result_json'], true);
        error_log("[DISCOVER] Vysledok pre cmd_id=$cmd_id: " . json_encode($result));
        send_json($result);
    } else {
        send_json(['status' => 'pending', 'command_id' => $cmd_id]);
    }
}

// --- SYSTEM STATUS (cloud verzia) ---
elseif ($path === '/api/system/status' && $method === 'GET') {
    // Vrat serial number ak je dostupny
    $serial = 'CM5-DEFAULT';
    try {
        $stmt = $pdo->query("SELECT serial_number FROM devices WHERE serial_number LIKE 'SN-CM5-%' ORDER BY last_seen DESC LIMIT 1");
        $row = $stmt->fetch();
        if ($row) $serial = $row['serial_number'];
    } catch (Exception $e) {}
    
    send_json([
        'status' => 'success',
        'is_claimed' => isset($_SESSION['user_id']),
        'internet' => true,
        'modbus' => 'CLOUD_MODE',
        'serial_number' => $serial,
        'serial' => $serial
    ]);
}

// --- AKTUALNY UZIVATEL ---
elseif ($path === '/api/user/me' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        send_json(['status' => 'error', 'message' => 'Nie ste prihlaseny']);
    }
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        send_json(['status' => 'success', 'user_id' => $user['id'], 'username' => $user['username'], 'email' => $user['email']]);
    } else {
        send_json(['status' => 'error', 'message' => 'Pouzivatel nenajdeny']);
    }
}

// --- WIFI CONNECT (cloud verzia - ulozi pre CM5) ---
elseif ($path === '/api/system/wifi/connect' && $method === 'POST') {
    $data = get_json_input();
    $serial = 'CM5-DEFAULT';
    $config = [
        'action' => 'wifi_connect',
        'ssid' => $data['ssid'] ?? '',
        'password' => $data['password'] ?? '',
    ];
    $stmt = $pdo->prepare("INSERT INTO cm5_config (serial_number, config_json, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$serial, json_encode($config)]);
    send_json(['status' => 'success']);
}

// --- 404 HANDLER ---
else {
    http_response_code(404);
    echo "Stránka nebola nájdaná.";
}
