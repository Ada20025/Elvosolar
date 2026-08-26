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
define('USE_SMTP', true);
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp-adamdz.alwaysdata.net');
define('SMTP_PORT', intval(getenv('SMTP_PORT') ?: '587'));
define('SMTP_USER', getenv('SMTP_USER') ?: 'adamdz@alwaysdata.net');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '1Adamko.');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls');
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
    render_template('device_detail.html', ['device' => $device]);
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
        
        if (!in_array($new_mode, ['UNLIMITED', 'SELF_CONSUMPTION', 'SMART'])) {
            send_json(['error' => 'Neplatný režim. Povolené: UNLIMITED, SELF_CONSUMPTION, SMART'], 400);
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
    if (!isset($_SESSION['user_id'])) send_json(['error' => 'Unauthorized'], 401);
    $device_id = $matches[1];
    
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$device_id, $_SESSION['user_id']]);
    $device = $stmt->fetch();
    if (!$device) send_json(['error' => 'Device not found'], 404);

    if ($method === 'GET') {
        $ai_state = get_device_ai_state_php($device_id);
        $meter_config = $ai_state['smart_meter_config'] ?? [
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
        ];
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
    send_json([
        "huawei" => ["znacka" => "Huawei", "zapojenie" => "Modul CH1: R/A(+) a T/B(-).", "kategorie" => ["sun2000" => ["meno" => "SUN2000", "modely" => ["1" => ["meno" => "Jednofázové aj Trojfázové SUN2000"]]]]],
        "solax" => ["znacka" => "SolaX", "zapojenie" => "Modrá na A(+), Modrobiela na B(-).", "kategorie" => ["g4" => ["meno" => "X3-Hybrid G4", "modely" => ["1" => ["meno" => "Všetky modely G4"]]]]]
    ]);
}

// --- REGISTRÁCIA ZARIADENIA ---
elseif ($path === '/api/devices/register' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        send_json(['status' => 'error', 'message' => 'Nie ste prihlásený'], 401);
    }
    $data = get_json_input();
    $serial = trim($data['serial_number'] ?? '');
    $name = trim($data['name'] ?? 'Moje zariadenie');
    $brand = trim($data['brand'] ?? 'Huawei');
    $model = trim($data['model'] ?? 'SUN2000');
    
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
        $stmt->execute([$_SESSION['user_id'], $name, $serial, $brand, $model]);
        $device_id = $pdo->lastInsertId();
        
        // Email notifikácia o pridaní zariadenia
        $user_email = $pdo->prepare("SELECT email, username FROM users WHERE id = ?");
        $user_email->execute([$_SESSION['user_id']]);
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

else {

// === SMTP TEST ===
elseif ($path === '/debug-smtp' && $method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== SMTP TEST ===
";
    
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $enc = SMTP_ENCRYPTION;
    
    echo "Host: $host
";
    echo "Port: $port
";
    echo "User: $user
";
    echo "Pass: " . (strlen($pass) > 0 ? str_repeat('*', strlen($pass)) . " ({$len} chars)" : "EMPTY") . "
";
    echo "Encryption: $enc

";
    
    // Test connection
    $socket_host = ($enc === 'ssl') ? 'ssl://' . $host : $host;
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    
    $start = microtime(true);
    $socket = @stream_socket_client($socket_host . ':' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
    $elapsed = round((microtime(true) - $start) * 1000) . 'ms';
    
    if (!$socket) {
        echo "CONNECTION FAILED: $errstr ($errno) in $elapsed
";
        exit;
    }
    echo "Connected in $elapsed
";
    
    $read = function($s) { $r = ''; while (($l = fgets($s, 512)) !== false) { $r .= $l; if (substr($l,3,1)==' ') break; } return $r; };
    
    echo "Banner: " . $read($socket) . "
";
    fwrite($socket, "EHLO elvosolar.sk
");
    echo "EHLO: " . $read($socket) . "
";
    
    if ($enc === 'tls') {
        fwrite($socket, "STARTTLS
");
        $tls = $read($socket);
        echo "STARTTLS: $tls
";
        if (strpos($tls, '220') !== false) {
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fwrite($socket, "EHLO elvosolar.sk
");
            echo "EHLO (TLS): " . $read($socket) . "
";
        }
    }
    
    if (!empty($user) && !empty($pass)) {
        fwrite($socket, "AUTH LOGIN
");
        echo "AUTH: " . $read($socket) . "
";
        fwrite($socket, base64_encode($user) . "
");
        echo "USER: " . $read($socket) . "
";
        fwrite($socket, base64_encode($pass) . "
");
        $auth = $read($socket);
        echo "PASS: $auth
";
        
        if (strpos($auth, '235') !== false) {
            echo "
✅ SMTP AUTH SUCCESSFUL - Email should work!
";
        } else {
            echo "
❌ SMTP AUTH FAILED
";
        }
    }
    
    fwrite($socket, "QUIT
");
    fclose($socket);
}

    http_response_code(404);
    echo "Stránka nebola nájdaná.";
}