<div class="auth-form-wrap" style="min-height:100vh">
  <div class="auth-form center">
    <div class="brand-mark" style="margin:0 auto 16px;width:52px;height:52px;font-size:22px;background:linear-gradient(135deg,#f43f5e,#f59e0b)">!</div>
    <h1>Terjadi kesalahan</h1>
    <p class="sub">Permintaan tidak dapat diproses. Tim operasional dapat memeriksa log server.</p>
    <?php if (!empty($detail)): ?>
      <div class="alert alert-error" style="text-align:left"><code style="font-size:11px;word-break:break-all"><?= e($detail) ?></code></div>
    <?php endif; ?>
    <a href="/dashboard" class="btn btn-primary">Kembali</a>
  </div>
</div>
