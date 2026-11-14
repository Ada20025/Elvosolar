<?php
declare(strict_types=1);
/**
 * _bootstrap.php — jedno miesto, odkiaľ web číta konfiguráciu.
 * ------------------------------------------------------------
 * Poradie (prvé vyhráva):
 *   1. premenné prostredia (Railway → Variables, Docker, Apache SetEnv)
 *   2. súbor .env v koreni projektu (jeden súbor so všetkým)
 *   3. _mail-config.php / _admin_config.php (starší spôsob, stále funguje)
 *   4. predvolené hodnoty v kóde
 *
 * Súbor .env je v .gitignore, v .dockerignore a .htaccess ho z webu blokuje.
 */

if (!function_exists('elvo_env')) {
    /** Hodnota premennej prostredia (ošetrená, nikdy null). */
    function elvo_env(string $key): string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_SERVER[$key] ?? ($_ENV[$key] ?? '');
        }
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('elvo_load_env_file')) {
    /**
     * Načíta jednoduchý KEY=VALUE súbor. Existujúce premenné prostredia
     * neprepisuje — na Railway tak vždy vyhrá nastavenie z dashboardu.
     */
    function elvo_load_env_file(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if ($key === '' || !preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                continue;
            }
            if (strlen($value) >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
                    $value = substr($value, 1, -1);
                }
            }
            $existing = getenv($key);
            if ($existing !== false && $existing !== '') {
                continue; // skutočné prostredie má prednosť
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

elvo_load_env_file(__DIR__ . '/.env');

if (!function_exists('elvo_opt')) {
    /** Hodnota z prostredia/.env, inak z konfiguračného poľa, inak predvolená. */
    function elvo_opt(string $envName, array $config, string $key, string $default = ''): string
    {
        $env = elvo_env($envName);
        if ($env !== '') {
            return $env;
        }
        $value = $config[$key] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }
}

if (!function_exists('elvo_data_dir')) {
    /**
     * Priečinok pre dopyty a záchranný log.
     * Railway: Volume na /data + ELVO_DATA_DIR=/data (dáta prežijú redeploy).
     * Bez premennej sa použije _data/ v projekte.
     */
    function elvo_data_dir(): string
    {
        static $dir = null;
        if ($dir !== null) {
            return $dir;
        }
        $env = elvo_env('ELVO_DATA_DIR');
        $dir = $env !== '' ? rtrim($env, '/\\') : __DIR__ . '/_data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('elvo_admin_config_path')) {
    /**
     * Cesta k uloženému heslu administrácie. Zmena hesla v administrácii
     * sa zapisuje do dátového priečinka (na Railway do Volume), aby prežila
     * nové nasadenie. Pôvodný _admin_config.php slúži ako východisko.
     */
    function elvo_admin_config_path(): string
    {
        $saved = elvo_data_dir() . '/admin_config.php';
        return is_file($saved) ? $saved : __DIR__ . '/_admin_config.php';
    }
}
