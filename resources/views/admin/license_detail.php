<?php
$licenseModules = json_decode((string) $license['features_json'], true) ?: [];
$enabledModules = array_keys(array_filter($licenseModules));
$licenseLimits = json_decode((string) $license['limits_json'], true) ?: [];
$eventBadges = ['issued' => 'badge-success', 'activated' => 'badge-info', 'revoked' => 'badge-danger'];
$eventLabels = ['issued' => 'Diterbitkan', 'activated' => 'Diaktivasi', 'revoked' => 'Dicabut'];
$limitLabels = static function (array $map) use ($limits): string {
    $bits = [];
    foreach ($limits as $key => $label) {
        if (!array_key_exists($key, $map)) {
            continue;
        }
        $value = (int) $map[$key];
        $bits[] = explode(' ', $label)[0] . ' ' . ($value < 0 ? '∞' : number_id($value, 0));
    }
    return $bits === [] ? '-' : implode(' · ', $bits);
};
?>
<div class="page-head">
  <div>
    <h2>Detail Lisensi</h2>
    <p><?= e($license['tenant_name']) ?> · <span class="mono"><?= e($license['key_id']) ?></span> · <?= e($license['plan_code']) ?></p>
  </div>
  <div class="flex">
    <a class="btn" href="/admin/licenses">Kembali</a>
    <a class="btn" href="/admin/tenants/<?= (int) $license['tenant_id'] ?>">Detail Tenant</a>
    <button class="btn btn-primary" type="button" data-copy="<?= e($license['token']) ?>" data-copy-label="Token lisensi">Salin Token</button>
  </div>
</div>

<div class="grid grid-1-2">
  <div class="card">
    <div class="card-head">
      <div><h3>Informasi lisensi</h3><p>Token dan status penerbitan</p></div>
      <span class="badge badge-<?= badge_status($license['status']) ?>"><span class="dot"></span><?= e(status_label($license['status'])) ?></span>
    </div>
    <div class="card-body">
      <table class="table compact">
        <tbody>
          <tr><td class="muted">Tenant</td><td class="strong"><a href="/admin/tenants/<?= (int) $license['tenant_id'] ?>"><?= e($license['tenant_name']) ?></a><div class="sub mono"><?= e($license['slug']) ?></div></td></tr>
          <tr><td class="muted">Paket</td><td><?= e($license['plan_code']) ?></td></tr>
          <tr><td class="muted">Key ID</td><td class="mono"><?= e($license['key_id']) ?></td></tr>
          <tr><td class="muted">Domain</td><td><?= e($license['domain'] ?: 'Semua domain') ?></td></tr>
          <tr><td class="muted">Diterbitkan</td><td><?= e(dt_local($license['issued_at'], null, 'd M Y H:i')) ?></td></tr>
          <tr><td class="muted">Berlaku sampai</td><td><?= e(dt_local($license['expires_at'], null, 'd M Y H:i')) ?></td></tr>
          <?php if ($license['revoked_at']): ?>
            <tr><td class="muted">Dicabut</td><td class="text-danger"><?= e(dt_local($license['revoked_at'], null, 'd M Y H:i')) ?><div class="sub"><?= e($license['revoked_reason'] ?: '-') ?></div></td></tr>
          <?php endif; ?>
          <tr><td class="muted">Modul aktif</td><td><?= count($enabledModules) ?> dari <?= count($modules) ?> modul</td></tr>
          <tr><td class="muted">Kuota</td><td class="sub"><?= e($limitLabels($licenseLimits)) ?></td></tr>
        </tbody>
      </table>
      <div class="field mt-2">
        <label>Token lisensi</label>
        <div class="license-token"><?= e($license['token']) ?></div>
        <div class="hint">Token ditandatangani HMAC-SHA256. Jangan tampilkan ke pengguna tenant.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <div><h3>Entitlement saat ini</h3><p>Modul dan kuota yang berlaku untuk tenant</p></div>
      <span class="badge badge-<?= $context['can_transact'] ? 'success' : 'danger' ?>"><span class="dot"></span><?= $context['can_transact'] ? 'Dapat bertransaksi' : 'Transaksi diblokir' ?></span>
    </div>
    <div class="card-body">
      <div class="small muted">Status langganan</div>
      <div class="strong"><?= e($context['message']) ?></div>
      <div class="small muted mt-1">Lisensi aktif:
        <?php if ($current): ?>
          <a class="mono" href="/admin/licenses/<?= (int) $current['id'] ?>"><?= e($current['key_id']) ?></a>
          <span class="badge badge-<?= badge_status($current['status']) ?>"><?= e(status_label($current['status'])) ?></span>
        <?php else: ?>
          <span class="text-danger">Tidak ada lisensi aktif</span>
        <?php endif; ?>
      </div>

      <div class="field mt-2">
        <label>Modul</label>
        <div class="module-list">
          <?php foreach ($modules as $code => $label): ?>
            <span class="badge badge-<?= !empty($context['features'][$code]) ? 'success' : 'muted' ?>"><?= e($label) ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field mt-1">
        <label>Kuota berlaku</label>
        <table class="table compact">
          <tbody>
            <?php foreach ($limits as $key => $label): ?>
              <?php $value = (int) ($context['limits'][$key] ?? -1); ?>
              <tr><td class="muted"><?= e($label) ?></td><td class="num"><?= $value < 0 ? 'Tanpa batas' : number_id($value, 0) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mt-2">
  <div class="card-head"><div><h3>Riwayat lisensi &amp; perubahan modul</h3><p>Semua lisensi tenant ini, terbaru di atas</p></div></div>
  <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Lisensi</th><th>Diterbitkan</th><th>Paket</th><th>Modul aktif</th><th>Perubahan modul</th><th>Kuota</th><th>Status</th></tr></thead>
      <tbody>
      <?php if ($history === []): ?><tr><td colspan="7" class="muted center" style="padding:24px">Belum ada lisensi.</td></tr><?php endif; ?>
      <?php foreach (array_reverse($history) as $row): ?>
        <tr>
          <td class="strong"><a href="/admin/licenses/<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?></a><div class="sub mono"><?= e($row['key_id']) ?></div></td>
          <td class="sub"><?= e(dt_local($row['issued_at'], null, 'd M Y H:i')) ?></td>
          <td><?= e($row['plan_code']) ?></td>
          <td>
            <div class="strong"><?= count($row['enabled_modules']) ?> modul</div>
            <div class="sub"><?= e(implode(', ', array_map(static fn ($code) => $modules[$code] ?? $code, $row['enabled_modules']))) ?></div>
          </td>
          <td>
            <?php if ($row['is_first']): ?>
              <span class="badge badge-info">Awal</span>
            <?php else: ?>
              <?php if ($row['added_modules']): ?>
                <div class="diff-add">+ <?= e(implode(', ', array_map(static fn ($code) => $modules[$code] ?? $code, $row['added_modules']))) ?></div>
              <?php endif; ?>
              <?php if ($row['removed_modules']): ?>
                <div class="diff-remove">− <?= e(implode(', ', array_map(static fn ($code) => $modules[$code] ?? $code, $row['removed_modules']))) ?></div>
              <?php endif; ?>
              <?php if (!$row['added_modules'] && !$row['removed_modules']): ?><span class="sub">Tidak berubah</span><?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="sub"><?= e($limitLabels($row['limit_map'])) ?></td>
          <td><span class="badge badge-<?= badge_status($row['status']) ?>"><?= e(status_label($row['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mt-2">
  <div class="card-head"><div><h3>Riwayat event</h3><p>Issue, aktivasi, dan pencabutan lisensi</p></div></div>
  <div class="card-body">
    <?php if ($events === []): ?>
      <div class="muted center" style="padding:20px">Belum ada event lisensi.</div>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($events as $event): ?>
          <li>
            <div>
              <span class="badge <?= $eventBadges[$event['event']] ?? 'badge-muted' ?>"><?= e($eventLabels[$event['event']] ?? ucfirst($event['event'])) ?></span>
              <?php if ($event['license_id']): ?>
                <a class="mono small" href="/admin/licenses/<?= (int) $event['license_id'] ?>"><?= e($event['key_id'] ?: '#' . $event['license_id']) ?></a>
              <?php endif; ?>
            </div>
            <div class="when"><?= e(dt_local($event['created_at'], null, 'd M Y H:i')) ?> · <?= e($event['actor_name'] ?: 'Sistem') ?></div>
            <?php if (!empty($event['meta']['reason'])): ?><div class="small">Alasan: <?= e($event['meta']['reason']) ?></div><?php endif; ?>
            <?php if (isset($event['meta']['modules']) && is_array($event['meta']['modules'])): ?>
              <div class="small muted"><?= count(array_filter($event['meta']['modules'])) ?> modul, <?= e($limitLabels((array) ($event['meta']['limits'] ?? []))) ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
