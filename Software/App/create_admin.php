<?php
// create_admin.php (ČISTÉ PHP PRE ALWAYSDATA)

// === KONFIGURÁCIA DATABÁZY (Upravte podľa vášho Alwaysdata profilu) ===
$host = 'mysql-adamdz.alwaysdata.net'; 
$db   = 'adamdz_solar';             
$user = 'adamdz_admin';                
$pass = '1Adamko.'; // Doplňte vaše skutočné heslo k MySQL databáze
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    $admin_user = 'admin';
    $admin_pass = 'admin123';
    
    // Štandardné a bezpečné PHP šifrovanie (generuje silný bcrypt hash začínajúci na $2y$)
    $hashed_pass = password_hash($admin_pass, PASSWORD_DEFAULT);
    
    // Vyčistenie starých administrátorských účtov pre zamedzenie duplicít
    $pdo->exec("DELETE FROM users WHERE role = 'admin' OR username = 'admin'");
    
    // Zápis nového administrátora
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (:user, :email, :pass, 'admin')");
    $stmt->execute([
        'user'  => $admin_user,
        'email' => 'admin@elvosolar.sk',
        'pass'  => $hashed_pass
    ]);
    
    echo "<div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #10b981; background-color: #f0fdf4; border-radius: 12px; max-w-sm: 500px;'>";
    echo "<h2 style='color: #10b981; margin-top: 0;'>🟢 Administrátor úspešne vytvorený!</h2>";
    echo "Prihlasovacie meno (Username): <strong>" . htmlspecialchars($admin_user) . "</strong><br>";
    echo "Heslo (Password): <strong>" . htmlspecialchars($admin_pass) . "</strong><br><br>";
    echo "<span style='font-si