<?php use App\Core\Csrf; ?>
<div class="page-head"><div><h2>Lisensi</h2><p>Token ditandatangani, aktif langsung, dapat dicabut kapan saja.</p></div></div>

<?php if (!empty($issued)): ?>
  <div class="alert alert-success"><?= icon('key') ?><div><strong>Lisensi diterbitkan.</strong> Key <span class="mono"><?= e($issued['key_id']) ?></span><br>
    <code class="mono" style="font-size:10px;word-break:break-all"><?= e($issued['token']) ?></code><br>
    <button class="btn btn-sm mt-1" type="button" data-copy="<?= e($issued['token']) ?>" data-copy-label="Token lisensi">Salin token</button>
    <span class="small muted">Berlaku s/d <?= e(dt_local(gmdate('Y-m-d H:i:s', (int) $issued['expires_at']), null, 'd M Y')) ?></span></div></div>
<?php endif; ?>

<div class="grid grid-1-2">
  <div class="card">
    <div class="card-head"><div><h3>Terbitkan lisensi</h3><p>Lisensi baru langsung menggantikan lisensi aktif sebelumnya.</p></div></div>
    <div class="card-body">
      <form method="post" action="/admin/licenses">
        <?= Csrf::field() ?>
        <div class="field"><label>Tenant</label><select class="select" name="tenant_id" required>
          <?php foreach ($tenants as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Paket</label><select class="select" name="plan_id" required>
          <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-grid">
          <div class="field"><label>Masa berlaku (hari)</label><input class="input" type="number" name="days" min="1" max="3650" value="365"></div>
          <div class="field"><label>Domain (opsional)</label><input class="input" name="domain" placeholder="pos.klien.id"></div>
        </div>
        <div class="field">
          <label>Modul lisensi</label>
          <div class="module-picker">
            <?php foreach ($modules as $code => $label): ?>
              <label class="check"><input type="checkbox" name="modules[<?= e($code) ?>]" value="1"> <span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="hint">Kosongkan untuk mengikuti paket. Pilih modul untuk menentukan sendiri modul lisensi ini; modul tambahan di luar paket juga dapat diberikan lewat lisensi.</div>
          <button class="btn btn-xs mt-1" type="button" onclick="this.closest('form').querySelectorAll('input[name^=modules]').forEach(function(c){c.checked=true})">Pilih semua</button>
        </div>
        <div class="field">
          <label>Kuota lisensi</label>
          <div class="form-grid">
            <?php foreach ($limits as $key => $label): ?>
              <div class="field"><label><?= e($label) ?></label><input class="input" type="number" min="-1" max="1000000" name="limits[<?= e($key) ?>]" placeholder="Ikut paket"></div>
            <?php endforeach; ?>
          </div>
          <div class="hint">Kosongkan untuk mengikuti paket. Isi <span class="mono">-1</span> untuk tanpa batas, <span class="mono">0</span> untuk menutup kuota. Nilai lisensi menggantikan kuota paket.</div>
        </div>
        <button class="btn btn-primary btn-block" type="submit"><?= icon('key') ?> Terbitkan</button>
      </form>
      <div class="alert alert-info mt-2"><?= icon('alert') ?><div>Token memuat tenant, paket, modul, kuota, dan masa berlaku. Verifikasi memakai HMAC-SHA256 dengan APP_KEY.</div></div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Riwayat lisensi</h3><p>100 lisensi terakhir</p></div></div>
    <div class="table-wrap">
      <table class="table compact">
        <thead><tr><th>Tenant</th><th>Paket</th><th>Modul</th><th>Kuota</th><th>Domain</th><th>Berlaku</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$licenses): ?><tr><td colspan="8" class="muted center" style="padding:24px">Belum ada lisensi.</td></tr><?php endif; ?>
        <?php foreach ($licenses as $l): ?>
          <?php
            $licenseModules = json_decode((string) $l['features_json'], true) ?: [];
            $activeModules = array_keys(array_filter($licenseModules));
            $licenseLimits = json_decode((string) $l['limits_json'], true) ?: [];
            $limitBits = [];
            foreach ($limits as $limitKey => $limitLabel) {
                if (!array_key_exists($limitKey, $licenseLimits)) {
                    continue;
                }
                $limitValue = (int) $licenseLimits[$limitKey];
                $limitBits[] = explode(' ', $limitLabel)[0] . ' ' . ($limitValue < 0 ? '∞' : number_id($limitValue, 0));
            }
          ?>
          <tr>
            <td class="strong"><a href="/admin/licenses/<?= (int) $l['id'] ?>"><?= e($l['tenant_name']) ?></a><div class="sub mono"><?= e($l['key_id']) ?></div></td>
            <td><?= e($l['plan_code']) ?></td>
            <td class="sub"><?= count($activeModules) ?> modul<div class="sub"><?= e(implode(', ', array_map(static fn ($c) => $modules[$c] ?? $c, $activeModules))) ?></div></td>
            <td class="sub"><?= e($limitBits === [] ? '-' : implode(' · ', $limitBits)) ?></td>
            <td class="sub"><?= e($l['domain'] ?: 'semua') ?></td>
            <td class="sub"><?= e(dt_local($l['expires_at'], null, 'd M Y')) ?></td>
            <td><span class="badge badge-<?= badge_status($l['status']) ?>"><?= e(status_label($l['status'])) ?></span></td>
            <td class="right nowrap">
              <a class="btn btn-xs" href="/admin/licenses/<?= (int) $l['id'] ?>">Detail</a>
              <button class="btn btn-xs" type="button" data-copy="<?= e($l['token']) ?>" data-copy-label="Token">Salin</button>
              <?php if ($l['status'] === 'active'): ?>
                <form method="post" action="/admin/licenses/<?= (int) $l['id'] ?>/revoke" style="display:inline" data-confirm="Cabut lisensi ini?">
                  <?= Csrf::field() ?><input type="hidden" name="reason" value="Dicabut dari console admin"><button class="btn btn-xs btn-danger" type="submit">Cabut</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
