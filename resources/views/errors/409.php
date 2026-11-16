<div class="auth-form-wrap" style="min-height:100vh">
  <div class="auth-form center">
    <div class="brand-mark" style="margin:0 auto 16px;width:52px;height:52px;font-size:22px">!</div>
    <h1><?= e($title ?? 'Nota belum tersedia') ?></h1>
    <p class="sub"><?= e($message ?? 'Nota final hanya diterbitkan setelah pembayaran terverifikasi lunas.') ?></p>
    <a href="/pos" class="btn btn-primary">Kembali ke kasir</a>
  </div>
</div>
