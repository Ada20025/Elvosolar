<?php
/**
 * pridá tabuľku cm5_config, keď neexistuje (volá sa z config.php)
 * nič nesmie byť NOT NULL bez defaultu
 */
function ensure_cm5_config_table($pdo) {
    try {
        $pdo->exec("
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
                INDEX idx_serial (serial_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $e) {
        error_log('[DB] cm5_config: ' . $e->getMessage());
    }
}
