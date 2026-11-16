<?php
// create_admin.php — ElvoControl
// Vytvorí (alebo povýši) admin účet. IBA z príkazového riadku:
//
//   ADMIN_EMAIL=admin@elvosolar.sk ADMIN_PASS='silne-heslo' php create_admin.php
//
// Z prehliadača sa nespustí (403) — pôvodná verzia mazala všetkých adminov
// a zakladala účet admin/admin123, čo bol otvorený zadný vchod.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tento skript je len pre CLI.\n");
}

require_once __DIR__ . '/config.php';

$email = trim((string)(getenv('ADMIN_EMAIL') ?: ''));
$pass  = (string)(getenv('ADMIN_PASS') ?: '');
$name  = trim((string)(getenv('ADMIN_NAME') ?: 'Admin'));

if ($email === '' || $pass === '') {
    fwrite(STDERR, "Použitie: ADMIN_EMAIL=... ADMIN_PASS=... php create_admin.php\n");
    exit(1);
}
if (strlen($pass) < 8) {
    fwrite(STDERR, "Heslo musí mať aspoň 8 znakov.\n");
    exit(1);
}

try {
    $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
    $st->execute([strtolower($email)]);
    $id = $st->fetchColumn();
    $hash = password_hash($pass, PASSWORD_BCRYPT);

    if ($id) {
        $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', email_verified = 1 WHERE id = ?")
            ->execute([$hash, $id]);
        echo "✅ Existujúci účet {$email} je admin a má nové heslo.\n";
    } else {
        $pdo->prepare("INSERT INTO users (username, email, password_hash, role, email_verified) VALUES (?, ?, ?, 'admin', 1)")
            ->execute([$name, $email, $hash]);
        echo "✅ Admin {$email} vytvorený.\n";
    }
    echo "Prihlás sa na /login (2FA kód príde e-mailom, prípadne sa zobrazí na obrazovke).\n";
} catch (Throwable $e) {
    fwrite(STDERR, "❌ Chyba: " . $e->getMessage() . "\n");
    exit(1);
}
