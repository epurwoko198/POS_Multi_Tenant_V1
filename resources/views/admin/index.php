<?php
$planLabels = array_map(static fn ($p) => $p['name'], $byPlan);
$planCounts = array_map(static fn ($p) => (int) $p['total'], $byPlan);
?>
<div class="page-head">
  <div><h2>Platform Overview</h2><p>Tenant, lisensi, dan pendapatan langganan.</p></div>
  <div class="flex">
    <a class="btn" href="/admin/licenses"><?= icon('key') ?> Terbitkan Lisensi</a>
    <a class="btn btn-primary" href="/admin/tenants"><?= icon('building') ?> Kelola Tenant</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="label">Tenant</div><div class="value"><?= number_id($stats['tenants']) ?></div><div class="sub"><?= number_id($stats['active']) ?> aktif/trial · <?= number_id($stats['suspended']) ?> suspend</div><div class="icon"><?= icon('building') ?></div></div>
  <div class="stat"><div class="label">MRR</div><div class="value"><?= rupiah($stats['mrr']) ?></div><div class="sub">Paket aktif · <?= rupiah($stats['mrr'] * 12) ?> ARR</div><div class="icon emerald"><?= icon('cash') ?></div></div>
  <div class="stat"><div class="label">Lisensi aktif</div><div class="value"><?= number_id($stats['licenses']) ?></div><div class="sub"><?= number_id($stats['expiring']) ?> kedaluwarsa ≤ 30 hari</div><div class="icon amber"><?= icon('key') ?></div></div>
  <div class="stat"><div class="label">Pendapatan tercatat</div><div class="value"><?= rupiah($stats['revenue']) ?></div><div class="sub">Invoice SaaS lunas</div><div class="icon sky"><?= icon('card') ?></div></div>
</div>

<?php if ($expiringLicenses): ?>
<div class="card" style="border-color:#fde68a">
  <div class="card-head">
    <div><h3>Notifikasi lisensi kedaluwarsa</h3><p><?= count($expiringLicenses) ?> lisensi aktif berakhir dalam 30 hari atau sudah lewat</p></div>
    <a class="btn btn-sm" href="/admin/licenses">Kelola lisensi →</a>
  </div>
  <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Tenant</th><th>Paket</th><th>Berakhir</th><th>Sisa waktu</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($expiringLicenses as $l): ?>
        <?php $days = (int) $l['days_left']; ?>
        <tr>
          <td><div class="strong"><?= e($l['tenant_name']) ?></div><div class="sub">Key <?= e($l['key_id']) ?></div></td>
          <td><span class="badge badge-brand"><?= e($l['plan_code']) ?></span></td>
          <td class="sub nowrap"><?= e(dt_local($l['expires_at'], null, 'd M Y')) ?></td>
          <td><span class="badge badge-<?= $days < 0 ? 'danger' : 'warning' ?>"><?= $days < 0 ? 'Lewat ' . abs($days) . ' hari' : ($days === 0 ? 'Hari ini' : $days . ' hari lagi') ?></span></td>
          <td class="num"><a class="btn btn-sm btn-ghost" href="/admin/licenses/<?= (int) $l['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-2-1">
  <div class="card">
    <div class="card-head"><div><h3>Tenant terbaru</h3><p>25 tenant terakhir</p></div><a class="btn btn-sm btn-ghost" href="/admin/tenants">Semua →</a></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tenant</th><th>Paket</th><th class="num">Outlet / User</th><th>Lisensi</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$tenants): ?><tr><td colspan="5" class="muted center" style="padding:24px">Belum ada tenant.</td></tr><?php endif; ?>
        <?php foreach ($tenants as $t): ?>
          <tr>
            <td><div class="strong"><?= e($t['name']) ?></div><div class="sub"><?= e($t['slug']) ?> · <?= e($t['email'] ?: '-') ?></div></td>
            <td><span class="badge badge-brand"><?= e($t['plan_name'] ?? '-') ?></span></td>
            <td class="num"><?= (int) $t['outlets'] ?> / <?= (int) $t['users'] ?></td>
            <td class="sub"><?= $t['license_expires'] ? e(dt_local($t['license_expires'], null, 'd M Y')) : '—' ?></td>
            <td><span class="badge badge-<?= badge_status($t['status']) ?>"><?= e(status_label($t['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Distribusi paket</h3></div>
    <div class="card-body"><div class="chart-box sm"><canvas id="planChart"></canvas></div></div>
  </div>
</div>

<div class="grid grid-2 mt-1">
  <div class="card">
    <div class="card-head"><div><h3>Audit terbaru</h3><p>Aktivitas lintas tenant</p></div><a class="btn btn-sm btn-ghost" href="/admin/audit">Semua →</a></div>
    <div class="table-wrap">
      <table class="table compact">
        <thead><tr><th>Waktu</th><th>Tenant</th><th>Aksi</th><th>Oleh</th></tr></thead>
        <tbody>
        <?php foreach ($audit as $a): ?>
          <tr><td class="sub nowrap"><?= e(dt_local($a['created_at'], null, 'd M H:i')) ?></td><td><?= e($a['tenant_name'] ?: 'Platform') ?></td><td><span class="badge badge-muted"><?= e($a['action']) ?></span></td><td class="sub"><?= e($a['user_name'] ?: 'sistem') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Checklist operasional platform</h3></div>
    <div class="card-body" style="display:grid;gap:9px">
      <div class="flex-between"><span>Webhook pembayaran</span><span class="badge badge-success">Aktif</span></div>
      <div class="flex-between"><span>Audit lintas tenant</span><span class="badge badge-success">Aktif</span></div>
      <div class="flex-between"><span>Lisensi ditandatangani</span><span class="badge badge-success">HMAC-SHA256</span></div>
      <div class="flex-between"><span>AI service</span><span class="badge badge-info">Python lokal</span></div>
      <div class="flex-between"><span>Email pengingat lisensi</span><span class="badge badge-<?= $mailReady ? 'success' : 'warning' ?>"><?= $mailReady ? 'Siap' : 'Belum diatur' ?></span></div>
      <div class="flex-between"><span>Backup database</span><span class="badge badge-warning">Jadwalkan cron</span></div>
      <div class="alert alert-warning" style="margin-top:8px"><?= icon('alert') ?><div>Produksi: aktifkan HTTPS, backup terenkripsi, dan rotasi APP_KEY/lisensi secara berkala.</div></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const ctx = document.getElementById('planChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: { labels: <?= json_encode($planLabels) ?>, datasets: [{ data: <?= json_encode($planCounts) ?>, backgroundColor: ['#94a3b8','#6366f1','#06b6d4','#10b981'], borderRadius: 7, maxBarThickness: 34 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, border: { display: false } }, y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef2f8' }, ticks: { precision: 0 } } } }
  });
});
</script>
