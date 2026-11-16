<?php use App\Core\Csrf; ?>
<div class="page-head">
  <div><h2>Tenant</h2><p>Setiap tenant memiliki data, outlet, lisensi, dan kuota yang terisolasi.</p></div>
  <button class="btn btn-primary" type="button" data-modal-open="tenantModal"><?= icon('plus') ?> Tenant Baru</button>
</div>

<?php if (!empty($newPassword)): ?>
  <div class="alert alert-warning"><?= icon('alert') ?><div><strong>Password owner baru:</strong> <code class="mono"><?= e($newPassword) ?></code><br>Simpan dan serahkan ke owner; hanya ditampilkan sekali.</div></div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Daftar tenant</h3><p><?= count($tenants) ?> perusahaan</p></div></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Perusahaan</th><th>Paket</th><th class="num">Outlet</th><th class="num">User</th><th class="num">Order</th><th>Lisensi</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$tenants): ?><tr><td colspan="8" class="muted center" style="padding:24px">Belum ada tenant.</td></tr><?php endif; ?>
      <?php foreach ($tenants as $t): ?>
        <tr>
          <td><div class="strong"><?= e($t['name']) ?></div><div class="sub"><?= e($t['slug']) ?> · <?= e($t['email'] ?: '-') ?></div></td>
          <td><span class="badge badge-brand"><?= e($t['plan_name'] ?? 'Tanpa paket') ?></span></td>
          <td class="num"><?= (int) $t['outlets'] ?></td>
          <td class="num"><?= (int) $t['users'] ?></td>
          <td class="num"><?= number_id($t['orders']) ?></td>
          <td class="sub"><?= $t['license_expires'] ? e(dt_local($t['license_expires'], null, 'd M Y')) : '—' ?></td>
          <td><span class="badge badge-<?= badge_status($t['status']) ?>"><?= e(status_label($t['status'])) ?></span></td>
          <td class="right"><a class="btn btn-xs" href="/admin/tenants/<?= (int) $t['id'] ?>">Kelola</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop hidden" id="tenantModal">
  <div class="modal wide">
    <div class="modal-head"><div><h3>Tenant baru</h3><p>Outlet utama, register, owner, subscription, dan lisensi dibuat otomatis.</p></div></div>
    <form method="post" action="/admin/tenants">
      <?= Csrf::field() ?>
      <div class="modal-body">
        <div class="form-grid">
          <div class="field"><label>Nama perusahaan *</label><input class="input" name="name" required></div>
          <div class="field"><label>Slug *</label><input class="input" name="slug" placeholder="toko-berkah" required></div>
          <div class="field"><label>Nama owner *</label><input class="input" name="owner_name" required></div>
          <div class="field"><label>Email owner *</label><input class="input" type="email" name="owner_email" required></div>
          <div class="field full"><label>Paket *</label><select class="select" name="plan_id" required>
            <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> · <?= rupiah($p['price_monthly']) ?>/bulan</option><?php endforeach; ?>
          </select></div>
        </div>
        <div class="alert alert-info"><?= icon('alert') ?><div>Password owner dibuat otomatis dan ditampilkan setelah tenant tersimpan.</div></div>
      </div>
      <div class="modal-foot"><button class="btn" type="button" data-modal-close="tenantModal">Batal</button><button class="btn btn-primary" type="submit">Buat Tenant</button></div>
    </form>
  </div>
</div>
