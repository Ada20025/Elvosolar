<?php
/**
 * MAPA WEBU — Elvosolar prehliadač stránok www.elvosolar.sk
 * Automaticky nájde všetky stránky (index.html) v celej štruktúre.
 * Nahraj na alwaysdata do koreňa webu → otvor /mapa-webu.php
 */
header('Content-Type: text/html; charset=utf-8');

$root = __DIR__;

// --- 1. Automatické nájdenie všetkých stránok ---
$pages = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getFilename() !== 'index.html') continue;
    $dir = str_replace('\\', '/', dirname($file->getPathname()));
    $rel = ltrim(str_replace($root, '', $dir), '/');
    $title = 'Bez názvu';
    $html = @file_get_contents($file->getPathname());
    if ($html && preg_match('#<title[^>]*>([^<]+)</title>#i', $html, $m)) {
        $title = trim(html_entity_decode($m[1]));
        $title = preg_replace('/\s*\|\s*ELVOSOLAR.*$/i', '', $title);
        $title = preg_replace('/\s*–\s*ELVOSOLAR.*$/i', '', $title);
    }
    $desc = '';
    if ($html && preg_match('#<meta\s+name="description"\s+content="([^"]*)"#i', $html, $m2)) {
        $desc = mb_substr(trim(html_entity_decode($m2[1])), 0, 110);
    }
    $depth = ($rel === '' ? 0 : substr_count($rel, '/') + 1);
    $pages[] = [
        'url'   => ($rel === '' ? './' : '/' . rawurlencode($rel) . '/'),
        'path'  => ($rel === '' ? '(koreň webu)' : $rel . '/'),
        'title' => ($title !== '' ? $title : basename($rel)),
        'desc'  => $desc,
        'depth' => $depth,
    ];
}
usort($pages, fn($a, $b) => [$a['depth'], $a['path']] <=> [$b['depth'], $b['path']]);

$icons = ['🏠', '⚡', '🔋', '☀️', '🌡️', '🏭', '🛒', '📞', 'ℹ️', '🌱', '🔧', '💡'];
$accent = ['#10b981', '#06b6d4', '#818cf8', '#f59e0b', '#f43f5e', '#22c55e'];
?>
<!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mapa webu — ELVOSOLAR</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  background:#04060d; color:#e5ecf5; min-height:100vh; overflow-x:hidden;
  background-image:
    radial-gradient(ellipse 80% 50% at 20% -10%, rgba(16,185,129,.13), transparent),
    radial-gradient(ellipse 70% 45% at 85% 10%, rgba(6,182,212,.10), transparent),
    radial-gradient(ellipse 60% 40% at 50% 110%, rgba(129,140,248,.08), transparent);
}
.wrap { max-width:1060px; margin:0 auto; padding:56px 20px 80px; }
header { text-align:center; margin-bottom:52px; }
.logo { font-size:13px; font-weight:800; letter-spacing:5px; color:#34d399; text-transform:uppercase; margin-bottom:14px; font-family:Consolas,monospace; }
h1 { font-size:clamp(30px,5vw,46px); font-weight:900; letter-spacing:-.02em; background:linear-gradient(90deg,#fff,#a7f3d0 55%,#67e8f9); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; animation:shine 6s ease-in-out infinite; }
@keyframes shine { 0%,100%{filter:brightness(1)} 50%{filter:brightness(1.25)} }
.sub { color:#8fa3bd; margin-top:12px; font-size:15px; }
.count { display:inline-flex; align-items:center; gap:8px; margin-top:22px; padding:9px 20px; border-radius:999px; border:1px solid rgba(52,211,153,.35); background:rgba(16,185,129,.10); color:#34d399; font-size:13px; font-weight:700; }
.count b { font-size:16px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:18px; }
.card {
  position:relative; display:block; text-decoration:none; color:inherit;
  background:rgba(255,255,255,.035); border:1px solid rgba(255,255,255,.09);
  border-radius:18px; padding:22px; overflow:hidden;
  transition:transform .35s cubic-bezier(.2,.9,.3,1.2), border-color .35s, box-shadow .35s, background .35s;
  opacity:0; transform:translateY(26px) scale(.97);
}
.card.show { animation:cardIn .6s cubic-bezier(.2,.9,.3,1.15) forwards; }
@keyframes cardIn { to { opacity:1; transform:translateY(0) scale(1); } }
.card:hover {
  transform:translateY(-7px) scale(1.02); border-color:rgba(52,211,153,.55);
  background:rgba(16,185,129,.07); box-shadow:0 22px 50px rgba(0,0,0,.55), 0 0 0 1px rgba(52,211,153,.18);
}
.card::before {
  content:""; position:absolute; inset:0;
  background:linear-gradient(120deg, transparent 30%, rgba(255,255,255,.07) 48%, transparent 62%);
  background-size:250% 100%; background-position:-120% 0; transition:background-position .9s ease; pointer-events:none;
}
.card:hover::before { background-position:120% 0; }
.card .glow { position:absolute; width:130px; height:130px; border-radius:50%; top:-45px; right:-45px; filter:blur(42px); opacity:.30; transition:opacity .4s; }
.card:hover .glow { opacity:.65; }
.icon { width:52px; height:52px; border-radius:15px; display:flex; align-items:center; justify-content:center; font-size:26px; margin-bottom:15px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.10); transition:transform .35s; }
.card:hover .icon { transform:scale(1.12) rotate(-6deg); }
.title { font-size:17px; font-weight:800; color:#fff; margin-bottom:7px; letter-spacing:-.01em; }
.desc { font-size:13px; color:#8fa3bd; line-height:1.6; margin-bottom:14px; min-height:38px; }
.path { font-family:Consolas,monospace; font-size:11.5px; color:#67e8f9; background:rgba(6,182,212,.09); border:1px solid rgba(6,182,212,.22); padding:5px 11px; border-radius:8px; display:inline-block; }
.open { position:absolute; top:20px; right:20px; font-size:19px; color:#34d399; opacity:0; transform:translate(-8px,8px); transition:all .35s; }
.card:hover .open { opacity:1; transform:translate(0,0); }
.depth-tag { position:absolute; top:22px; right:46px; font-size:10px; color:#8fa3bd; border:1px solid rgba(255,255,255,.14); border-radius:6px; padding:3px 7px; }
footer { text-align:center; margin-top:60px; color:#55637a; font-size:12px; }
footer a { color:#34d399; text-decoration:none; }
@media (max-width:560px){ .grid{grid-template-columns:1fr;} .card{padding:18px;} }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo">Elvosolar &middot; Smart EMS</div>
    <h1>Mapa webu</h1>
    <p class="sub">Všetky stránky www.elvosolar.sk na jednom mieste — klikni a pozeraj</p>
    <div class="count">📄 <b><?= count($pages) ?></b> stránok nájdených</div>
  </header>

  <div class="grid">
  <?php $base = 'https://www.elvosolar.sk';
  foreach ($pages as $i => $p):
      $icon  = $icons[$i % count($icons)];
      $color = $accent[$i % count($accent)];
  ?>
    <a class="card" href="<?= htmlspecialchars($base . $p['url']) ?>" style="animation-delay:<?= $i * 60 ?>ms">
      <div class="glow" style="background:<?= $color ?>"></div>
      <div class="open">→</div>
      <div class="icon"><?= $icon ?></div>
      <div class="title"><?= htmlspecialchars($p['title']) ?></div>
      <?php if ($p['desc']): ?><div class="desc"><?= htmlspecialchars($p['desc']) ?>…</div><?php else: ?><div class="desc"></div><?php endif; ?>
      <span class="path"><?= htmlspecialchars($p['path']) ?></span>
      <span class="depth-tag">L<?= $p['depth'] ?></span>
    </a>
  <?php endforeach; ?>
  </div>

  <footer>Vygenerované automaticky z štruktúry súborov · <a href="https://www.elvosolar.sk">www.elvosolar.sk</a></footer>
</div>
<script>
// plynulé objavovanie kariet
requestAnimationFrame(() => {
  document.querySelectorAll('.card').forEach(c => c.classList.add('show'));
});
</script>
</body>
</html>
