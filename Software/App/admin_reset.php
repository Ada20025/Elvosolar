<?php
// admin_reset.php - jednorazove nastavenie hesla admina
// otvor: https://<domena>/admin_reset.php?key=K7m2Qe9xLp4T
// potom subor z hostingu ZMAZ

$KEY = 'K7m2Qe9xLp4T';
$ADMIN_EMAIL = 'admin@elvosolar.sk';

if (($_GET['key'] ?? '') !== $KEY) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

$msg = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string)($_POST['password'] ?? '');
    $conf = (string)($_POST['password2'] ?? '');
    if (strlen($new) < 8) {
        $msg = 'Heslo musi mat aspon 8 znakov.';
    } elseif ($new !== $conf) {
        $msg = 'Hesla sa nezhoduju.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $row = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
        $row->execute([$ADMIN_EMAIL]);
        $user = $row->fetch();
        if ($user) {
            $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', email_verified = 1 WHERE id = ?")
                ->execute([$hash, $user['id']]);
            $msg = 'Heslo pre ' . $ADMIN_EMAIL . ' je nastavene.';
        } else {
            $pdo->prepare("INSERT INTO users (username, email, password_hash, role, email_verified) VALUES ('Admin', ?, ?, 'admin', 1)")
                ->execute([$ADMIN_EMAIL, $hash]);
            $msg = 'Ucet ' . $ADMIN_EMAIL . ' neexistoval, vytvoril som ho.';
        }
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Heslo admina | ElvoControll</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#020617;color:#e2e8f0;font-family:system-ui,sans-serif;padding:16px}
.card{width:100%;max-width:380px;background:rgba(2,6,23,.9);border:1px solid rgba(6,182,212,.25);border-radius:20px;padding:26px}
h1{font-size:17px;margin:0 0 4px}
p.sub{font-size:11px;color:#64748b;margin:0 0 16px}
label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#67e8f9;margin:12px 0 5px}
input{width:100%;box-sizing:border-box;background:#05070f;border:1px solid #334155;color:#fff;font-size:14px;border-radius:10px;padding:11px 13px;outline:none}
input:focus{border-color:#06b6d4}
button{width:100%;margin-top:16px;background:linear-gradient(90deg,#0891b2,#2563eb);border:0;color:#fff;font-size:12px;font-weight:800;letter-spacing:1px;text-transform:uppercase;border-radius:10px;padding:13px;cursor:pointer}
.msg{margin:0 0 12px;padding:10px 12px;border-radius:10px;font-size:12px;font-weight:600;background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.3);color:#fda4af}
.msg.ok{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.3);color:#6ee7b7}
.note{margin-top:14px;font-size:10.5px;color:#64748b;line-height:1.6}
</style>
</head>
<body>
<div class="card">
  <h1>Heslo admina</h1>
  <p class="sub"><?php echo htmlspecialchars($ADMIN_EMAIL); ?></p>
  <?php if ($msg): ?><div class="msg<?php echo $ok ? ' ok' : ''; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if ($ok): ?>
    <div class="note">Prihlas sa na <a href="/login" style="color:#67e8f9">/login</a> tymto e-mailom a novym heslom.<br><strong>Teraz zmaz subor admin_reset.php z hostingu.</strong></div>
  <?php else: ?>
  <form method="post" action="?key=<?php echo urlencode($KEY); ?>">
    <label for="p1">Nove heslo</label>
    <input id="p1" name="password" type="password" autocomplete="new-password" minlength="8" required>
    <label for="p2">Zopakuj heslo</label>
    <input id="p2" name="password2" type="password" autocomplete="new-password" minlength="8" required>
    <button type="submit">Nastavit heslo</button>
  </form>
  <div class="note">Po nastaveni subor z hostingu zmaz.</div>
  <?php endif; ?>
</div>
</body>
</html>
