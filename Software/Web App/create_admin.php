<?php
// create_admin.php — ElvoControl
// Vytvorí admin účet. Spusti raz a potom vymaž!

// Načítaj config
require_once __DIR__ . '/config.php';

$admin_user = 'admin';
$admin_pass = 'admin123';
$admin_email = 'admin@elvosolar.sk';

$hashed = password_hash($admin_pass, PASSWORD_BCRYPT);

try {
    // Vymaž starého admina
    $pdo->exec("DELETE FROM users WHERE role = 'admin' OR username = 'admin'");
    
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
    $stmt->execute([$admin_user, $admin_email, $hashed]);
    
    echo "<div style='font-family: Arial; padding: 30px; background: #0f172a; color: #f8fafc; border-radius: 16px; max-width: 500px; margin: 40px auto;'>";
    echo "<h2 style='color: #10b981;'>🟢 Admin úspešne vytvorený!</h2>";
    echo "<p>Email: <strong>$admin_email</strong></p>";
    echo "<p>Heslo: <strong>$admin_pass</strong></p>";
    echo "<p style='color: #f43f5e;'>⚠️ Vymaž tento súbor!</p>";
    echo "<p><a href='/login' style='color: #3b82f6;'>Prihlásiť sa →</a></p>";
    echo "</div>";
} catch (PDOException $e) {
    echo "❌ Chyba: " . $e->getMessage();
}
?>
