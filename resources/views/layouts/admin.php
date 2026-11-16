<?php
use App\Core\Auth;
use App\Core\Csrf;

$user = Auth::user();
$title = $title ?? 'Platform';
$flashes = consume_flash();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title><?= e($title) ?> · SaaS Console</title>
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/vendor/chart.umd.min.js"></script>
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark" style="background:linear-gradient(135deg,#0ea5e9,#6366f1)">S</div>
      <div class="brand-text"><strong><?= e(\App\Core\Config::get('APP_NAME', 'Berkah POS')) ?></strong><span>SaaS Console</span></div>
    </div>
    <nav class="nav">
      <div class="nav-section">Platform</div>
      <a class="nav-link <?= nav_active('/admin') === 'active' && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/admin' ? 'active' : '' ?>" href="/admin"><?= icon('dashboard') ?> Overview</a>
      <a class="nav-link <?= nav_active('/admin/tenants') ?>" href="/admin/tenants"><?= icon('building') ?> Tenant</a>
      <a class="nav-link <?= nav_active('/admin/plans') ?>" href="/admin/plans"><?= icon('layers') ?> Paket & Modul</a>
      <a class="nav-link <?= nav_active('/admin/licenses') ?>" href="/admin/licenses"><?= icon('key') ?> Lisensi</a>
      <a class="nav-link <?= nav_active('/admin/invoices') ?>" href="/admin/invoices"><?= icon('card') ?> Invoice SaaS</a>
      <a class="nav-link <?= nav_active('/admin/mail') ?>" href="/admin/mail"><?= icon('mail') ?> Email & SMTP</a>
      <a class="nav-link <?= nav_active('/admin/audit') ?>" href="/admin/audit"><?= icon('shield') ?> Audit</a>
      <div class="nav-section">Tenant</div>
      <a class="nav-link" href="/dashboard"><?= icon('pos') ?> Masuk Dashboard</a>
    </nav>
    <div class="sidebar-foot">
      <div class="plan-card">
        <div class="label">Mode</div>
        <strong>Super Admin</strong>
        <div class="expire">Akses lintas tenant terbatas</div>
      </div>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <div class="flex">
        <button class="btn btn-ghost btn-icon menu-toggle" type="button"><?= icon('menu') ?></button>
        <div><h1><?= e($title) ?></h1><p>SaaS licensing & operations console</p></div>
      </div>
      <div class="topbar-actions">
        <div class="user-chip">
          <div class="avatar" style="background:linear-gradient(135deg,#0ea5e9,#6366f1)">SA</div>
          <div><div class="name"><?= e((string) $user['name']) ?></div><div class="role">Super Admin</div></div>
        </div>
        <form method="post" action="/logout"><?= Csrf::field() ?><button class="btn btn-ghost btn-icon" title="Keluar"><?= icon('logout') ?></button></form>
      </div>
    </header>
    <main class="content"><?= $content ?></main>
  </div>
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
