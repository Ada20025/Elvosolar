<?php
// db_bootstrap.php — automatické vytvorenie všetkých tabuliek
// Volá sa z config.php po pripojení k MySQL. Idempotentné.
// Zdroj pravdy je db/schema.sql (rovnaké tabuľky ako používa index.php).

if (!function_exists('elvo_db_bootstrap')) {

    /**
     * Vytvorí chýbajúce tabuľky. Vráti true, ak je schéma pripravená.
     * Výsledok si pamätá 10 minút v dočasnom súbore, aby sa nekontrolovalo
     * pri každom requeste.
     */
    function elvo_db_bootstrap($pdo, $force = false) {
        if (!$pdo) return false;

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            return false;
        }
        if ($driver !== 'mysql') {
            // SQLite fallback má vlastnú minimálnu schému v config.php
            return false;
        }

        $flag = sys_get_temp_dir() . '/elvo_schema_ok_' . md5(DB_HOST . '|' . DB_NAME) . '.flag';
        if (!$force && is_file($flag) && (time() - (int)@filemtime($flag) < 600)) {
            return true;
        }

        // Rýchla kontrola — ak kľúčové tabuľky existujú, netreba nič robiť
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
            @touch($flag);
        }
        return empty($failed);
    }
}
