<?php
/**
 * Prihlasovanie cez Google / Apple / Microsoft.
 *
 * OAuth 2.0 + OpenID Connect, bez Composeru a bez externých knižníc —
 * všetko ide cez cURL. Konfigurácia je v premenných prostredia:
 *
 *   Google:    GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET
 *   Microsoft: MICROSOFT_CLIENT_ID, MICROSOFT_CLIENT_SECRET, MICROSOFT_TENANT (nepovinné, default "common")
 *   Apple:     APPLE_CLIENT_ID (Service ID), APPLE_TEAM_ID, APPLE_KEY_ID, APPLE_PRIVATE_KEY (.p8 obsah)
 *
 * Callback URL, ktorú treba pridať v konzole poskytovateľa:
 *   https://<domena>/auth/google/callback
 *   https://<domena>/auth/apple/callback
 *   https://<domena>/auth/microsoft/callback
 */

if (!function_exists('elvo_oauth_providers')) {

    function elvo_oauth_providers(): array
    {
        return [
            'google' => [
                'name'    => 'Google',
                'client'  => (string)(getenv('GOOGLE_CLIENT_ID') ?: ''),
                'secret'  => (string)(getenv('GOOGLE_CLIENT_SECRET') ?: ''),
                'auth'    => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token'   => 'https://oauth2.googleapis.com/token',
                'info'    => 'https://openidconnect.googleapis.com/v1/userinfo',
                'scope'   => 'openid email profile',
                'mode'    => 'query',
            ],
            'apple' => [
                'name'    => 'Apple',
                'client'  => (string)(getenv('APPLE_CLIENT_ID') ?: ''),
                'secret'  => '', // generuje sa ako podpísaný JWT (.p8)
                'auth'    => 'https://appleid.apple.com/auth/authorize',
                'token'   => 'https://appleid.apple.com/auth/token',
                'info'    => '',
                'scope'   => 'name email',
                'mode'    => 'form_post',
            ],
            'microsoft' => [
                'name'    => 'Microsoft',
                'client'  => (string)(getenv('MICROSOFT_CLIENT_ID') ?: ''),
                'secret'  => (string)(getenv('MICROSOFT_CLIENT_SECRET') ?: ''),
                'auth'    => 'https://login.microsoftonline.com/' . (getenv('MICROSOFT_TENANT') ?: 'common') . '/oauth2/v2.0/authorize',
                'token'   => 'https://login.microsoftonline.com/' . (getenv('MICROSOFT_TENANT') ?: 'common') . '/oauth2/v2.0/token',
                'info'    => 'https://graph.microsoft.com/oidc/userinfo',
                'scope'   => 'openid email profile',
                'mode'    => 'query',
            ],
        ];
    }

    /** Je poskytovateľ nastavený v premenných prostredia? */
    function elvo_oauth_enabled(string $provider): bool
    {
        $all = elvo_oauth_providers();
        if (!isset($all[$provider])) return false;
        $p = $all[$provider];
        if ($p['client'] === '') return false;
        if ($provider === 'apple') {
            return (getenv('APPLE_PRIVATE_KEY') ?: '') !== ''
                && (getenv('APPLE_TEAM_ID') ?: '') !== ''
                && (getenv('APPLE_KEY_ID') ?: '') !== '';
        }
        return $p['secret'] !== '';
    }

    /** Ktoré tlačidlá ukázať na prihlasovacej stránke: ['google' => 'Google', ...] */
    function elvo_oauth_available(): array
    {
        $out = [];
        foreach (elvo_oauth_providers() as $key => $p) {
            if (elvo_oauth_enabled($key)) $out[$key] = $p['name'];
        }
        return $out;
    }

    /** Absolútna adresa appky (na redirect_uri musí sedieť s konzolou poskytovateľa). */
    function elvo_oauth_absolute_base(): string
    {
        $env = rtrim((string)(getenv('APP_URL') ?: ''), '/');
        if ($env !== '') return $env;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /** Malý HTTP klient (POST s form-urlencoded alebo GET) → [status, telo]. */
    function elvo_oauth_http(string $url, ?array $post = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post, '', '&'));
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) return [0, $err];
        return [$code, (string)$body];
    }

    /** Apple client_secret = JWT podpísaný ES256 kľúčom .p8 (platí max 6 mesiacov). */
    function elvo_oauth_apple_secret(): string
    {
        $key  = (string)(getenv('APPLE_PRIVATE_KEY') ?: '');
        $team = (string)(getenv('APPLE_TEAM_ID') ?: '');
        $kid  = (string)(getenv('APPLE_KEY_ID') ?: '');
        $cid  = (string)(getenv('APPLE_CLIENT_ID') ?: '');
        // V Railway sa nové riadky často uložia ako "\n" — opravíme to.
        if (strpos($key, 'BEGIN PRIVATE KEY') === false) {
            $key = str_replace(['\\n', '\n'], "\n", $key);
        }
        if ($key === '' || $team === '' || $kid === '' || $cid === '') return '';

        $b64 = static function (string $data): string {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };
        $now = time();
        $header = $b64(json_encode(['alg' => 'ES256', 'kid' => $kid]));
        $claims = $b64(json_encode([
            'iss' => $team,
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => 'https://appleid.apple.com',
            'sub' => $cid,
        ]));
        $signing = $header . '.' . $claims;

        $pem = openssl_pkey_get_private($key);
        if (!$pem) return '';
        $der = '';
        if (!openssl_sign($signing, $der, $pem, OPENSSL_ALGO_SHA256)) return '';
        // openssl_sign vracia už DER (r,s) sekvenciu — pre JWS ju treba rozložiť na 32+32 bajtov.
        $r = $s = '';
        $pos = 2; // preskočíme SEQUENCE header
        if (ord($der[1]) & 0x80) $pos = 2 + (ord($der[1]) & 0x7f);
        $lenR = ord($der[$pos + 1]);
        $r = substr($der, $pos + 2, $lenR);
        $pos2 = $pos + 2 + $lenR;
        $lenS = ord($der[$pos2 + 1]);
        $s = substr($der, $pos2 + 2, $lenS);
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return $signing . '.' . $b64($r . $s);
    }

    /** URL, na ktorú pošleme používateľa (prihlásenie u poskytovateľa). */
    function elvo_oauth_authorize_url(string $provider, string $redirect, string $state, string $nonce): string
    {
        $all = elvo_oauth_providers();
        $p   = $all[$provider];
        $args = [
            'client_id'     => $p['client'],
            'redirect_uri'  => $redirect,
            'response_type' => 'code',
            'scope'         => $p['scope'],
            'state'         => $state,
            'nonce'         => $nonce,
        ];
        if ($provider === 'apple') {
            $args['response_mode'] = 'form_post';
        }
        if ($provider === 'google') {
            $args['access_type'] = 'online';
            $args['prompt'] = 'select_account';
        }
        return $p['auth'] . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    /** Vymení autorizačný kód za tokeny. */
    function elvo_oauth_exchange(string $provider, string $code, string $redirect): ?array
    {
        $all = elvo_oauth_providers();
        $p   = $all[$provider];
        $secret = $provider === 'apple' ? elvo_oauth_apple_secret() : $p['secret'];
        if ($secret === '') return null;

        [$status, $body] = elvo_oauth_http($p['token'], [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect,
            'client_id'     => $p['client'],
            'client_secret' => $secret,
        ]);
        $json = json_decode($body, true);
        if ($status !== 200 || !is_array($json) || empty($json['access_token'])) return null;
        return $json;
    }

    /** base64url dekódovanie (JWT payload). */
    function elvo_oauth_b64url(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return (string)base64_decode($data, true);
    }

    /**
     * Zistí e-mail a meno prihláseného používateľa.
     * Vracia ['email' => ..., 'email_verified' => bool, 'name' => ...] alebo null.
     */
    function elvo_oauth_identity(string $provider, array $tokens, string $expectedNonce = ''): ?array
    {
        $all = elvo_oauth_providers();
        $p   = $all[$provider];
        $email = '';
        $name  = '';
        $verified = false;

        // Apple: všetko je v id_token (userinfo endpoint nemá)
        if ($provider === 'apple') {
            $parts = explode('.', (string)($tokens['id_token'] ?? ''));
            if (count($parts) !== 3) return null;
            $claims = json_decode(elvo_oauth_b64url($parts[1]), true);
            if (!is_array($claims)) return null;
            if (($claims['iss'] ?? '') !== 'https://appleid.apple.com') return null;
            if (($claims['aud'] ?? '') !== $p['client']) return null;
            if ($expectedNonce !== '' && (($claims['nonce'] ?? '') !== $expectedNonce)) return null;
            $email = (string)($claims['email'] ?? '');
            $verified = (bool)($claims['email_verified'] ?? false);
            return $email === '' ? null : ['email' => $email, 'email_verified' => $verified, 'name' => ''];
        }

        // Google / Microsoft: userinfo cez access token
        [$status, $body] = elvo_oauth_http($p['info'], null, ['Authorization: Bearer ' . $tokens['access_token']]);
        $json = json_decode($body, true);
        if ($status !== 200 || !is_array($json)) return null;

        $email = (string)($json['email'] ?? '');
        if ($email === '' && !empty($json['preferred_username'])) $email = (string)$json['preferred_username'];
        $name  = (string)($json['name'] ?? '');
        if ($name === '' && !empty($json['given_name'])) {
            $name = trim(($json['given_name'] ?? '') . ' ' . ($json['family_name'] ?? ''));
        }
        // Microsoft vracia email_verified nepravdivo pri firemných účtoch — berieme,
        // že e-mail z overeného Microsoft/Google účtu je dôveryhodný.
        $verified = !isset($json['email_verified']) ? true : (bool)$json['email_verified'];

        return $email === '' ? null : ['email' => $email, 'email_verified' => $verified, 'name' => $name];
    }

    /** Dočasné úložisko "state" (kvôli Apple, ktorý posiela odpoveď ako POST bez cookie). */
    function elvo_oauth_state_save(PDO $pdo, string $state, string $provider, string $nonce, string $redirect): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS oauth_states (
            state VARCHAR(64) PRIMARY KEY,
            provider VARCHAR(20) NOT NULL,
            nonce VARCHAR(64) NOT NULL,
            redirect_uri VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->prepare("DELETE FROM oauth_states WHERE created_at < (NOW() - INTERVAL 15 MINUTE)")->execute();
        $pdo->prepare("INSERT INTO oauth_states (state, provider, nonce, redirect_uri) VALUES (?, ?, ?, ?)")
            ->execute([$state, $provider, $nonce, $redirect]);
    }

    function elvo_oauth_state_take(PDO $pdo, string $state): ?array
    {
        try {
            $st = $pdo->prepare("SELECT * FROM oauth_states WHERE state = ?");
            $st->execute([$state]);
            $row = $st->fetch();
            if (!$row) return null;
            $pdo->prepare("DELETE FROM oauth_states WHERE state = ?")->execute([$state]);
            return $row;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Nájde používateľa podľa e-mailu alebo ho založí.
     * Nový účet nemá použiteľné heslo — prihlasuje sa cez poskytovateľa
     * (heslo si môže neskôr nastaviť cez "Zabudli ste heslo?").
     */
    function elvo_oauth_user(PDO $pdo, string $email, string $name, string $provider): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || strpos($email, '@') === false) return null;

        $st = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $st->execute([$email]);
        $user = $st->fetch();
        if ($user) return $user;

        // Prezývka: z mena alebo z časti e-mailu, musí byť unikátna
        $base = trim($name) !== '' ? $name : explode('@', $email)[0];
        $base = preg_replace('/[^A-Za-z0-9._ -]/', '', $base) ?: 'user';
        $username = $base;
        $i = 1;
        while (true) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $chk->execute([$username]);
            if (!$chk->fetch()) break;
            $username = $base . ($i++);
        }

        $role = in_array($email, elvo_admin_emails(), true) ? 'admin' : 'user';
        $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
        $ins = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $ins->execute([$username, $email, $hash, $role]);

        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user) return null;
        if (function_exists('elvo_ensure_some_admin')) $user = elvo_ensure_some_admin($pdo, $user);
        return $user;
    }

    /** Prihlási používateľa do session (rovnaké polia ako klasické prihlásenie). */
    function elvo_oauth_login(PDO $pdo, array $user, string $provider): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']     = (int)$user['id'];
        $_SESSION['username']    = (string)$user['username'];
        $_SESSION['email']       = (string)$user['email'];
        $_SESSION['role']        = (string)($user['role'] ?? 'user');
        $_SESSION['last_activity'] = time();
        $_SESSION['stay_logged_in'] = true;

        // Zariadenie si zapamätáme rovnako ako pri heslovom prihlásení
        try {
            $devHash = hash('sha256', 'oauth:' . $provider . ':' . ($_SERVER['HTTP_USER_AGENT'] ?? '-'));
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_devices (id INTEGER PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, device_hash VARCHAR(64) NOT NULL, device_name VARCHAR(100) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_login DATETIME NULL, UNIQUE KEY uq_userdev (user_id, device_hash))");
            $pdo->prepare("INSERT INTO login_devices (user_id, device_hash, device_name, last_login) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_login = NOW()")
                ->execute([(int)$user['id'], $devHash, substr($provider . ' · ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Zariadenie'), 0, 100)]);
        } catch (Exception $e) { /* best effort */ }
    }
}
