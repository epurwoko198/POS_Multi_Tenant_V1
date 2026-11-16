<?php
use App\Core\Auth;
use App\Core\License;

$mixLabels = ['cash' => 'Tunai', 'qris' => 'QRIS', 'edc' => 'EDC/Kartu', 'transfer' => 'Transfer', 'ewallet' => 'E-Wallet'];
$mixColors = ['#4f46e5', '#06b6d4', '#f59e0b', '#10b981', '#ec4899'];
$growthClass = $growth >= 0 ? 'delta-up' : 'delta-down';
?>
<?php if (in_array($licenseNotice['level'], ['expired', 'critical', 'warning'], true)): ?>
  <div class="alert alert-<?= $licenseNotice['level'] === 'warning' ? 'warning' : 'error' ?>">
    <?= icon('alert') ?>
    <div><strong><?= $licenseNotice['level'] === 'expired' ? 'Lisensi berakhir.' : 'Lisensi akan berakhir.' ?></strong> <?= e($licenseNotice['message']) ?> Tanggal berlaku: <?= e(dt_local((string) $licenseNotice['expires_at'], null, 'd M Y')) ?>. <a href="/license">Kelola lisensi →</a></div>
  </div>
<?php endif; ?>

<?php if (!$shift && License::enabled('pos') && Auth::can('pos.sell')): ?>
  <div class="alert alert-warning">
    <?= icon('alert') ?>
    <div><strong>Shift kasir belum dibuka.</strong> Buka shift di halaman Kas &amp; Shift sebelum memulai transaksi. <a href="/cash">Buka shift →</a></div>
  </div>
<?php endif; ?>

<div class="page-head">
  <div>
    <h2>Ringkasan hari ini</h2>
    <p><?= e(dt_local(now_utc(), null, 'l, d F Y')) ?> · data diperbarui real-time dari transaksi tersimpan.</p>
  </div>
  <div class="flex wrap">
    <?php if (License::enabled('reports')): ?><a class="btn btn-sm" href="/reports"><?= icon('chart') ?> Laporan</a><?php endif; ?>
    <?php if (License::enabled('ai')): ?><a class="btn btn-sm" href="/ai"><?= icon('sparkles') ?> AI Insights</a><?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat">
    <div class="label">Omzet hari ini</div>
    <div class="value"><?= rupiah($kpi['revenue']) ?></div>
    <div class="sub"><span class="<?= $growthClass ?>"><?= $growth >= 0 ? '+' : '' ?><?= number_id($growth, 1) ?>%</span> vs kemarin (<?= rupiah($kpiYesterday['revenue']) ?>)</div>
    <div class="icon emerald"><?= icon('cash') ?></div>
  </div>
  <div class="stat">
    <div class="label">Transaksi</div>
    <div class="value"><?= number_id($kpi['orders']) ?></div>
    <div class="sub">Rata-rata <?= rupiah($kpi['avg_basket']) ?> / nota</div>
    <div class="icon"><?= icon('receipt') ?></div>
  </div>
  <div class="stat">
    <div class="label">Laba kotor estimasi</div>
    <div class="value"><?= rupiah($kpi['gross_profit']) ?></div>
    <div class="sub"><?= $kpi['revenue'] > 0 ? number_id(($kpi['gross_profit'] / $kpi['revenue']) * 100, 1) : '0' ?>% margin · sebelum biaya operasional</div>
    <div class="icon sky"><?= icon('chart') ?></div>
  </div>
  <div class="stat">
    <div class="label">Perlu perhatian</div>
    <div class="value"><?= number_id(count($lowStock)) ?></div>
    <div class="sub">stok kritis · <?= (int) $pendingKds ?> tiket dapur · <?= (int) $kpi['pending_count'] ?> pembayaran pending</div>
    <div class="icon amber"><?= icon('alert') ?></div>
  </div>
</div>

<div class="grid grid-2-1">
  <div class="card">
    <div class="card-head">
      <div><h3>Tren omzet 14 hari</h3><p>Omzet neto setelah diskon, termasuk pajak &amp; service charge.</p></div>
      <span class="chip"><?= e((string) ($context['currency'] ?? 'IDR')) ?></span>
    </div>
    <div class="card-body"><div class="chart-box"><canvas id="salesChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Metode pembayaran</h3><span class="small muted">30 hari</span></div>
    <div class="card-body">
      <div class="chart-box sm"><canvas id="mixChart"></canvas></div>
      <?php if (!$mix): ?><div class="empty" style="padding:10px"><p>Belum ada pembayaran lunas.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-2-1 mt-1">
  <div class="card">
    <div class="card-head">
      <div><h3>Transaksi terbaru</h3><p>8 transaksi terakhir pada cakupan outlet Anda.</p></div>
      <a class="btn btn-sm btn-ghost" href="/reports">Lihat semua <?= icon('arrow-right') ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>No. Order</th><th>Waktu</th><th>Kasir</th><th>Tipe</th><th>Status</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="6"><div class="empty"><h4>Belum ada transaksi</h4><p>Transaksi yang dibayar akan tampil di sini.</p></div></td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $o): ?>
          <tr>
            <td><a href="/pos/receipt/<?= e($o['public_id']) ?>" class="mono strong"><?= e($o['order_no']) ?></a></td>
            <td class="sub"><?= e(dt_local($o['created_at'], null, 'd M H:i')) ?></td>
            <td><?= e($o['cashier']) ?></td>
            <td><span class="badge badge-muted"><?= e(status_label($o['order_type'])) ?></span></td>
            <td><span class="badge badge-<?= badge_status($o['status']) ?>"><span class="dot"></span><?= e(status_label($o['status'])) ?></span></td>
            <td class="num strong"><?= rupiah($o['grand_total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Stok kritis</h3><p>≤ reorder point</p></div><a class="btn btn-sm btn-ghost" href="/inventory">Kelola</a></div>
    <div class="card-body tight">
      <?php if (!$lowStock): ?>
        <div class="empty" style="padding:20px"><h4>Stok aman</h4><p>Tidak ada produk di bawah reorder point.</p></div>
      <?php else: ?>
        <?php foreach ($lowStock as $p): ?>
          <div class="flex-between" style="padding:9px 4px;border-bottom:1px dashed var(--line)">
            <div>
              <div class="strong" style="font-size:12.8px"><?= e($p['name']) ?></div>
              <div class="small muted"><?= e($p['sku']) ?> · min <?= number_id($p['reorder_point'], 0) ?> <?= e($p['unit']) ?></div>
            </div>
            <span class="badge badge-<?= (float) $p['qty'] <= 0 ? 'danger' : 'warning' ?>"><?= number_id($p['qty'], 0) ?> tersisa</span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="grid grid-2 mt-1">
  <div class="card">
    <div class="card-head"><div><h3>Produk terlaris</h3><p>30 hari terakhir berdasarkan omzet</p></div></div>
    <div class="table-wrap">
      <table class="table compact">
        <thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Omzet</th></tr></thead>
        <tbody>
        <?php if (!$top): ?><tr><td colspan="3"><div class="empty" style="padding:24px"><p>Belum ada penjualan.</p></div></td></tr><?php endif; ?>
        <?php foreach ($top as $t): ?>
          <tr><td class="strong"><?= e($t['name']) ?></td><td class="num"><?= number_id($t['qty'], 0) ?></td><td class="num"><?= rupiah($t['revenue']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Performa outlet</h3><p>30 hari terakhir</p></div></div>
    <div class="table-wrap">
      <table class="table compact">
        <thead><tr><th>Outlet</th><th class="num">Transaksi</th><th class="num">Omzet</th></tr></thead>
        <tbody>
        <?php if (!$outletCompare): ?><tr><td colspan="3"><div class="empty" style="padding:24px"><p>Belum ada data.</p></div></td></tr><?php endif; ?>
        <?php foreach ($outletCompare as $o): ?>
          <tr><td class="strong"><?= e($o['name']) ?></td><td class="num"><?= number_id($o['orders']) ?></td><td class="num"><?= rupiah($o['revenue']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const series = <?= json_encode($series, JSON_UNESCAPED_UNICODE) ?>;
  const mix = <?= json_encode($mix, JSON_UNESCAPED_UNICODE) ?>;
  Chart.defaults.font.family = "'Inter','Segoe UI',sans-serif";
  Chart.defaults.font.size = 11;
  Chart.defaults.color = '#8794a8';

  const ctx = document.getElementById('salesChart');
  if (ctx) {
    const g = ctx.getContext('2d').createLinearGradient(0, 0, 0, 260);
    g.addColorStop(0, 'rgba(79,70,229,.22)');
    g.addColorStop(1, 'rgba(79,70,229,0)');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: series.labels,
        datasets: [{
          label: 'Omzet',
          data: series.revenue,
          borderColor: '#4f46e5',
          backgroundColor: g,
          borderWidth: 2.2,
          pointRadius: 0,
          pointHoverRadius: 4,
          fill: true,
          tension: .38
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => 'Omzet: ' + App.money(c.parsed.y) },
            backgroundColor: '#0f172a', padding: 10, cornerRadius: 8, displayColors: false }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false } },
          y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef2f8' },
               ticks: { callback: v => App.money(v, false) } }
        }
      }
    });
  }

  const mixCanvas = document.getElementById('mixChart');
  if (mixCanvas && mix.length) {
    const labels = mix.map(m => <?= json_encode($mixLabels) ?>[m.method] || m.method);
    const colors = <?= json_encode($mixColors) ?>;
    new Chart(mixCanvas, {
      type: 'doughnut',
      data: { labels, datasets: [{ data: mix.map(m => Number(m.amount)), backgroundColor: colors, borderWidth: 0, hoverOffset: 6 }] },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 14 } },
          tooltip: { callbacks: { label: c => c.label + ': ' + App.money(c.parsed) }, backgroundColor: '#0f172a', cornerRadius: 8 }
        }
      }
    });
  }
});
</script>
