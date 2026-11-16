<?php
use App\Core\Csrf;

$totalSpend = 0.0;
foreach ($customers as $c) $totalSpend += (float) $c['total_spent'];
?>
<div class="page-head">
  <div><h2>Pelanggan &amp; Loyalty</h2><p>Profil pelanggan, poin, tier, dan riwayat transaksi.</p></div>
  <button class="btn btn-primary" type="button" onclick="openCustomer()"><?= icon('plus') ?> Pelanggan</button>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
  <div class="stat"><div class="label">Total pelanggan</div><div class="value"><?= number_id(count($customers)) ?></div><div class="sub">Terdaftar pada perusahaan</div><div class="icon"><?= icon('users') ?></div></div>
  <div class="stat"><div class="label">Total belanja terdaftar</div><div class="value"><?= rupiah($totalSpend) ?></div><div class="sub">Akumulasi lifetime</div><div class="icon emerald"><?= icon('cash') ?></div></div>
  <div class="stat"><div class="label">Poin loyalty</div><div class="value"><?= $loyaltyEnabled ? 'Aktif' : 'Nonaktif' ?></div><div class="sub"><?= $loyaltyEnabled ? '1 poin per Rp 1.000' : 'Aktifkan modul loyalty' ?></div><div class="icon amber"><?= icon('sparkles') ?></div></div>
</div>

<div class="card">
  <div class="card-head"><div><h3>Daftar pelanggan</h3><p>Diurutkan berdasarkan total belanja</p></div><span class="chip"><?= count($customers) ?> pelanggan</span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nama</th><th>Kontak</th><th>Tier</th><th class="num">Transaksi</th><th class="num">Total belanja</th><th class="num">Poin</th><th></th></tr></thead>
      <tbody>
      <?php if (!$customers): ?><tr><td colspan="7"><div class="empty"><h4>Belum ada pelanggan</h4><p>Tambahkan pelanggan untuk mengaktifkan poin dan riwayat belanja.</p></div></td></tr><?php endif; ?>
      <?php foreach ($customers as $c): ?>
        <tr>
          <td><div class="strong"><?= e($c['name']) ?></div><div class="sub"><?= e(date_local($c['created_at'])) ?></div></td>
          <td><?= e($c['phone'] ?: '-') ?><div class="sub"><?= e($c['email'] ?: '') ?></div></td>
          <td><span class="badge badge-<?= $c['tier'] === 'gold' ? 'warning' : ($c['tier'] === 'silver' ? 'info' : 'muted') ?>"><?= e(status_label($c['tier'])) ?></span></td>
          <td class="num"><?= number_id($c['orders']) ?></td>
          <td class="num strong"><?= rupiah($c['total_spent']) ?></td>
          <td class="num"><?= number_id($c['points']) ?></td>
          <td class="right"><button class="btn btn-sm" type="button" data-customer='<?= e(json_encode($c, JSON_UNESCAPED_UNICODE)) ?>' onclick="openCustomer(this)">Edit</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($loyaltyEnabled): ?>
<div class="card">
  <div class="card-head"><div><h3>Riwayat poin</h3><p>30 transaksi loyalty terakhir</p></div></div>
  <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Waktu</th><th>Pelanggan</th><th>Alasan</th><th class="num">Poin</th></tr></thead>
      <tbody>
      <?php if (!$loyalty): ?><tr><td colspan="4"><div class="empty" style="padding:24px"><p>Belum ada transaksi poin.</p></div></td></tr><?php endif; ?>
      <?php foreach ($loyalty as $l): ?>
        <tr><td class="sub"><?= e(dt_local($l['created_at'])) ?></td><td class="strong"><?= e($l['customer_name']) ?></td><td><?= e($l['reason']) ?></td><td class="num <?= (int) $l['points_delta'] >= 0 ? 'delta-up' : 'delta-down' ?>"><?= ((int) $l['points_delta'] > 0 ? '+' : '') . number_id($l['points_delta']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="modal-backdrop hidden" id="customerModal">
  <div class="modal">
    <div class="modal-head"><div><h3 id="customerTitle">Pelanggan Baru</h3><p>Data kontak digunakan untuk nota digital dan campaign.</p></div></div>
    <form method="post" action="/crm/customer">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="customerId" value="">
      <div class="modal-body">
        <div class="form-grid">
          <div class="field full"><label>Nama *</label><input class="input" name="name" id="cName" required></div>
          <div class="field"><label>Telepon</label><input class="input" name="phone" id="cPhone"></div>
          <div class="field"><label>Email</label><input class="input" type="email" name="email" id="cEmail"></div>
          <div class="field full"><label>Catatan</label><textarea class="textarea" name="notes" id="cNotes"></textarea></div>
        </div>
      </div>
      <div class="modal-foot"><button class="btn" type="button" data-modal-close="customerModal">Batal</button><button class="btn btn-primary" type="submit">Simpan</button></div>
    </form>
  </div>
</div>

<script>
function openCustomer(btn) {
  const form = document.querySelector('#customerModal form');
  form.reset();
  document.getElementById('customerId').value = '';
  document.getElementById('customerTitle').textContent = 'Pelanggan Baru';
  if (btn && btn.dataset.customer) {
    const c = JSON.parse(btn.dataset.customer);
    document.getElementById('customerTitle').textContent = 'Edit ' + c.name;
    document.getElementById('customerId').value = c.id;
    document.getElementById('cName').value = c.name;
    document.getElementById('cPhone').value = c.phone || '';
    document.getElementById('cEmail').value = c.email || '';
    document.getElementById('cNotes').value = c.notes || '';
  }
  App.openModal('customerModal');
}
</script>
