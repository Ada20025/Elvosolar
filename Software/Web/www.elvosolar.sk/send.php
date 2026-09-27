<?php
/**
 * send.php - ELVOSOLAR web (InfinityFree / PHP mail())
 *
 * Akcie (JSON POST):
 *   action=contact     -> kontaktny formular             -> mail adam.dzurko5@gmail.com
 *   action=cart        -> dopytovy kosik (podstranky)    -> mail
 *   action=newsletter  -> odber notifikacii (novinky)    -> mail + potvrdenie odberatelovi
 *
 * Ochrany: honeypot "website", rate-limit 1 poziadavka / 20 s / IP (per akcia).
 * Vsetko bezi cez PHP mail() - bez SMTP, kompatibilne s InfinityFree.
 */
header('Content-Type: application/json; charset=utf-8');

// === KONFIGURACIA ===
$to_email      = "adam.dzurko5@gmail.com";   // kam chodia dopyty
$from_email    = "adam.dzurko5@gmail.com";   // From adresa (InfinityFree: len existujuca schranka na hostingu!)
$site_name     = "ELVOSOLAR a.s.";
$rate_seconds  = 20;                          // min. interval medzi odoslaniami z jednej IP
// ====================

$input_raw = file_get_contents('php://input');
$data = json_decode($input_raw, true);
if (!$data || !isset($data['action'])) {
    echo json_encode(["success" => false, "message" => "Neplatné alebo chýbajúce údaje."]);
    exit;
}
$action = (string)$data['action'];

/* ---------- Spolocne ochrany ---------- */
// Honeypot: skryte pole "website" - ak je vyplnene, je to bot. Falosne "uspech", nic sa neodosle.
if (isset($data['website']) && trim((string)$data['website']) !== '') {
    echo json_encode(["success" => true]);
    exit;
}

// Jednoduchy rate-limit na subore (bez DB) - max 1 poziadavka / $rate_seconds / IP / akcia
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$stampFile = sys_get_temp_dir() . '/elvo_rate_' . md5($ip . '|' . $action) . '.txt';
if (file_exists($stampFile) && (time() - (int)filemtime($stampFile)) < $rate_seconds) {
    echo json_encode(["success" => false, "message" => "Požiadavky posielajte s odstupom — skúste to o chvíľu znova."]);
    exit;
}
@touch($stampFile);

function utf8_subject(string $s): string {
    return "=?utf-8?B?" . base64_encode($s) . "?=";
}
function elvo_mail(string $to, string $subject, string $body, string $from): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "From: " . $from . "\r\n";
    return mail($to, $subject, $body, $headers);
}

/* ---------- 1. KONTAKTNY FORMULAR ---------- */
if ($action === 'contact') {
    $name    = strip_tags(trim($data['name'] ?? ''));
    $email   = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $message = strip_tags(trim($data['message'] ?? ''));

    if ($name === '' || !$email || $message === '') {
        echo json_encode(["success" => false, "message" => "Všetky polia sú povinné a e-mail musí byť v správnom formáte."]);
        exit;
    }
    if (mb_strlen($name) > 120 || mb_strlen($message) > 5000) {
        echo json_encode(["success" => false, "message" => "Správa je príliš dlhá."]);
        exit;
    }

    $subject = utf8_subject("Nový kontaktný dopyt: " . $name);
    $body    = "Meno a priezvisko: $name\n"
             . "E-mail: $email\n"
             . "Stránka: " . ($_SERVER['HTTP_REFERER'] ?? '-') . "\n\n"
             . "Správa:\n$message\n";

    if (elvo_mail($to_email, $subject, $body, $from_email)) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "Serveru sa nepodarilo odoslať e-mail. Skontrolujte nastavenie mail() na hostingu."]);
    }
    exit;
}

/* ---------- 2. DOPYTOVY KOSIK (vsetky podstranky) ---------- */
if ($action === 'cart') {
    $name  = strip_tags(trim($data['name'] ?? ''));
    $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $items = strip_tags(trim($data['cart_items'] ?? ''));
    $total = strip_tags(trim($data['total_price'] ?? ''));
    $page  = strip_tags(trim($data['page'] ?? ''));

    if ($name === '' || !$email || $items === '') {
        echo json_encode(["success" => false, "message" => "Chýbajúce údaje potrebné pre odoslanie košíka."]);
        exit;
    }

    $subject = utf8_subject("Nový dopyt: " . $name . ($page !== '' ? " [$page]" : ''));
    $body    = "Dopyt od (Meno / Firma): $name\n"
             . "E-mail klienta: $email\n"
             . "Stránka: " . ($page !== '' ? $page : ($_SERVER['HTTP_REFERER'] ?? '-')) . "\n\n"
             . "Požadované položky:\n$items\n\n"
             . "Orientačná hodnota dopytu: $total\n";

    if (elvo_mail($to_email, $subject, $body, $from_email)) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "Serveru sa nepodarilo odoslať e-mail. Skontrolujte nastavenie mail() na hostingu."]);
    }
    exit;
}

/* ---------- 3. ODBER NOTIFIKACII (novinky e-mailom) ---------- */
if ($action === 'newsletter') {
    $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        echo json_encode(["success" => false, "message" => "Zadajte platnú e-mailovú adresu."]);
        exit;
    }
    if (mb_strlen($email) > 200) {
        echo json_encode(["success" => false, "message" => "E-mailová adresa je príliš dlhá."]);
        exit;
    }

    $page = strip_tags(trim($data['page'] ?? ''));
    $time = date('j. n. Y H:i');

    // a) Notifikacia majitelovi: novy odberatel
    $subjAdmin = utf8_subject("Nový odber notifikácií: " . $email);
    $bodyAdmin = "Nový odberateľ notifikácií na www.elvosolar.sk\n"
               . "------------------------------------------\n"
               . "E-mail: $email\n"
               . "Stránka: " . ($page !== '' ? $page : ($_SERVER['HTTP_REFERER'] ?? '-')) . "\n"
               . "Dátum: $time\n"
               . "------------------------------------------\n"
               . "Uložte si adresu do svojho mailing listu (napr. Google Contacts, Mailjet free plan).";
    $okAdmin = elvo_mail($to_email, $subjAdmin, $bodyAdmin, $from_email);

    // b) Potvrdenie odberatelovi
    $subjUser = utf8_subject("Potvrdenie odberu noviniek — " . $site_name);
    $bodyUser = "Dobrý deň,\n\n"
              . "práve ste sa prihlásil/á na odber noviniek a notifikácií stránky $site_name.\n"
              . "Budeme vás informovať o novinkách, dotáciách (Zelená podnikom) a akciách.\n\n"
              . "Ak ste o to nestáli, odpovedajte na tento e-mail a odber zrušíme.\n\n"
              . "$site_name\ninfo@elvosolar.sk";
    $okUser = elvo_mail($email, $subjUser, $bodyUser, $from_email);

    if ($okAdmin || $okUser) {
        echo json_encode(["success" => true, "message" => "Úspešne ste prihlásený/á na odber notifikácií. Potvrdenie posielame na váš e-mail."]);
    } else {
        echo json_encode(["success" => false, "message" => "Serveru sa nepodarilo odoslať e-mail. Skúste to neskôr."]);
    }
    exit;
}

echo json_encode(["success" => false, "message" => "Neznáma požiadavka."]);
