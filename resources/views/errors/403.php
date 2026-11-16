<div class="page-head"><div><h2>Akses ditolak</h2><p><?= e($message ?? 'Anda tidak memiliki izin untuk membuka halaman ini.') ?></p></div></div>
<div class="card"><div class="card-body">
  <div class="empty">
    <?= icon('lock') ?>
    <h4>Peran Anda belum memiliki izin</h4>
    <p>Hubungi owner atau administrator perusahaan untuk menyesuaikan peran Anda.</p>
    <a href="/dashboard" class="btn btn-primary mt-2">Kembali ke dashboard</a>
  </div>
</div></div>
