<?php
use App\Core\Csrf;

$lowCount = 0;
foreach ($balances as $b) {
    if ((float) $b['qty'] <= (float) $b['reorder_point']) $lowCount++;
}
$totalQty = 0.0;
foreach ($balances as $b) $totalQty += (float) $b['qty'];
?>
<div class="page-head">
  <div><h2>Inventori</h2><p>Saldo stok materialized + ledger append-only untuk audit.</p></div>
  <div class="flex">
    <?php if ($multiOutlet): ?><button class="btn" type="button" data-modal-open="transferModal"><?= icon('truck') ?> Transfer</button><?php endif; ?>
    <button class="btn btn-primary" type="button" data-modal-open="adjustModal"><?= icon('plus') ?> Penyesuaian</button>
  </div>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
  <div class="stat"><div class="label">Nilai stok (HPP)</div><div class="value"><?= rupiah($totalValue) ?></div><div class="sub">Outlet aktif</div><div class="icon emerald"><?= icon('layers') ?></div></div>
  <div class="stat"><div class="label">SKU dilacak</div><div class="value"><?= number_id(count($balances)) ?></div><div class="sub"><?= number_id($totalQty, 0) ?> total unit</div><div class="icon"><?= icon('box') ?></div></div>
  <div class="stat"><div class="label">Stok kritis</div><div class="value"><?= number_id($lowCount) ?></div><div class="sub">≤ reorder point</div><div class="icon amber"><?= icon('alert') ?></div></div>
  <div class="stat"><div class="label">Transfer terakhir</div><div class="value"><?= number_id(count($transfers)) ?></div><div class="sub">15 transaksi terakhir</div><div class="icon sky"><?= icon('truck') ?></div></div>
</div>

<div class="grid grid-2-1">
  <div class="card">
    <div class="card-head"><div><h3>Saldo stok</h3><p>Per outlet aktif · diurutkan dari yang kritis</p></div></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Produk</th><th>SKU</th><th class="num">Stok</th><th class="num">Reorder</th><th class="num">Nilai</th><th></th></tr></thead>
        <tbody>
        <?php if (!$balances): ?><tr><td colspan="6"><div class="empty" style="padding:26px"><p>Belum ada produk dengan pelacakan stok.</p></div></td></tr><?php endif; ?>
        <?php foreach ($balances as $b): ?>
          <tr>
            <td><div class="strong"><?= e($b['name']) ?></div><?php if ((float) $b['qty'] <= (float) $b['reorder_point']): ?><span class="badge badge-warning mt-1">Perlu reorder</span><?php endif; ?></td>
            <td class="mono sub"><?= e($b['sku']) ?></td>
            <td class="num strong"><?= number_id($b['qty'], 0) ?> <?= e($b['unit']) ?></td>
            <td class="num sub"><?= number_id($b['reorder_point'], 0) ?></td>
            <td class="num"><?= rupiah($b['value']) ?></td>
            <td class="right"><button class="btn btn-sm" type="button" data-adjust='<?= e(json_encode($b, JSON_UNESCAPED_UNICODE)) ?>' onclick="openAdjust(this)">Sesuaikan</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Ledger terbaru</h3><p>60 pergerakan stok terakhir</p></div></div>
    <div class="table-wrap" style="max-height:520px;overflow-y:auto">
      <table class="table compact">
        <thead><tr><th>Waktu</th><th>Produk</th><th>Delta</th><th>Sumber</th></tr></thead>
        <tbody>
        <?php if (!$ledger): ?><tr><td colspan="4"><div class="empty" style="padding:24px"><p>Belum ada pergerakan stok.</p></div></td></tr><?php endif; ?>
        <?php foreach ($ledger as $l): ?>
          <tr>
            <td class="sub nowrap"><?= e(dt_local($l['created_at'], null, 'd M H:i')) ?></td>
            <td><div class="strong" style="font-size:12px"><?= e($l['name']) ?></div><div class="sub"><?= e($l['source_type']) ?> · <?= e($l['user_name'] ?: 'sistem') ?></div></td>
            <td class="num <?= (float) $l['qty_delta'] >= 0 ? 'delta-up' : 'delta-down' ?> nowrap"><?= ((float) $l['qty_delta'] > 0 ? '+' : '') . number_id($l['qty_delta'], 2) ?></td>
            <td class="sub"><?= e($l['note'] ?: '-') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head"><div><h3>Riwayat transfer</h3><p>Perpindahan stok antar outlet</p></div></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nomor</th><th>Dari</th><th>Ke</th><th>Oleh</th><th>Waktu</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$transfers): ?><tr><td colspan="6"><div class="empty" style="padding:24px"><p>Belum ada transfer stok.</p></div></td></tr><?php endif; ?>
      <?php foreach ($transfers as $t): ?>
        <tr><td class="mono strong"><?= e($t['number']) ?></td><td><?= e($t['from_name']) ?></td><td><?= e($t['to_name']) ?></td><td><?= e($t['user_name'] ?: '-') ?></td><td class="sub"><?= e(dt_local($t['created_at'])) ?></td><td><span class="badge badge-<?= badge_status($t['status']) ?>"><?= e(status_label($t['status'])) ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop hidden" id="adjustModal">
  <div class="modal">
    <div class="modal-head"><div><h3>Penyesuaian stok</h3><p id="adjustSub">Koreksi stok dengan jejak audit.</p></div></div>
    <form method="post" action="/inventory/adjust">
      <?= Csrf::field() ?>
      <input type="hidden" name="product_id" id="adjustProduct">
      <div class="modal-body">
        <div class="form-grid">
          <div class="field full"><label>Produk</label><select class="select" id="adjustProductSelect" onchange="document.getElementById('adjustProduct').value=this.value">
            <?php foreach ($balances as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?> · <?= number_id($b['qty'], 0) ?> <?= e($b['unit']) ?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Outlet</label><select class="select" name="outlet_id">
            <?php foreach ($outlets as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) $o['id'] === $outletId ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Delta qty</label><input class="input" type="number" name="qty_delta" step="0.01" required placeholder="mis. -2 atau 10"><div class="hint">Gunakan nilai negatif untuk mengurangi.</div></div>
          <div class="field full"><label>Alasan / catatan</label><textarea class="textarea" name="note" placeholder="Contoh: stock opname, barang rusak, koreksi input"></textarea></div>
        </div>
      </div>
      <div class="modal-foot"><button class="btn" type="button" data-modal-close="adjustModal">Batal</button><button class="btn btn-primary" type="submit">Simpan Penyesuaian</button></div>
    </form>
  </div>
</div>

<?php if ($multiOutlet): ?>
<div class="modal-backdrop hidden" id="transferModal">
  <div class="modal wide">
    <div class="modal-head"><div><h3>Transfer stok</h3><p>Mutasi dua ledger: keluar dari outlet asal, masuk ke outlet tujuan.</p></div></div>
    <form method="post" action="/inventory/transfer">
      <?= Csrf::field() ?>
      <input type="hidden" name="items_json" id="transferItemsJson" value="[]">
      <div class="modal-body">
        <div class="form-grid">
          <div class="field"><label>Dari outlet</label><select class="select" name="from_outlet_id">
            <?php foreach ($outlets as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Ke outlet</label><select class="select" name="to_outlet_id">
            <?php foreach ($outlets as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
          </select></div>
        </div>
        <div class="field"><label>Tambah item</label>
          <div class="flex">
            <select class="select" id="transferProduct">
              <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option><?php endforeach; ?>
            </select>
            <input class="input" id="transferQty" type="number" min="0.01" step="0.01" placeholder="Qty" style="max-width:110px">
            <button class="btn" type="button" onclick="addTransferRow()">Tambah</button>
          </div>
        </div>
        <div id="transferRows" class="small muted">Belum ada item.</div>
      </div>
      <div class="modal-foot"><button class="btn" type="button" data-modal-close="transferModal">Batal</button><button class="btn btn-primary" type="submit" onclick="syncTransfer()">Proses Transfer</button></div>
    </form>
  </div>
</div>
<script>
const transferItems = [];
function addTransferRow() {
  const sel = document.getElementById('transferProduct');
  const qty = Number(document.getElementById('transferQty').value);
  if (!qty || qty <= 0) { App.toast('Qty tidak valid.', 'error'); return; }
  const id = Number(sel.value);
  const name = sel.options[sel.selectedIndex].text;
  const existing = transferItems.find(i => i.product_id === id);
  if (existing) existing.qty += qty; else transferItems.push({ product_id: id, qty, name });
  document.getElementById('transferQty').value = '';
  renderTransfer();
}
function renderTransfer() {
  const box = document.getElementById('transferRows');
  if (!transferItems.length) { box.textContent = 'Belum ada item.'; return; }
  box.innerHTML = transferItems.map((i, idx) => `<div class="flex-between" style="padding:6px 0;border-bottom:1px dashed var(--line)"><span>${i.name}</span><span><strong>${App.number(i.qty, 2)}</strong> <button type="button" class="btn btn-xs btn-ghost" onclick="removeTransfer(${idx})">hapus</button></span></div>`).join('');
}
function removeTransfer(idx) { transferItems.splice(idx, 1); renderTransfer(); }
function syncTransfer() { document.getElementById('transferItemsJson').value = JSON.stringify(transferItems.map(i => ({ product_id: i.product_id, qty: i.qty }))); }
</script>
<?php endif; ?>

<script>
function openAdjust(btn) {
  const b = JSON.parse(btn.dataset.adjust);
  document.getElementById('adjustProduct').value = b.id;
  document.getElementById('adjustProductSelect').value = b.id;
  document.getElementById('adjustSub').textContent = b.name + ' · stok saat ini ' + App.number(b.qty, 2) + ' ' + b.unit;
  App.openModal('adjustModal');
}
</script>
