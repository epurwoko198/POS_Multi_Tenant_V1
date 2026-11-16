<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\License;

$user = Auth::user();
$ctx = License::context();
$title = $title ?? 'Dashboard';
$flashes = consume_flash();
$initials = '';
foreach (array_slice(preg_split('/\s+/', (string) $user['name']) ?: [], 0, 2) as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
$licenseLabel = strtoupper((string) ($ctx['plan']['name'] ?? 'TANPA PAKET'));
$expires = $ctx['expires_at'] ? dt_local($ctx['expires_at'], null, 'd M Y') : '-';
$licenseNotice = \App\Core\License::expiryNotice();
$showLicenseNotice = in_array($licenseNotice['level'], ['expired', 'critical', 'warning'], true);
$navItem = static function (string $href, string $label, string $iconName, bool $enabled, string $active = ''): string {
    if (!$enabled) {
        return '<a class="nav-link disabled" href="/license" title="Aktifkan modul ini melalui lisensi"><i style="display:inline-flex;width:17px">' . icon($iconName) . '</i>' . e($label) . '<span class="nav-lock">PRO</span></a>';
    }
    return '<a class="nav-link ' . $active . '" href="' . $href . '"><i style="display:inline-flex;width:17px">' . icon($iconName) . '</i>' . e($label) . '</a>';
};
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title><?= e($title) ?> · <?= e((string) ($ctx['tenant']['name'] ?? 'Berkah POS')) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/vendor/chart.umd.min.js"></script>
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">B</div>
      <div class="brand-text"><strong><?= e(\App\Core\Config::get('APP_NAME', 'Berkah POS')) ?></strong><span>SaaS Platform</span></div>
    </div>
    <nav class="nav">
      <div class="nav-section">Operasional</div>
      <?= $navItem('/dashboard', 'Dashboard', 'dashboard', true, nav_active('/dashboard')) ?>
      <?php $onPosDashboard = str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/pos/dashboard'); ?>
      <?= $navItem('/pos/dashboard', 'Dashboard POS', 'pos', License::enabled('pos'), nav_active('/pos/dashboard')) ?>
      <?= $navItem('/pos', 'POS Kasir', 'pos', License::enabled('pos'), $onPosDashboard ? '' : nav_active('/pos')) ?>
      <?= $navItem('/cash', 'Kas & Shift', 'cash', License::enabled('pos'), nav_active('/cash')) ?>
      <?= $navItem('/kds', 'Kitchen Display', 'kitchen', License::enabled('kds'), nav_active('/kds')) ?>
      <?= $navItem('/restaurant', 'Meja Restoran', 'table', License::enabled('restaurant'), nav_active('/restaurant')) ?>

      <div class="nav-section">Katalog & Stok</div>
      <?= $navItem('/catalog', 'Produk', 'box', License::enabled('catalog'), nav_active('/catalog')) ?>
      <?= $navItem('/inventory', 'Inventori', 'layers', License::enabled('inventory'), nav_active('/inventory')) ?>
      <?= $navItem('/purchasing', 'Pembelian', 'truck', License::enabled('purchasing'), nav_active('/purchasing')) ?>

      <div class="nav-section">Pelanggan</div>
      <?= $navItem('/crm', 'CRM & Loyalty', 'users', License::enabled('crm'), nav_active('/crm')) ?>

      <div class="nav-section">Analitik</div>
      <?= $navItem('/reports', 'Laporan', 'chart', License::enabled('reports'), nav_active('/reports')) ?>
      <?= $navItem('/ai', 'AI Analytics', 'sparkles', License::enabled('ai'), nav_active('/ai')) ?>

      <div class="nav-section">Sistem</div>
      <?= $navItem('/users', 'Pengguna', 'shield', Auth::can('users.manage'), nav_active('/users')) ?>
      <?= $navItem('/license', 'Lisensi', 'key', true, nav_active('/license')) ?>
      <?= $navItem('/settings', 'Pengaturan', 'settings', Auth::can('settings.manage'), nav_active('/settings')) ?>
    </nav>
    <div class="sidebar-foot">
      <div class="plan-card">
        <div class="label">Paket aktif</div>
        <strong><?= e((string) ($ctx['plan']['name'] ?? 'Tanpa paket')) ?></strong>
        <div class="expire">Berlaku s/d <?= e($expires) ?></div>
        <a href="/license">Kelola lisensi & modul →</a>
        <?php if ($showLicenseNotice): ?>
          <div class="expire" style="color:#fbbf24;margin-top:6px"><?= e($licenseNotice['message']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="flex">
        <button class="btn btn-ghost btn-icon menu-toggle" type="button" aria-label="Menu"><?= icon('menu') ?></button>
        <div>
          <h1><?= e($title) ?></h1>
          <p><?= e((string) ($ctx['tenant']['name'] ?? '')) ?> · <?= e((string) ($user['outlet_name'] ?? 'Semua outlet')) ?></p>
        </div>
      </div>
      <div class="topbar-actions">
        <?php if ($showLicenseNotice): ?>
          <a href="/license" class="notif-pill notif-<?= e($licenseNotice['level']) ?>" title="<?= e($licenseNotice['message']) ?>">
            <?= icon('alert') ?><span><?= $licenseNotice['level'] === 'expired' ? 'Lisensi berakhir' : ($licenseNotice['days_left'] <= 0 ? 'Hari ini' : (int) $licenseNotice['days_left'] . ' hari') ?></span>
          </a>
        <?php endif; ?>
        <?php if (License::enabled('pos') && Auth::can('pos.sell')): ?>
          <a href="/pos" class="btn btn-primary btn-sm"><?= icon('pos') ?> Buka Kasir</a>
        <?php endif; ?>
        <div class="user-chip">
          <div class="avatar"><?= e($initials ?: 'U') ?></div>
          <div><div class="name"><?= e((string) $user['name']) ?></div><div class="role"><?= e((string) $user['role_name']) ?></div></div>
        </div>
        <form method="post" action="/logout"><?= Csrf::field() ?><button class="btn btn-ghost btn-icon" title="Keluar"><?= icon('logout') ?></button></form>
      </div>
    </header>
    <main class="content">
      <?= $content ?>
    </main>
  </div>
</div>
<script src="/assets/js/app.js"></script>
<?php if ($flashes): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  <?php foreach ($flashes as $f): ?>
    App.toast(<?= json_encode($f['message'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($f['type'] === 'success' ? 'success' : ($f['type'] === 'error' ? 'error' : ($f['type'] === 'warning' ? 'warning' : 'info'))) ?>, 5200);
  <?php endforeach; ?>
});
</script>
<?php endif; ?>
<?= $scripts ?? '' ?>
</body>
</html>
