<?php
// ============================================================
// ELVO MAIL RELAY v2 - posielanie e-mailov cez HTTP na porte 443,
// aby fungovalo aj tam, kde su SMTP porty blokovane.
//
// Nahraj tento subor na relay host (napr. /www/mail-relay.php)
// a v appke nastav MAIL_RELAY_URL na jeho adresu.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

// Bezpecnostny token (musi sediet s MAIL_RELAY_KEY na relay hostovi)
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
$headers .= "From: ElvoControll <no-reply@elvosolar.sk>\r\n";
$headers .= "Reply-To: no-reply@elvosolar.sk\r\n";
$headers .= "X-Mailer: ElvoRelay/2.0\r\n";

// relay host odosiela cez vlastny mail()
$ok = @mail($to, $subject_encoded, $html, $headers, '-f no-reply@elvosolar.sk');

if ($ok) {
    echo json_encode(['success' => true, 'via' => 'relay-mail()']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'mail() failed on relay host']);
}
