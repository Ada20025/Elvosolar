<?php
/**
 * Pridá cm5_config tabuľku ak neexistuje.
 * Volá sa z config.php po vytvorení PDO spojenia.
 */
function ensure_cm5_config_table($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cm5_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            serial_number VARCHAR(64) NOT NULL,
            config_json JSON NOT NULL,
            status ENUM('pending','applied','error') DEFAULT 'pending',
            result_json JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_serial (serial_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}
