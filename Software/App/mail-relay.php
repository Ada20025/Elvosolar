<?php
// ============================================================
// ELVO MAIL RELAY - umiestni na alwaysdata hosting
// (napr. do /www/mail-relay.php => https://adamdz.alwaysdata.net/mail-relay.php)
//
// Railway (elvosolar-production.up.railway.app) posiela maily cez tento
// skript, lebo na Railway nefunguje mail() ani Gmail SMTP bez hesla.
// Na alwaysdata mail() funguje priamo.
//
// NA RAILWAY POTOM NASTAV PREMENNU:
//   MAIL_RELAY_URL = https://adamdz.alwaysdata.net/mail-relay.php
// ============================================================

header('Content-Type: application/json; charset=utf-8');

// --- Bezpecnostny token (optional): na Railway nastav rovnaky MAIL_RELAY_KEY ---
$expected_key = getenv('MAIL_RELAY_KEY') ?: 'elvo-relay-2026';
$received_key = $_SERVER['HTTP_X_RELAY_KEY'] ?? '';

if (!hash_equals($expected_key, $received_key)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid relay key']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['to']) || empty($input['subject']) || empty($input['html'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$to      = filter_var($input['to'], FILTER_VALIDATE_EMAIL);
$subject = $input['subject'];
$html    = $input['html'];
$accent  = preg_match('/^#[0-9a-fA-F]{6}$/', $input['accent'] ?? '') ? $input['accent'] : '#007aff';

if (!$to) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid recipient']);
    exit;
}

$subject_encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: ElvoSolar Control <no-reply@elvosolar.sk>\r\n";
$headers .= "Reply-To: support@elvosolar.sk\r\n";
$headers .= "X-Mailer: ElvoRelay/1.0\r\n";

$ok = @mail($to, $subject_encoded, $html, $headers, '-f no-reply@elvosolar.sk');

if ($ok) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'mail() failed on relay host']);
}
