<?php
header('Content-Type: application/json; charset=utf-8');

// === KONFIGURÁCIA PRIJÍMATEĽA ===
// Sem zadajte e-mail, na ktorý majú dopyty prichádzať:
$to_email = "adam.dzurko5@gmail.com"; 
// ===============================

// Spracovanie prichádzajúcich JSON dát
$input_raw = file_get_contents('php://input');
$data = json_decode($input_raw, true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "Neplatné alebo chýbajúce údaje."]);
    exit;
}

$action = isset($data['action']) ? $data['action'] : '';

// 1. SPRACOVANIE KONTAKTNÉHO FORMULÁRA
if ($action === 'contact') {
    $name = strip_tags(trim($data['name']));
    $email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
    $message = strip_tags(trim($data['message']));

    if (empty($name) || !$email || empty($message)) {
        echo json_encode(["success" => false, "message" => "Všetky polia sú povinné a e-mail musí byť v správnom formáte."]);
        exit;
    }

    $subject = "=?utf-8?B?" . base64_encode("Nový kontaktný dopyt: " . $name) . "?=";
    
    $body = "Meno a priezvisko: $name\n";
    $body .= "E-mail: $email\n\n";
    $body .= "Správa:\n$message\n";

    // Hlavičky pre správne doručenie a UTF-8 kódovanie (aby diakritika fungovala)
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "From: $to_email\r\n"; // Odporúča sa odosielať z adresy na vlastnej doméne kvôli SPF filtrom
    $headers .= "Reply-To: $email\r\n";

    if (mail($to_email, $subject, $body, $headers)) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "Serveru sa nepodarilo odoslať e-mail. Skontrolujte nastavenie mail() na hostingu."]);
    }
} 
// 2. SPRACOVANIE DOPYTOVÉHO KOŠÍKA
elseif ($action === 'cart') {
    $name = strip_tags(trim($data['name']));
    $email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
    $cart_items = strip_tags(trim($data['cart_items']));
    $total_price = strip_tags(trim($data['total_price']));

    if (empty($name) || !$email || empty($cart_items)) {
        echo json_encode(["success" => false, "message" => "Chýbajúce údaje potrebné pre odoslanie košíka."]);
        exit;
    }

    $subject = "=?utf-8?B?" . base64_encode("Nový dopyt na smart riešenia: " . $name) . "?=";

    $body = "Dopyt od (Meno / Firma): $name\n";
    $body .= "E-mail klienta: $email\n\n";
    $body .= "Požadované položky:\n$cart_items\n\n";
    $body .= "Orientačná hodnota dopytu: $total_price\n";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "From: $to_email\r\n";
    $headers .= "Reply-To: $email\r\n";

    if (mail($to_email, $subject, $body, $headers)) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "Chyba servera pri odosielaní dopytového košíka."]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Neznáma požiadavka."]);
}