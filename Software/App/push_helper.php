<?php
// push_helper.php — Web Push (VAPID + aes128gcm) bez externych kniznic (pure PHP + OpenSSL)
// VAPID par sa vygeneruje raz a ulozi v tabulke push_keys.
// Subscriptions sa ukladaju v tabulke push_subs (user_id + endpoint + JSON).

if (!function_exists('elvo_push_b64url_enc')) {
    function elvo_push_b64url_enc($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
    function elvo_push_b64url_dec($str) { $pad = strlen($str) % 4; if ($pad) $str .= str_repeat('=', 4 - $pad); return base64_decode(strtr($str, '-_', '+/')); }

    // Vrati ['pub'=>b64url, 'priv'=>PEM] — pri prvom pouziti vygeneruje
    function elvo_push_keys($pdo) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS push_keys (id TINYINT PRIMARY KEY, pub TEXT, priv TEXT)");
            $row = $pdo->query("SELECT pub, priv FROM push_keys WHERE id = 1")->fetch();
            if ($row && !empty($row['pub']) && !empty($row['priv'])) return ['pub' => $row['pub'], 'priv' => $row['priv']];
            $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if (!$res) return null;
            openssl_pkey_export($res, $privPem);
            $d = openssl_pkey_get_details($res);
            $x = str_pad(ltrim($d['ec']['x'], "\x00"), 32, "\x00", STR_PAD_LEFT);
            $y = str_pad(ltrim($d['ec']['y'], "\x00"), 32, "\x00", STR_PAD_LEFT);
            $pubB64 = elvo_push_b64url_enc("\x04" . $x . $y);
            $pdo->prepare("REPLACE INTO push_keys (id, pub, priv) VALUES (1, ?, ?)")->execute([$pubB64, $privPem]);
            return ['pub' => $pubB64, 'priv' => $privPem];
        } catch (Exception $e) { error_log('[WEBPUSH] keys: ' . $e->getMessage()); return null; }
    }

    // DER ECDSA podpis -> raw r||s (64 B) pre ES256
    function elvo_push_der_to_raw($der) {
        $p = 2;
        $b1 = ord($der[1]);
        if ($b1 & 0x80) $p += ($b1 & 0x7f);
        $p++; // 0x02 (r)
        $rlen = ord($der[$p]); $p++;
        $r = substr($der, $p, $rlen); $p += $rlen;
        $p++; // 0x02 (s)
        $slen = ord($der[$p]); $p++;
        $s = substr($der, $p, $slen);
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    function elvo_push_vapid_jwt($endpoint, $privPem) {
        $aud = 'https://' . parse_url($endpoint, PHP_URL_HOST);
        $h = elvo_push_b64url_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = elvo_push_b64url_enc(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => 'mailto:admin@elvosolar.sk']));
        $input = $h . '.' . $c;
        $key = openssl_pkey_get_private($privPem);
        if (!$key) return '';
        openssl_sign($input, $derSig, $key, OPENSSL_ALGO_SHA256);
        return $input . '.' . elvo_push_b64url_enc(elvo_push_der_to_raw($derSig));
    }

    // raw uncompressed P-256 public key -> OpenSSL key resource
    function elvo_push_pub_from_raw($raw) {
        $hdr = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($hdr . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem);
    }

    // Posle push VSETKYM zariadeniam usera. Vracia pocet uspesnych odoslani.
    function elvo_push_user($pdo, $user_id, $title, $body, $tag, $url) {
        try {
            $stmt = $pdo->prepare("SELECT sub_json FROM push_subs WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $sent = 0;
            foreach ($stmt->fetchAll() as $row) {
                $sub = json_decode($row['sub_json'], true);
                if (!is_array($sub)) continue;
                $r = elvo_push_send($pdo, $sub, $title, $body, $tag, $url);
                if ($r === 'expired') {
                    $pdo->prepare("DELETE FROM push_subs WHERE endpoint = ?")->execute([$sub['endpoint'] ?? '']);
                } elseif ($r === true) {
                    $sent++;
                }
            }
            return $sent;
        } catch (Exception $e) { error_log('[WEBPUSH] user: ' . $e->getMessage()); return 0; }
    }

    // Posle push notifikaciu. true = OK, 'expired' = subscription mrtva (vymazat), false = ina chyba
    function elvo_push_send($pdo, $sub, $title, $body, $tag, $url) {
        try {
            $keys = elvo_push_keys($pdo);
            if (!$keys) return false;
            $end = $sub['endpoint'] ?? '';
            if (!$end) return false;
            $subKeys = $sub['keys'] ?? [];
            $uaPub = elvo_push_b64url_dec($subKeys['p256dh'] ?? '');
            $authSec = elvo_push_b64url_dec($subKeys['auth'] ?? '');
            if (strlen($uaPub) !== 65 || strlen($authSec) < 16) return false;

            $salt = random_bytes(16);
            $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if (!$eph) return false;
            $ed = openssl_pkey_get_details($eph);
            $asPub = "\x04" . str_pad(ltrim($ed['ec']['x'], "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($ed['ec']['y'], "\x00"), 32, "\x00", STR_PAD_LEFT);

            $uaKey = elvo_push_pub_from_raw($uaPub);
            if (!$uaKey) return false;
            $shared = false;
            try { $shared = openssl_pkey_derive($uaKey, $eph, 32); } catch (Throwable $t1) { $shared = false; }
            if ($shared === false) { try { $shared = openssl_pkey_derive($eph, $uaKey, 32); } catch (Throwable $t2) { $shared = false; } }
            if ($shared === false) return false;

            // RFC 8291: HKDF retaz
            // IKM  = HKDF(salt, ecdh_secret, "WebPush: info"||0x00||ua_pub||as_pub, 32)
            // PRK  = HKDF(salt=auth_secret, IKM=ikm, "Content-Encoding: auth"||0x00, 32)
            $info = "WebPush: info\x00" . $uaPub . $asPub;
            $ikm = hash_hkdf('sha256', $shared, 32, $info, $salt);
            $prk = hash_hkdf('sha256', $ikm, 32, "Content-Encoding: auth\x00", $authSec);
            $cek = hash_hkdf('sha256', $prk, 16, "Content-Encoding: aes128gcm\x00", $salt);
            $nonce = hash_hkdf('sha256', $prk, 12, "Content-Encoding: nonce\x00", $salt);

            $plaintext = json_encode(['title' => $title, 'body' => $body, 'tag' => $tag, 'url' => $url]) . "\x02";
            $tagBin = '';
            $ct = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tagBin);
            if ($ct === false) return false;

            $jwt = elvo_push_vapid_jwt($end, $keys['priv']);
            if (!$jwt) return false;

            // aes128gcm header: salt(16) || rs(4 BE) || idlen(1) || server pub(65)
            $httpBody = $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tagBin;
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/octet-stream\r\nContent-Encoding: aes128gcm\r\nTTL: 604800\r\nUrgency: high\r\nTopic: elvo-alert\r\nAuthorization: vapid t=" . $jwt . ", k=" . $keys['pub'] . "\r\n",
                'content' => $httpBody,
                'timeout' => 6,
                'ignore_errors' => true,
            ]]);
            $resp = @file_get_contents($end, false, $ctx);
            $code = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $hh) {
                    if (preg_match('#HTTP/\S+\s+(\d+)#', $hh, $m)) { $code = (int)$m[1]; break; }
                }
            }
            if ($code >= 200 && $code < 300) return true;
            if ($code === 404 || $code === 410) return 'expired';
            error_log('[WEBPUSH] HTTP ' . $code . ': ' . substr((string)$resp, 0, 150));
            return false;
        } catch (Exception $e) {
            error_log('[WEBPUSH] send: ' . $e->getMessage());
            return false;
        }
    }
}
