<?php use App\Core\Csrf; ?>
<div class="page-head"><div><h2>Profil &amp; Keamanan</h2><p>Kelola akses akun dan password Anda.</p></div></div>
<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><div><h3>Informasi akun</h3><p>Perubahan peran dilakukan administrator.</p></div></div>
    <div class="card-body">
      <div class="flex" style="gap:14px">
        <div class="avatar" style="width:52px;height:52px;font-size:18px"><?= e(strtoupper(substr((string) $user['name'], 0, 2))) ?></div>
        <div><div style="font-size:17px;font-weight:700"><?= e((string) $user['name']) ?></div><div class="muted small"><?= e((string) $user['email']) ?></div></div>
      </div>
      <div class="form-grid mt-2">
        <div class="field"><label>Peran</label><input class="input" value="<?= e((string) $user['role_name']) ?>" disabled></div>
        <div class="field"><label>Perusahaan</label><input class="input" value="<?= e((string) ($user['tenant_name'] ?? 'Platform')) ?>" disabled></div>
        <div class="field"><label>Outlet</label><input class="input" value="<?= e((string) ($user['outlet_name'] ?? 'Semua outlet')) ?>" disabled></div>
        <div class="field"><label>Login terakhir</label><input class="input" value="<?= e((string) ($user['last_login_at'] ?? '-')) ?>" disabled></div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Ubah password</h3><p>Minimal 8 karakter, disarankan Argon2id.</p></div></div>
    <div class="card-body">
      <form method="post" action="/profile">
        <?= Csrf::field() ?>
        <div class="field"><label>Password saat ini</label><input class="input" type="password" name="current_password" required></div>
        <div class="field"><label>Password baru</label><input class="input" type="password" name="new_password" minlength="8" required></div>
        <div class="field"><label>Konfirmasi password baru</label><input class="input" type="password" name="confirm_password" minlength="8" required></div>
        <button class="btn btn-primary" type="submit">Simpan Password</button>
      </form>
    </div>
  </div>
</div>
