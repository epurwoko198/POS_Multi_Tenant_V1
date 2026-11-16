<?php use App\Core\Csrf; ?>
<div class="page-head"><div><h2>Audit Lintas Tenant</h2><p>300 aktivitas terakhir. Log append-only untuk tindakan sensitif.</p></div></div>
<div class="card">
  <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Waktu</th><th>Tenant</th><th>Pengguna</th><th>Aksi</th><th>Entitas</th><th>Detail</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$logs): ?><tr><td colspan="7" class="muted center" style="padding:24px">Belum ada log.</td></tr><?php endif; ?>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="sub nowrap"><?= e(dt_local($l['created_at'], null, 'd M H:i:s')) ?></td>
          <td><?= e($l['tenant_name'] ?: 'Platform') ?></td>
          <td class="sub"><?= e($l['user_name'] ?: 'sistem') ?></td>
          <td><span class="badge badge-muted"><?= e($l['action']) ?></span></td>
          <td class="sub"><?= e($l['entity']) ?> <?= $l['entity_id'] ? '#' . e($l['entity_id']) : '' ?></td>
          <td class="sub" style="max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($l['after_json'] ?: $l['before_json'] ?: '-') ?></td>
          <td class="sub mono"><?= e($l['ip'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
