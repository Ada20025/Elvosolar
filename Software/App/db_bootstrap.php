<?php
// db_bootstrap.php - vytvorí chýbajúce tabuľky
// volá sa z config.php, dá sa spustiť viackrát

if (!function_exists('elvo_db_bootstrap')) {

    /**
     * true = schéma je pripravená. Výsledok sa pamätá 10 minút v dočasnom súbore.
     */
    function elvo_db_bootstrap($pdo, $force = false) {
        if (!$pdo) return false;

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            return false;
        }
        if ($driver !== 'mysql') {
            // SQLite má schému v config.php
            return false;
        }

        $flag = sys_get_temp_dir() . '/elvo_schema_ok_' . md5(DB_HOST . '|' . DB_NAME) . '.flag';
        if (!$force && is_file($flag) && (time() - (int)@filemtime($flag) < 600)) {
            return true;
        }

        // ak tabuľky existujú, netreba nič robiť
        try {
            $hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
            $hasDevices = $pdo->query("SHOW TABLES LIKE 'devices'")->fetchColumn();
            if ($hasUsers && $hasDevices) {
                @touch($flag);
                return true;
            }
        } catch (Throwable $e) {
            return false;
        }

        $file = __DIR__ . '/db/schema.sql';
        if (!is_file($file)) {
            error_log('[DB] db/schema.sql nenájdený — tabuľky sa nevytvoria');
            return false;
        }

        $sql = (string)file_get_contents($file);
        // Odstráň riadkové komentáre (-- ...) a prázdne riadky
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        $created = 0;
        $failed = [];
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strtoupper(substr($stmt, 0, 3)) === 'SET') continue;
            try {
                $pdo->exec($stmt);
                if (stripos($stmt, 'CREATE TABLE') === 0) $created++;
            } catch (Throwable $e) {
                $failed[] = substr($e->getMessage(), 0, 120);
            }
        }

        if ($failed) {
            error_log('[DB] Bootstrap: ' . count($failed) . ' statementov zlyhalo: ' . implode(' | ', array_slice($failed, 0, 3)));
        } else {
            error_log('[DB] Bootstrap hotový — tabuľky pripravené (' . $created . ' CREATE)');
            elvo_db_seed_demo($pdo);
            @touch($flag);
        }
        return empty($failed);
    }

    /**
     * demo dáta, len do prázdnej DB a len so SEED_DEMO=1
     * demo@elvosolar.sk / demo123 (rola user)
     */
    function elvo_db_seed_demo($pdo) {
        if (getenv('SEED_DEMO') !== '1') return false;
        try {
            if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0) return false;

            $hash = password_hash('demo123', PASSWORD_BCRYPT);
            // demo je bežný používateľ
            $pdo->prepare("INSERT INTO users (username, email, password_hash, role, email_verified) VALUES (?, ?, ?, 'user', 1)")
                ->execute(['Demo ElvoSolar', 'demo@elvosolar.sk', $hash]);
            $uid = (int)$pdo->lastInsertId();

            $dev = $pdo->prepare("INSERT INTO devices
                (user_id, name, serial_number, sub_type, status, connection_type, smartlogger_ip,
                 smartlogger_port, modbus_slave_id, battery_soc, power_ac, temp, active_model_id, meter_control_mode)
                VALUES (?, ?, ?, ?, 'offline', ?, ?, ?, ?, 0, 0, 25, 'AI', 'SMART')");
            $dev->execute([$uid, 'ElvoControll Demo', 'DEMO-CM5-001', 'smartlogger', 'modbus_tcp', '192.168.0.10', 502, 205]);
            $dev->execute([$uid, 'Demo Striedač (Huawei)', 'DEMO-CM5-002', 'hybrid', 'modbus_rtu', '', 502, 1]);

            error_log('[DB] Demo dáta vložené: demo@elvosolar.sk / demo123 (2 zariadenia)');
            return true;
        } catch (Throwable $e) {
            error_log('[DB] Demo seed: ' . $e->getMessage());
            return false;
        }
    }
}
