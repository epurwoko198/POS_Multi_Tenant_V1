<?php
use App\Core\Csrf;
$appName = (string) \App\Core\Config::get('APP_NAME', 'Berkah POS');
?>
<div class="auth-page">
  <section class="auth-art">
    <div class="brand">
      <div class="brand-mark">B</div>
      <div class="brand-text"><strong><?= e($appName) ?></strong><span>SaaS Platform</span></div>
    </div>
    <h2>Platform POS multi-outlet untuk retail, waserba &amp; restoran.</h2>
    <p class="lead">Satu tenant, banyak outlet. Transaksi cepat, stok konsisten, pembayaran terverifikasi, dan analitik AI yang membantu keputusan harian.</p>
    <div class="auth-features">
      <div class="auth-feature"><span class="tick"><?= icon('check') ?></span><div><strong>Multi-tenant &amp; lisensi modular</strong><br>Aktifkan modul sesuai paket: POS, restoran, KDS, CRM, API, AI.</div></div>
      <div class="auth-feature"><span class="tick"><?= icon('check') ?></span><div><strong>Pembayaran aman</strong><br>QRIS dinamis, webhook tervalidasi, nota hanya terbit setelah lunas.</div></div>
      <div class="auth-feature"><span class="tick"><?= icon('check') ?></span><div><strong>AI analytics lokal</strong><br>Forecast, anomali, reorder stok, RFM, dan market basket tanpa cloud.</div></div>
      <div class="auth-feature"><span class="tick"><?= icon('check') ?></span><div><strong>Ledger &amp; audit</strong><br>Stok append-only, jejak audit, dan rekonsiliasi pembayaran.</div></div>
    </div>
    <div class="auth-foot">PHP <?= PHP_VERSION ?> · MySQLi · Python Analytics · Chart.js<br>© <?= date('Y') ?> <?= e($appName) ?>. All rights reserved.</div>
  </section>

  <section class="auth-form-wrap">
    <div class="auth-form">
      <h1>Masuk ke dashboard</h1>
      <p class="sub">Gunakan akun perusahaan Anda. Sesi dilindungi CSRF dan rate limiting.</p>
      <form method="post" action="/login" autocomplete="on">
        <?= Csrf::field() ?>
        <div class="field">
          <label for="email">Email</label>
          <input class="input" type="email" id="email" name="email" value="<?= e(old('email')) ?>" placeholder="nama@perusahaan.id" required autofocus>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input class="input" type="password" id="password" name="password" placeholder="••••••••" required>
        </div>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Masuk</button>
      </form>

      <div class="demo-accounts">
        <div class="title">Akun demo</div>
        <div class="demo-account" data-demo-email="owner@demo.id" data-demo-password="Demo#12345">
          <span><strong>Owner</strong> · toko demo</span><code>owner@demo.id</code>
        </div>
        <div class="demo-account" data-demo-email="kasir@demo.id" data-demo-password="Demo#12345">
          <span><strong>Kasir</strong> · outlet pusat</span><code>kasir@demo.id</code>
        </div>
        <div class="demo-account" data-demo-email="admin@possaas.id" data-demo-password="Admin#12345">
          <span><strong>Super Admin</strong> · platform</span><code>admin@possaas.id</code>
        </div>
        <div class="small muted mt-1">Password demo: <span class="mono">Demo#12345</span> · Super admin: <span class="mono">Admin#12345</span></div>
      </div>
    </div>
  </section>
</div>
<script>
document.querySelectorAll('.demo-account').forEach(function (row) {
  row.addEventListener('click', function () {
    document.getElementById('email').value = row.dataset.demoEmail;
    document.getElementById('password').value = row.dataset.demoPassword;
    document.getElementById('password').focus();
  });
});
</script>
