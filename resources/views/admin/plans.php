<?php use App\Core\Csrf; ?>
<div class="page-head"><div><h2>Paket &amp; Feature Flags</h2><p>Semua batas modul disimpan di database, bukan hard-coded.</p></div></div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
<?php foreach ($plans as $plan): $pid = (int) $plan['id']; ?>
  <div class="card">
    <form method="post" action="/admin/plans/<?= $pid ?>">
      <?= Csrf::field() ?>
      <div class="card-head">
        <div><h3><?= e($plan['name']) ?></h3><p class="mono"><?= e($plan['code']) ?></p></div>
        <label class="check"><input type="checkbox" name="is_active" value="1" <?= $plan['is_active'] ? 'checked' : '' ?>> Aktif</label>
      </div>
      <div class="card-body">
        <div class="form-grid">
          <div class="field"><label>Nama</label><input class="input" name="name" value="<?= e($plan['name']) ?>" required></div>
          <div class="field"><label>Trial (hari)</label><input class="input" type="number" name="trial_days" value="<?= (int) $plan['trial_days'] ?>" min="0"></div>
          <div class="field"><label>Harga bulanan</label><input class="input" type="number" name="price_monthly" value="<?= (int) $plan['price_monthly'] ?>" min="0" step="1000"></div>
          <div class="field"><label>Harga tahunan</label><input class="input" type="number" name="price_yearly" value="<?= (int) $plan['price_yearly'] ?>" min="0" step="1000"></div>
        </div>

        <div class="small muted" style="text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin:6px 0 8px">Modul</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <?php foreach ($modules as $code => $label): ?>
            <label class="check" style="font-size:11.5px"><input type="checkbox" name="modules[<?= e($code) ?>]" value="1" <?= !empty($features[$pid][$code]) ? 'checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
        </div>

        <div class="small muted" style="text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin:14px 0 8px">Kuota (-1 = ∞)</div>
        <div class="form-grid">
          <?php foreach ($limitLabels as $key => $label): ?>
            <div class="field" style="margin-bottom:8px"><label style="font-size:11px"><?= e($label) ?></label>
              <input class="input" type="number" name="limits[<?= e($key) ?>]" value="<?= e((string) ($limits[$pid][$key] ?? -1)) ?>" min="-1" style="padding:7px 9px">
            </div>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-primary btn-block mt-1" type="submit">Simpan <?= e($plan['name']) ?></button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
</div>
