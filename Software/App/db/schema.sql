-- =============================================================================
--  ElvoControll — kompletná MySQL schéma pre Railway
-- =============================================================================
--  Použitie: Railway → MySQL plugin → Query (alebo `mysql < schema.sql`).
--  Skript je idempotentný (IF NOT EXISTS) — dá sa pustiť aj opakovane.
--  Aplikácia (index.php) si tabuľky vie vytvoriť aj sama, toto je istota
--  pri prvom nasadení a pri obnove databázy.
-- =============================================================================

SET NAMES utf8mb4;

-- ── Používatelia ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    email_verified TINYINT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Zariadenia (CM5 jednotky) ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(200) DEFAULT 'ElvoSolar CM5',
    serial_number VARCHAR(100) DEFAULT '',
    slave_id INT DEFAULT 1,
    brand_id VARCHAR(50) DEFAULT '',
    category_id VARCHAR(50) DEFAULT '',
    model_id VARCHAR(50) DEFAULT '',
    sub_type VARCHAR(50) DEFAULT '',
    status VARCHAR(20) DEFAULT 'offline',
    last_seen DATETIME NULL,
    battery_soc FLOAT DEFAULT 0,
    power_ac FLOAT DEFAULT 0,
    fve_power_w FLOAT DEFAULT 0,
    temp FLOAT DEFAULT 0,
    total_saved_eur DECIMAL(10,2) DEFAULT 0,
    total_kwh DECIMAL(10,2) DEFAULT 0,
    manual_override VARCHAR(10) DEFAULT 'AUTO',
    active_model_id VARCHAR(20) DEFAULT 'AI',
    meter_control_mode VARCHAR(20) DEFAULT 'SMART',
    night_sleep INT DEFAULT 1,
    min_power_pct FLOAT DEFAULT 0,
    max_power_pct FLOAT DEFAULT 100,
    connection_type VARCHAR(20) DEFAULT 'modbus_tcp',
    smartlogger_ip VARCHAR(50) DEFAULT '192.168.0.10',
    smartlogger_port INT DEFAULT 502,
    modbus_slave_id INT DEFAULT 205,
    min_okte_price_cz_eur FLOAT DEFAULT 0,
    admin_command TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dev_user (user_id),
    INDEX idx_dev_serial (serial_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Telemetria ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS telemetry (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    battery_soc FLOAT DEFAULT 0,
    power_ac FLOAT DEFAULT 0,
    temp FLOAT DEFAULT 0,
    freq FLOAT DEFAULT 50,
    status_msg VARCHAR(255) DEFAULT 'Online',
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tel_dev_id (device_id, id),
    INDEX idx_tel_time (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Denné agregáty telemetrie (drží históriu, keď sa raw dáta po 90 dňoch zmažú)
CREATE TABLE IF NOT EXISTS telemetry_daily (
    device_id INT NOT NULL,
    day DATE NOT NULL,
    samples INT DEFAULT 0,
    avg_power FLOAT DEFAULT 0,
    max_power FLOAT DEFAULT 0,
    min_soc FLOAT DEFAULT 0,
    max_soc FLOAT DEFAULT 0,
    kwh FLOAT DEFAULT 0,
    PRIMARY KEY (device_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Alerty zariadení (offline, nízke SOC, teplota, chyba striedača) ─────────
CREATE TABLE IF NOT EXISTS device_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    alert_key VARCHAR(40) NOT NULL,
    title VARCHAR(120) NOT NULL,
    body VARCHAR(255) DEFAULT '',
    severity VARCHAR(10) DEFAULT 'warn',
    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    email_sent TINYINT(1) DEFAULT 0,
    UNIQUE KEY uq_alert (device_id, alert_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Predvoľby notifikácií používateľa (kanály push/email/oboje/žiadne) ──────
CREATE TABLE IF NOT EXISTS user_prefs (
    user_id INT PRIMARY KEY,
    prefs TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Web Push (VAPID kľúč + odbery) ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS push_keys (
    id TINYINT PRIMARY KEY,
    pub TEXT,
    priv TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_subs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    sub_json TEXT NOT NULL,
    ua VARCHAR(200) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_endpoint (endpoint),
    INDEX idx_push_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── CM5 konfigurácia / príkazy z cloudu ────────────────────────────────────
CREATE TABLE IF NOT EXISTS cm5_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    serial_number VARCHAR(100) DEFAULT 'CM5-DEFAULT',
    modbus_slave_id INT DEFAULT 205,
    admin_command TEXT NULL,
    config_json TEXT NULL,
    result_json TEXT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cm5_serial (serial_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── MQTT audit (skúška káblom + príkazy z cloudu) ───────────────────────────
CREATE TABLE IF NOT EXISTS mqtt_audit (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Prístupová brána (predlogin) ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS access_gate (
    id TINYINT PRIMARY KEY,
    gate_user VARCHAR(64) NOT NULL,
    gate_pass_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Prihlásenia: nové zariadenia + throttle ────────────────────────────────
CREATE TABLE IF NOT EXISTS login_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_hash VARCHAR(64) NOT NULL,
    device_name VARCHAR(100) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    UNIQUE KEY uq_userdev (user_id, device_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_throttle (
    device_hash VARCHAR(64) PRIMARY KEY,
    blocked_until DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Stav migrácií ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS migrations_state (
    id INT PRIMARY KEY AUTO_INCREMENT,
    done_date VARCHAR(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Záznam odoslaných notifikácií (dedup pre cron) ─────────────────────────
CREATE TABLE IF NOT EXISTS notif_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(30) NOT NULL,
    dedup_key VARCHAR(60) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notif (user_id, type, dedup_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Voliteľný demo účet (odkomentuj, ak ho chceš) ───────────────────────────
-- Demo účet sa nezakladá (voliteľné testovacie dáta: db/demo.sql).
-- Hash nižšie je bcrypt pre heslo "demo123".
-- INSERT IGNORE INTO users (username, email, password_hash, email_verified)
-- VALUES ('Demo ElvoSolar', 'demo@elvosolar.sk',
--         '$2y$10$e0NRvQ0d0m0Q0m0Q0m0Q0uJ8m5q1J0GQ1p3gKq0Xy7bYyq7S1q1K', 1);
