<?php
/**
 * ElvoControl — Database Setup Script
 * 
 * Spusti raz: https://tvoja-app.up.railway.app/setup_database.php
 * Vytvorí všetky tabuľky + admin účet.
 * PO SPUSTENÍ VYMAŽ TENTO SÚBOR!
 */

// Priame čítanie - najspoľahlivejšie
$db_host = getenv('MYSQLHOST') ?: 'localhost';
$db_name = getenv('MYSQL_DATABASE') ?: 'railway';
$db_user = getenv('MYSQLUSER') ?: 'root';
$db_pass = getenv('MYSQL_ROOT_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';
$db_port = getenv('MYSQLPORT') ?: '3306';

$charset = 'utf8mb4';
$dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=$charset";

// Debug info
echo "<!-- DB DEBUG: host=" . htmlspecialchars($db_host) . " port=" . htmlspecialchars($db_port) . " db=" . htmlspecialchars($db_name) . " user=" . htmlspecialchars($db_user) . " pass_len=" . strlen($db_pass) . " -->
";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    echo "🟢 Pripojenie k DB úspešné!<br><br>";
} catch (PDOException $e) {
    die("🔴 Chyba pripojenia: " . $e->getMessage());
}

// =============================================
// VYTVORENIE TABULIEK
// =============================================

$queries = [

// --- USERS ---
"CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// --- DEVICES ---
"CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(200) DEFAULT 'ElvoSolar CM5',
    serial_number VARCHAR(100) NOT NULL,
    slave_id INT DEFAULT 1,
    brand_id VARCHAR(50) DEFAULT '',
    category_id VARCHAR(50) DEFAULT '',
    model_id VARCHAR(50) DEFAULT '',
    total_saved_eur DECIMAL(10,2) DEFAULT 0.00,
    total_kwh DECIMAL(10,2) DEFAULT 0.00,
    last_seen TIMESTAMP NULL,
    manual_override VARCHAR(10) DEFAULT 'AUTO',
    active_model_id VARCHAR(20) DEFAULT 'AI',
    night_sleep INT DEFAULT 1,
    ai_state JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_serial (serial_number),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// --- TELEMETRY ---
"CREATE TABLE IF NOT EXISTS telemetry (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    timestamp INT DEFAULT 0,
    power_ac FLOAT DEFAULT 0,
    battery_soc FLOAT DEFAULT 0,
    temp FLOAT DEFAULT 0,
    freq FLOAT DEFAULT 50.0,
    status_msg VARCHAR(200) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_time (device_id, timestamp),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// --- PASSWORD RESETS ---
"CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
// --- SYSTEM SETTINGS ---
"CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

];

echo "<h2>📋 Vytváram tabuľky...</h2>";
foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        $table = preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m) ? $m[1] : '?';
        echo "✅ Tabuľka <strong>$table</strong> vytvorená<br>";
    } catch (PDOException $e) {
        echo "❌ Chyba: " . $e->getMessage() . "<br>";
    }
}

// =============================================
// ADMIN ÚČET
// =============================================

echo "<br><h2>👤 Vytváram admin účet...</h2>";

$admin_user = 'admin';
$admin_email = 'admin@elvosolar.sk';
$admin_pass = 'admin123';
$hashed = password_hash($admin_pass, PASSWORD_BCRYPT);

try {
    // Vymaž starého admina
    $pdo->exec("DELETE FROM users WHERE role = 'admin' OR username = 'admin'");
    
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
    $stmt->execute([$admin_user, $admin_email, $hashed]);
    echo "✅ Admin vytvorený<br>";
} catch (PDOException $e) {
    echo "❌ Admin chyba: " . $e->getMessage() . "<br>";
}

// =============================================
// ŠTandardné nastavenia
// =============================================

echo "<br><h2>⚙️ Vkladám štandardné nastavenia...</h2>";

$defaults = [
    'cloud_sync_url' => '',
    'rs485_active_port' => '/dev/ttyAMA3',
    'rs485_parity' => 'N',
    'meter_mode' => 'NONE',
    'meter_control_mode' => 'SMART',
];

foreach ($defaults as $key => $value) {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (`key`, `value`) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
        echo "✅ $key = $value<br>";
    } catch (PDOException $e) {
        echo "⚠️ $key: " . $e->getMessage() . "<br>";
    }
}

// =============================================
// VÝSLEDOK
// =============================================

echo "
<br><div style='background:#0f172a;color:#f8fafc;padding:24px;border-radius:16px;margin-top:20px;'>
<h2 style='color:#3b82f6;margin-top:0;'>🎉 Databáza pripravená!</h2>
<p style='color:#94a3b8;font-size:14px;'>
    <strong style='color:#f8fafc;'>Prihlasovacie údaje:</strong><br>
    Email: <code style='color:#818cf8;'>admin@elvosolar.sk</code><br>
    Heslo: <code style='color:#818cf8;'>admin123</code>
</p>
<p style='color:#f43f5e;font-size:12px;margin-top:16px;'>
    ⚠️ <strong>POZOR:</strong> Vymaž tento súbor (setup_database.php) z serveru po prvom spustení!
</p>
<p style='color:#64748b;font-size:12px;margin-top:8px;'>
    Prihlas sa na: <a href='/login' style='color:#3b82f6;'>/login</a>
</p>
</div>";
?>
