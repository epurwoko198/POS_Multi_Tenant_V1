<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\License;

$user = Auth::user();
$ctx = License::context();
$flashes = consume_flash();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title>POS Kasir · <?= e((string) ($ctx['tenant']['name'] ?? 'Berkah POS')) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="pos-layout">
  <header class="pos-top">
    <div class="flex" style="gap:18px">
      <div class="brand">
        <div class="brand-mark">B</div>
        <div class="brand-text"><strong style="font-size:13px"><?= e((string) ($ctx['tenant']['name'] ?? 'Berkah POS')) ?></strong><span><?= e((string) ($user['outlet_name'] ?? 'Outlet')) ?></span></div>
      </div>
      <div class="pos-meta hide-sm"><div><span>Kasir</span><strong><?= e((string) $user['name']) ?></strong></div></div>
      <div class="pos-meta hide-sm"><div><span>Shift</span><strong><?= $shift ? 'Terbuka' : 'Belum dibuka' ?></strong></div></div>
    </div>
    <div class="flex" style="gap:14px">
      <div class="clock" data-clock>--:--:--</div>
      <a href="/dashboard" class="btn btn-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0">Dashboard</a>
      <a href="/cash" class="btn btn-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0">Shift</a>
      <button class="btn btn-sm" type="button" style="background:#1e293b;border-color:#334155;color:#e2e8f0" onclick="document.getElementById('cartPanel').classList.toggle('open')">Keranjang</button>
      <form method="post" action="/logout"><?= Csrf::field() ?><button class="btn btn-sm btn-icon" style="background:#1e293b;border-color:#334155;color:#e2e8f0" title="Keluar"><?= icon('logout') ?></button></form>
    </div>
  </header>
  <?= $content ?>
</div>
<script src="/assets/js/app.js"></script>
<?php if ($flashes): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  <?php foreach ($flashes as $f): ?>
    App.toast(<?= json_encode($f['message'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($f['type'] === 'success' ? 'success' : ($f['type'] === 'error' ? 'error' : 'warning')) ?>, 6000);
  <?php endforeach; ?>
});
</script>
<?php endif; ?>
<?= $scripts ?? '' ?>
</body>
</html>
