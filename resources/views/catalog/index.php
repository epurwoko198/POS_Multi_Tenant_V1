<?php
use App\Core\Csrf;

$limitLabel = $limit < 0 ? 'tanpa batas' : (string) $limit;
?>
<div class="page-head">
  <div><h2>Katalog Produk</h2><p><?= number_id($usage) ?> dari <?= e($limitLabel) ?> produk terpakai pada paket Anda.</p></div>
  <div class="flex">
    <button class="btn" type="button" data-modal-open="categoryModal"><?= icon('plus') ?> Kategori</button>
    <button class="btn btn-primary" type="button" onclick="openProduct()"><?= icon('plus') ?> Produk Baru</button>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <form class="filter-bar" method="get" action="/catalog">
      <div class="search-input field" style="min-width:240px">
        <?= icon('search') ?>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Cari nama / SKU / barcode">
      </div>
      <div class="field"><select class="select" name="category" onchange="this.form.submit()">
        <option value="">Semua kategori</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
      <button class="btn" type="submit">Filter</button>
      <?php if ($q !== '' || $categoryId): ?><a class="btn btn-ghost" href="/catalog">Reset</a><?php endif; ?>
    </form>
    <span class="chip"><?= count($products) ?> produk ditampilkan</span>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Produk</th><th>Kategori</th><th class="num">Harga jual</th><th class="num">HPP</th><th class="num">Stok</th><th class="num">Margin</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="8"><div class="empty"><h4>Belum ada produk</h4><p>Tambahkan produk pertama Anda untuk mulai berjualan.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p):
        $margin = (float) $p['price'] > 0 ? (((float) $p['price'] - (float) $p['cost']) / (float) $p['price']) * 100 : 0;
      ?>
        <tr>
          <td>
            <div class="strong"><?= e($p['name']) ?></div>
            <div class="sub mono"><?= e($p['sku']) ?><?= $p['barcode'] ? ' · ' . e($p['barcode']) : '' ?><?= !$p['is_sellable'] ? ' · BAHAN BAKU' : '' ?></div>
          </td>
          <td><span class="badge badge-muted"><?= e($p['category'] ?: 'Tanpa kategori') ?></span></td>
          <td class="num strong"><?= rupiah($p['price']) ?></td>
          <td class="num"><?= rupiah($p['cost']) ?></td>
          <td class="num"><?= $p['track_stock'] ? number_id($p['stock'], 0) . ' ' . e($p['unit']) : '<span class="muted">resep</span>' ?></td>
          <td class="num <?= $margin < 15 ? 'delta-down' : 'delta-up' ?>"><?= number_id($margin, 1) ?>%</td>
          <td><span class="badge badge-<?= $p['is_active'] ? 'success' : 'muted' ?>"><?= $p['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td class="right">
            <button class="btn btn-sm" type="button" data-product='<?= e(json_encode($p, JSON_UNESCAPED_UNICODE)) ?>' onclick="openProduct(this)">Edit</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-backdrop hidden" id="productModal">
  <div class="modal wide">
    <div class="modal-head"><div><h3 id="productModalTitle">Produk Baru</h3><p>Harga, pajak, dan stok awal tersimpan per tenant/outlet.</p></div><button class="modal-close" type="button" data-modal-close="productModal"><?= icon('x') ?></button></div>
    <form method="post" action="/catalog/product">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="productId" value="">
      <div class="modal-body">
        <div class="form-grid">
          <div class="field full"><label>Nama produk *</label><input class="input" name="name" id="pName" required maxlength="160"></div>
          <div class="field"><label>SKU *</label><input class="input" name="sku" id="pSku" required maxlength="40"></div>
          <div class="field"><label>Barcode</label><input class="input" name="barcode" id="pBarcode" maxlength="64"></div>
          <div class="field"><label>Kategori</label><select class="select" name="category_id" id="pCategory">
            <option value="">Tanpa kategori</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Satuan</label><input class="input" name="unit" id="pUnit" value="pcs" maxlength="12"></div>
          <div class="field"><label>Harga jual *</label><input class="input" type="number" name="price" id="pPrice" min="0" step="100" required></div>
          <div class="field"><label>HPP / modal</label><input class="input" type="number" name="cost" id="pCost" min="0" step="100" value="0"></div>
          <div class="field"><label>Pajak (%)</label><input class="input" type="number" name="tax_rate" id="pTax" min="0" max="100" step="0.01" value="0"></div>
          <div class="field"><label>Station dapur</label><select class="select" name="station" id="pStation">
            <option value="">-</option><option value="kitchen">Kitchen</option><option value="bar">Bar</option><option value="dessert">Dessert</option>
          </select></div>
          <div class="field"><label>Reorder point</label><input class="input" type="number" name="reorder_point" id="pReorder" min="0" step="1" value="0"></div>
          <div class="field"><label>Qty reorder</label><input class="input" type="number" name="reorder_qty" id="pReorderQty" min="0" step="1" value="0"></div>
          <div class="field" id="initialStockField"><label>Stok awal (outlet Anda)</label><input class="input" type="number" name="initial_stock" id="pInitial" min="0" step="1" value="0"><div class="hint">Hanya untuk produk baru. Gunakan Inventori untuk penyesuaian.</div></div>
          <div class="field full">
            <div class="flex wrap" style="gap:18px">
              <label class="check"><input type="checkbox" name="track_stock" id="pTrack" checked> Lacak stok</label>
              <label class="check"><input type="checkbox" name="is_sellable" id="pSellable" checked> Dapat dijual di POS</label>
              <label class="check"><input type="checkbox" name="is_active" id="pActive" checked> Aktif</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot"><button class="btn" type="button" data-modal-close="productModal">Batal</button><button class="btn btn-primary" type="submit">Simpan Produk</button></div>
    </form>
  </div>
</div>

<div class="modal-backdrop hidden" id="categoryModal">
  <div class="modal">
    <div class="modal-head"><div><h3>Kategori baru</h3><p>Kelompokkan produk agar mudah dicari di POS.</p></div></div>
    <form method="post" action="/catalog/category">
      <?= Csrf::field() ?>
      <div class="modal-body">
        <div class="field"><label>Nama kategori</label><input class="input" name="name" required maxlength="80"></div>
        <div class="field"><label>Urutan</label><input class="input" type="number" name="sort_order" value="0"></div>
      </div>
      <div class="modal-foot"><button class="btn" type="button" data-modal-close="categoryModal">Batal</button><button class="btn btn-primary" type="submit">Tambah Kategori</button></div>
    </form>
  </div>
</div>

<script>
function openProduct(btn) {
  const form = document.querySelector('#productModal form');
  form.reset();
  document.getElementById('productId').value = '';
  document.getElementById('productModalTitle').textContent = 'Produk Baru';
  document.getElementById('initialStockField').style.display = '';
  if (btn && btn.dataset.product) {
    const p = JSON.parse(btn.dataset.product);
    document.getElementById('productModalTitle').textContent = 'Edit ' + p.name;
    document.getElementById('productId').value = p.id;
    document.getElementById('pName').value = p.name;
    document.getElementById('pSku').value = p.sku;
    document.getElementById('pBarcode').value = p.barcode || '';
    document.getElementById('pCategory').value = p.category_id || '';
    document.getElementById('pUnit').value = p.unit || 'pcs';
    document.getElementById('pPrice').value = p.price;
    document.getElementById('pCost').value = p.cost;
    document.getElementById('pTax').value = p.tax_rate;
    document.getElementById('pStation').value = p.station || '';
    document.getElementById('pReorder').value = p.reorder_point || 0;
    document.getElementById('pReorderQty').value = p.reorder_qty || 0;
    document.getElementById('pTrack').checked = Number(p.track_stock) === 1;
    document.getElementById('pSellable').checked = Number(p.is_sellable) === 1;
    document.getElementById('pActive').checked = Number(p.is_active) === 1;
    document.getElementById('initialStockField').style.display = 'none';
  }
  App.openModal('productModal');
}
</script>
