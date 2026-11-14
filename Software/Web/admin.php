<?php
declare(strict_types=1);
/**
 * admin.php — administrácia prijatých dopytov (Elvosolar)
 * --------------------------------------------------------
 * Prihlásenie: meno + heslo z _admin_config.php (heslo je len hash).
 * Obsah: všetky dopyty, ktoré prešli cez send.php — kontaktný formulár,
 *        dopytový košík aj odber noviniek — vrátane tých, ktorým zlyhalo
 *        odoslanie e-mailu. Zapisuje ich send.php do ELVO_DATA_DIR/submissions.jsonl
 *        (predvolene _data/, na Railway pripojený Volume /data).
 *
 * Bezpečnosť: session cookie (httponly, samesite), CSRF token, limit pokusov
 * o prihlásenie, honeypot, escapovanie výstupu, žiadny JavaScript.
 */

// Jedno miesto konfigurácie: .env v koreni projektu + premenné prostredia.
require_once __DIR__ . '/_bootstrap.php';

$dataDir     = elvo_data_dir();
$savedConfig = $dataDir . '/admin_config.php';
$configFile  = elvo_admin_config_path();
$config = is_file($configFile) ? require $configFile : [];
if (!is_array($config)) { $config = []; }

// Premenné prostredia / .env majú prednosť (heslo tak nemusí byť v repozitári).
$ADMIN_USER = elvo_env('ADMIN_USER') !== '' ? elvo_env('ADMIN_USER') : (string)($config['user'] ?? 'admin');
$ADMIN_HASH = elvo_env('ADMIN_PASSWORD_HASH') !== '' ? elvo_env('ADMIN_PASSWORD_HASH') : (string)($config['hash'] ?? '');
$STORE      = $dataDir . '/submissions.jsonl';

/* ---------------- Hlavičky ---------------- */
header('Content-Type: text/html; charset=UTF-8');
header_remove('X-Powered-By');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

/* ---------------- Session ---------------- */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('elvo_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function csrf_ok(): bool
{
    $sent = (string)($_POST['csrf'] ?? '');
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $sent);
}

/* ---------------- Limit pokusov o prihlásenie ---------------- */
function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $candidate = trim(explode(',', (string)($_SERVER[$key] ?? ''))[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) { return $candidate; }
    }
    return 'unknown';
}

function attempts_file(): string
{
    return sys_get_temp_dir() . '/elvo_admin_login_' . md5(client_ip()) . '.json';
}

function attempts_state(): array
{
    $raw = @file_get_contents(attempts_file());
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state) || (int)($state['until'] ?? 0) < time()) {
        return ['count' => 0, 'until' => 0];
    }
    return ['count' => (int)($state['count'] ?? 0), 'until' => (int)$state['until']];
}

function attempts_add(): void
{
    $state = attempts_state();
    $state['count'] = $state['count'] + 1;
    $state['until'] = time() + 900;
    @file_put_contents(attempts_file(), json_encode($state), LOCK_EX);
}

function attempts_clear(): void
{
    @unlink(attempts_file());
}

/* ---------------- Dopyty ---------------- */
function store_records(string $file): array
{
    if (!is_file($file)) { return []; }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) { return []; }
    $out = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) { $out[] = $row; }
    }
    return $out;
}

function store_write(string $file, array $records): bool
{
    $dir = dirname($file);
    $tmp = @tempnam($dir, 'sub');
    if ($tmp === false) { return false; }
    $buffer = '';
    foreach ($records as $row) {
        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) { $buffer .= $line . "\n"; }
    }
    if (@file_put_contents($tmp, $buffer, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function fmt_time(string $value): string
{
    $ts = strtotime($value);
    return $ts ? date('j. n. Y H:i', $ts) : $value;
}

/* ---------------- Stav prihlásenia ---------------- */
$logged = !empty($_SESSION['admin']);
$error = '';
$notice = '';

if ($logged && time() - (int)($_SESSION['admin_time'] ?? 0) > 7200) {
    $_SESSION = [];
    $logged = false;
    $error = 'Prihlásenie vypršalo. Prihláste sa znova.';
}

/* ---------------- Spracovanie POST ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = (string)($_POST['do'] ?? '');

    if (!csrf_ok()) {
        $error = 'Neplatná požiadavka. Obnovte stránku a skúste to znova.';
    } elseif ($do === 'login') {
        $user = (string)($_POST['user'] ?? '');
        $pass = (string)($_POST['pass'] ?? '');
        $trap = trim((string)($_POST['elvo_hp'] ?? ''));

        if ($trap !== '') {
            $error = 'Prihlásenie sa nepodarilo.';
        } elseif (attempts_state()['count'] >= 5) {
            $error = 'Príliš veľa pokusov. Skúste to znova o 15 minút.';
        } elseif ($ADMIN_HASH !== '' && hash_equals($ADMIN_USER, $user) && password_verify($pass, $ADMIN_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['admin_time'] = time();
            attempts_clear();
            header('Location: admin.php', true, 303);
            exit;
        } else {
            attempts_add();
            usleep(400000);
            $error = 'Nesprávne meno alebo heslo.';
        }
    } elseif ($do === 'logout' && $logged) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: admin.php', true, 303);
        exit;
    } elseif ($do === 'delete' && $logged) {
        $id = (string)($_POST['id'] ?? '');
        $records = store_records($STORE);
        $kept = array_values(array_filter($records, static function (array $row) use ($id): bool {
            return (string)($row['id'] ?? '') !== $id;
        }));
        if ($id === '' || count($kept) === count($records)) {
            $error = 'Záznam sa nepodarilo nájsť.';
        } elseif (!store_write($STORE, $kept)) {
            $error = 'Záznam sa nepodarilo vymazať (práva na dátový priečinok).';
        } else {
            header('Location: admin.php?deleted=1', true, 303);
            exit;
        }
    } elseif ($do === 'password' && $logged) {
        $current = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $new2 = (string)($_POST['new2'] ?? '');

        if (!password_verify($current, $ADMIN_HASH)) {
            $error = 'Súčasné heslo nie je správne.';
        } elseif (strlen($new) < 10) {
            $error = 'Nové heslo musí mať aspoň 10 znakov.';
        } elseif ($new !== $new2) {
            $error = 'Nové heslá sa nezhodujú.';
        } elseif (elvo_env('ADMIN_PASSWORD_HASH') !== '') {
            $error = 'Heslo sa spravuje cez premennú ADMIN_PASSWORD_HASH, tu ho meniť netreba.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $body = "<?php\n/**\n * Prístup do administrácie (admin.php).\n * Heslo je uložené iba ako hash — z tohto súboru sa nedá zistiť.\n */\nreturn [\n    'user' => "
                . var_export($ADMIN_USER, true) . ",\n    'hash' => " . var_export($hash, true) . ",\n];\n";
            if (@file_put_contents($savedConfig, $body, LOCK_EX) === false) {
                $error = 'Heslo sa nepodarilo uložiť (dátový priečinok nie je zapisovateľný).';
            } else {
                $ADMIN_HASH = (string)$hash;
                $configFile = $savedConfig;
                $notice = 'Heslo bolo zmenené.';
            }
        }
    }
}

if (isset($_GET['deleted']) && $logged) { $notice = 'Záznam bol vymazaný.'; }

$records = $logged ? array_reverse(store_records($STORE)) : [];
$TYPES = [
    'contact'    => ['Kontaktný formulár', '#10b981'],
    'cart'       => ['Dopytový košík', '#38bdf8'],
    'newsletter' => ['Odber noviniek', '#c084fc'],
];
$today = date('Y-m-d');
$todayCount = 0;
$failedCount = 0;
foreach ($records as $row) {
    if (substr((string)($row['time'] ?? ''), 0, 10) === $today) { $todayCount++; }
    if (empty($row['sent'])) { $failedCount++; }
}
?>
<!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?= $logged ? 'Prijaté dopyty' : 'Prihlásenie' ?> — Elvosolar administrácia</title>
<style>
    :root {
        --bg: #070b14; --card: #0f172a; --card2: #121d33; --line: #1e293b;
        --text: #e6edf7; --muted: #93a4bb; --green: #10b981; --amber: #f59e0b; --red: #f43f5e;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; background: var(--bg); color: var(--text); min-height: 100vh;
        font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        background-image: radial-gradient(ellipse 70% 45% at 15% -10%, rgba(16,185,129,.16), transparent),
                          radial-gradient(ellipse 60% 40% at 90% 0%, rgba(56,189,248,.10), transparent);
    }
    a { color: var(--green); }
    .wrap { max-width: 1040px; margin: 0 auto; padding: 28px 18px 64px; }
    .top { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 26px; flex-wrap: wrap; }
    .brand { font-weight: 800; letter-spacing: -.02em; font-size: 20px; }
    .brand span { color: var(--muted); font-weight: 500; font-size: 14px; margin-left: 8px; }
    .top-actions { display: flex; gap: 10px; align-items: center; }
    .btn {
        display: inline-block; border: 1px solid var(--line); background: #16233a; color: var(--text);
        border-radius: 10px; padding: 8px 14px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none;
    }
    .btn:hover { background: #1c2c47; }
    .btn-primary { background: var(--green); border-color: var(--green); color: #04231a; }
    .btn-primary:hover { background: #0ea371; }
    .btn-danger { border-color: rgba(244,63,94,.45); color: #ffb3c0; background: rgba(244,63,94,.10); }
    .btn-danger:hover { background: rgba(244,63,94,.18); }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 22px; }
    .login { max-width: 420px; margin: 8vh auto 0; }
    .login h1 { font-size: 22px; margin: 0 0 6px; letter-spacing: -.02em; }
    .login p.hint { color: var(--muted); margin: 0 0 20px; font-size: 14px; }
    label { display: block; font-weight: 600; font-size: 13px; margin: 14px 0 6px; color: #c6d3e4; }
    input[type=text], input[type=password] {
        width: 100%; padding: 11px 13px; border-radius: 10px; border: 1px solid var(--line);
        background: #0b1424; color: var(--text); font: inherit;
    }
    input[type=text]:focus, input[type=password]:focus { outline: 2px solid rgba(16,185,129,.45); border-color: var(--green); }
    .hp { position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0; }
    .alert { border-radius: 10px; padding: 11px 14px; margin: 0 0 16px; font-size: 14px; }
    .alert.error { background: rgba(244,63,94,.12); border: 1px solid rgba(244,63,94,.4); color: #ffc2cd; }
    .alert.ok { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.4); color: #b6f5d9; }
    .stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
    .stat { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px 18px; min-width: 150px; }
    .stat b { display: block; font-size: 24px; letter-spacing: -.02em; }
    .stat span { color: var(--muted); font-size: 13px; }
    .msg { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-bottom: 14px; }
    .msg-head { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
    .badge { font-size: 12px; font-weight: 700; padding: 3px 9px; border-radius: 999px; border: 1px solid var(--line); color: #cfe0f2; }
    .badge.ok { border-color: rgba(16,185,129,.45); color: #9df0cf; background: rgba(16,185,129,.10); }
    .badge.warn { border-color: rgba(245,158,11,.45); color: #fbd99b; background: rgba(245,158,11,.10); }
    .msg-time { color: var(--muted); font-size: 13px; }
    .msg-who { font-weight: 700; }
    .msg-who a { color: #cfe0f2; }
    .msg-body { white-space: pre-wrap; word-break: break-word; margin: 10px 0 0; color: #d7e3f2; }
    .msg-meta { color: var(--muted); font-size: 12.5px; margin-top: 10px; word-break: break-all; }
    details { margin-top: 12px; }
    summary { cursor: pointer; color: var(--muted); font-size: 13px; }
    summary::marker { color: var(--green); }
    details form { margin-top: 10px; }
    .empty { text-align: center; color: var(--muted); padding: 48px 20px; }
    .footer { color: #64748b; font-size: 12.5px; margin-top: 26px; }
    .pw { display: grid; gap: 4px; max-width: 380px; }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$logged): ?>
    <div class="card login">
        <h1>Prihlásenie do administrácie</h1>
        <p class="hint">Zadajte meno a heslo. Dopyty z webu sa zobrazujú po prihlásení.</p>
        <?php if ($error !== ''): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="admin.php">
            <input type="hidden" name="do" value="login">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input class="hp" type="text" name="elvo_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label for="user">Meno</label>
            <input id="user" name="user" type="text" autocomplete="username" required autofocus>
            <label for="pass">Heslo</label>
            <input id="pass" name="pass" type="password" autocomplete="current-password" required>
            <div style="margin-top:20px"><button class="btn btn-primary" type="submit" style="width:100%">Prihlásiť sa</button></div>
        </form>
        <p class="footer"><a href="/">← Späť na web</a></p>
    </div>
<?php else: ?>
    <div class="top">
        <div class="brand">Elvosolar <span>administrácia · prijaté dopyty</span></div>
        <div class="top-actions">
            <a class="btn" href="/">Web</a>
            <form method="post" action="admin.php">
                <input type="hidden" name="do" value="logout">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button class="btn" type="submit">Odhlásiť</button>
            </form>
        </div>
    </div>

    <?php if ($error !== ''): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert ok"><?= e($notice) ?></div><?php endif; ?>

    <div class="stats">
        <div class="stat"><b><?= count($records) ?></b><span>celkom dopytov</span></div>
        <div class="stat"><b><?= $todayCount ?></b><span>dnes</span></div>
        <div class="stat"><b><?= $failedCount ?></b><span>e-mail neodoslaný</span></div>
    </div>

    <?php if (!$records): ?>
        <div class="card empty">Zatiaľ tu nie sú žiadne dopyty. Keď niekto odošle formulár, objaví sa tu.</div>
    <?php else: ?>
        <?php foreach ($records as $row):
            $type = (string)($row['action'] ?? 'contact');
            $label = $TYPES[$type][0] ?? ucfirst($type);
            $color = $TYPES[$type][1] ?? '#94a3b8';
            $sent = !empty($row['sent']);
        ?>
        <article class="msg">
            <div class="msg-head">
                <span class="badge" style="border-color: <?= e($color) ?>55; color: <?= e($color) ?>"><?= e($label) ?></span>
                <span class="badge <?= $sent ? 'ok' : 'warn' ?>"><?= $sent ? 'E-mail odoslaný' : 'E-mail neodoslaný' ?></span>
                <span class="msg-time"><?= e(fmt_time((string)($row['time'] ?? ''))) ?></span>
            </div>
            <?php if (($row['name'] ?? '') !== ''): ?><div class="msg-who"><?= e((string)$row['name']) ?></div><?php endif; ?>
            <?php if (($row['email'] ?? '') !== ''): ?>
                <div class="msg-who"><a href="mailto:<?= e((string)$row['email']) ?>"><?= e((string)$row['email']) ?></a></div>
            <?php endif; ?>

            <?php if (($row['message'] ?? '') !== ''): ?>
                <div class="msg-body"><?= e((string)$row['message']) ?></div>
            <?php endif; ?>

            <?php if (($row['total'] ?? '') !== ''): ?>
                <div class="msg-meta">Hodnota dopytu: <?= e((string)$row['total']) ?></div>
            <?php endif; ?>
            <?php if (($row['page'] ?? '') !== ''): ?>
                <div class="msg-meta">Stránka: <?= e((string)$row['page']) ?></div>
            <?php endif; ?>
            <?php if (($row['subject'] ?? '') !== ''): ?>
                <div class="msg-meta">Predmet: <?= e((string)$row['subject']) ?></div>
            <?php endif; ?>
            <div class="msg-meta">Potvrdenie klientovi: <?= !empty($row['sent_user']) ? 'odoslané' : 'neodoslané' ?></div>

            <details>
                <summary>Vymazať záznam</summary>
                <form method="post" action="admin.php">
                    <input type="hidden" name="do" value="delete">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= e((string)($row['id'] ?? '')) ?>">
                    <button class="btn btn-danger" type="submit">Naozaj vymazať</button>
                </form>
            </details>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <details class="card" style="margin-top:22px">
        <summary>Zmena hesla</summary>
        <?php if ($error !== ''): ?><div class="alert error" style="margin-top:12px"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="admin.php" class="pw">
            <input type="hidden" name="do" value="password">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label for="current">Súčasné heslo</label>
            <input id="current" name="current" type="password" autocomplete="current-password" required>
            <label for="new">Nové heslo (min. 10 znakov)</label>
            <input id="new" name="new" type="password" autocomplete="new-password" minlength="10" required>
            <label for="new2">Nové heslo znova</label>
            <input id="new2" name="new2" type="password" autocomplete="new-password" minlength="10" required>
            <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Zmeniť heslo</button></div>
        </form>
    </details>

    <p class="footer">Dopyty sa ukladajú do <code><?= e($STORE) ?></code>. Zálohu urobíte stiahnutím tohto súboru (FTP, Railway volume alebo príkaz <code>railway ssh</code>).</p>
<?php endif; ?>
</div>
</body>
</html>
