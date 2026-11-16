<?php
$pythonCmd = 'python python/ai_service.py';
?>
<div class="page-head">
  <div><h2>AI Analytics</h2><p>Mesin analitik statistik lokal: forecast akurat, anomali, reorder stok, RFM, dan market basket.</p></div>
  <div class="flex">
    <span class="badge badge-<?= $available ? 'success' : 'danger' ?>"><span class="dot"></span><?= $available ? 'AI service online' : 'AI service offline' ?></span>
    <button class="btn btn-sm" type="button" onclick="loadAll()"><?= icon('refresh') ?> Refresh</button>
  </div>
</div>

<?php if (!$available): ?>
<div class="card mb-2"><div class="card-body">
  <div class="alert alert-warning"><?= icon('alert') ?><div><strong>Layanan AI belum berjalan.</strong> Jalankan <code class="mono"><?= e($pythonCmd) ?></code> pada server, lalu muat ulang halaman. Endpoint: <span class="mono"><?= e($serviceUrl) ?></span></div></div>
  <p class="small muted">Layanan ini berjalan lokal tanpa cloud, membaca dataset melalui endpoint internal bertoken. Tidak ada data yang dikirim ke pihak ketiga.</p>
</div></div>
<?php endif; ?>

<div class="card mb-2">
  <div class="card-body">
    <div class="grid grid-2-1" style="align-items:center">
      <div>
        <div class="label small muted" style="text-transform:uppercase;letter-spacing:.1em;font-weight:700">Tanya AI</div>
        <div class="flex mt-1" style="gap:8px">
          <input class="input" id="askInput" placeholder="Contoh: berapa omzet 7 hari terakhir? produk apa yang harus direorder? jam paling sibuk?" style="height:44px">
          <button class="btn btn-primary" id="askBtn" type="button">Tanya</button>
        </div>
        <div class="flex wrap mt-1" style="gap:6px">
          <button class="chip" type="button" data-q="Berapa omzet 7 hari terakhir?">Omzet 7 hari</button>
          <button class="chip" type="button" data-q="Produk apa yang harus direorder?">Reorder stok</button>
          <button class="chip" type="button" data-q="Siapa pelanggan paling loyal?">Pelanggan loyal</button>
          <button class="chip" type="button" data-q="Ramalan penjualan 14 hari ke depan">Forecast</button>
        </div>
      </div>
      <div id="askAnswer" class="small muted">Jawaban dihitung dari data transaksi Anda, bukan tebakan.</div>
    </div>
  </div>
</div>

<div class="grid grid-2-1">
  <div class="card">
    <div class="card-head"><div><h3>Forecast omzet</h3><p id="forecastMeta">Memuat model…</p></div><span class="badge badge-brand" id="forecastModel">—</span></div>
    <div class="card-body"><div class="chart-box"><canvas id="forecastChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Ringkasan bisnis</h3><p>Dinilai dari data aktual</p></div></div>
    <div class="card-body" id="summaryBox"><div class="empty" style="padding:20px"><p>Memuat ringkasan…</p></div></div>
  </div>
</div>

<div class="grid grid-2 mt-1">
  <div class="card">
    <div class="card-head"><div><h3>Rekomendasi reorder</h3><p>Berdasarkan forecast permintaan &amp; safety stock</p></div></div>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Produk</th><th class="num">Stok</th><th class="num">Cover</th><th class="num">Saran order</th><th>Prioritas</th></tr></thead><tbody id="reorderBody"><tr><td colspan="5" class="muted center" style="padding:20px">Memuat…</td></tr></tbody></table></div>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Anomali penjualan</h3><p>Deteksi robust z-score per hari</p></div></div>
    <div class="card-body tight" id="anomalyBox"><div class="empty" style="padding:20px"><p>Memuat…</p></div></div>
  </div>
</div>

<div class="grid grid-2 mt-1">
  <div class="card">
    <div class="card-head"><div><h3>Segmentasi pelanggan (RFM)</h3><p>Recency, Frequency, Monetary</p></div></div>
    <div class="card-body" id="rfmBox"><div class="empty" style="padding:20px"><p>Memuat…</p></div></div>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Market basket</h3><p>Produk yang sering dibeli bersama</p></div></div>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Kombinasi</th><th class="num">Support</th><th class="num">Confidence</th><th class="num">Lift</th></tr></thead><tbody id="basketBody"><tr><td colspan="4" class="muted center" style="padding:20px">Memuat…</td></tr></tbody></table></div>
  </div>
</div>

<div class="card">
  <div class="card-head"><div><h3>Menu engineering</h3><p>Klasifikasi popularitas vs margin</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Omzet</th><th class="num">Margin</th><th>Kelas</th><th>Rekomendasi</th></tr></thead><tbody id="menuBody"><tr><td colspan="6" class="muted center" style="padding:24px">Memuat…</td></tr></tbody></table></div>
</div>

<script>
Chart.defaults.font.family = "'Inter','Segoe UI',sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#8794a8';
let forecastChart = null;
const fmtMoney = (n) => App.money(n);

function skeleton(rows, cols) { return `<tr><td colspan="${cols}" class="muted center" style="padding:20px">${rows}</td></tr>`; }

async function api(path) {
  const res = await App.fetchJson('/api/ai/' + path);
  if (!res.ok) throw new Error(res.message || 'AI error');
  return res.data;
}

async function loadSummary() {
  const box = document.getElementById('summaryBox');
  try {
    const d = await api('summary');
    const k = d.kpis || {};
    box.innerHTML = `
      <div class="totals">
        <div class="row"><span>Omzet periode</span><strong>${fmtMoney(k.revenue)}</strong></div>
        <div class="row"><span>Transaksi</span><strong>${App.number(k.orders)}</strong></div>
        <div class="row"><span>Rata-rata / nota</span><strong>${fmtMoney(k.aov)}</strong></div>
        <div class="row"><span>Margin kotor</span><strong>${App.number(k.gross_margin_pct, 1)}%</strong></div>
        <div class="row"><span>Pertumbuhan vs periode lalu</span><strong class="${k.growth_pct >= 0 ? 'delta-up' : 'delta-down'}">${App.number(k.growth_pct, 1)}%</strong></div>
      </div>
      <div class="mt-2" style="display:grid;gap:8px">${(d.narrative || []).map(n => `<div class="alert alert-info" style="margin:0">${iconSafe(n)}<div>${escapeHtml(n)}</div></div>`).join('')}</div>`;
  } catch (e) { box.innerHTML = `<div class="alert alert-error" style="margin:0">${escapeHtml(e.message)}</div>`; }
}

async function loadForecast() {
  try {
    const d = await api('forecast');
    document.getElementById('forecastModel').textContent = (d.model || 'model') + ' · MAPE ' + App.number(d.mape, 1) + '%';
    document.getElementById('forecastMeta').textContent = `Horizon ${d.horizon} hari · akurasi backtest ${App.number(100 - (d.mape || 0), 1)}%`;
    const history = d.history || [];
    const forecast = d.forecast || [];
    const labels = history.map(x => x.date.slice(5)).concat(forecast.map(x => x.date.slice(5)));
    const actual = history.map(x => x.value).concat(forecast.map(() => null));
    const pred = history.map(() => null).concat(forecast.map(x => x.value));
    const low = history.map(() => null).concat(forecast.map(x => x.low));
    const high = history.map(() => null).concat(forecast.map(x => x.high));
    const ctx = document.getElementById('forecastChart');
    if (forecastChart) forecastChart.destroy();
    forecastChart = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets: [
        { label: 'Aktual', data: actual, borderColor: '#0f172a', borderWidth: 2, pointRadius: 0, tension: .3 },
        { label: 'Forecast', data: pred, borderColor: '#4f46e5', borderWidth: 2, borderDash: [5, 4], pointRadius: 0, tension: .3 },
        { label: 'Batas atas', data: high, borderColor: 'rgba(79,70,229,0)', backgroundColor: 'rgba(99,102,241,.12)', fill: '+1', pointRadius: 0 },
        { label: 'Batas bawah', data: low, borderColor: 'rgba(79,70,229,0)', pointRadius: 0 }
      ] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', cornerRadius: 8, callbacks: { label: c => c.dataset.label + ': ' + fmtMoney(c.parsed.y) } } }, scales: { x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef2f8' }, ticks: { callback: v => App.money(v, false) } } } }
    });
  } catch (e) {
    document.getElementById('forecastMeta').textContent = 'Forecast tidak tersedia: ' + e.message;
  }
}

async function loadReorder() {
  const body = document.getElementById('reorderBody');
  try {
    const d = await api('reorder');
    const items = d.items || [];
    if (!items.length) { body.innerHTML = skeleton('Semua stok dalam level aman.', 5); return; }
    body.innerHTML = items.map(i => `<tr>
      <td><div class="strong" style="font-size:12.5px">${escapeHtml(i.name)}</div><div class="sub">${escapeHtml(i.reason || '')}</div></td>
      <td class="num">${App.number(i.stock, 0)}</td>
      <td class="num">${i.days_cover === null ? '—' : App.number(i.days_cover, 1) + 'h'}</td>
      <td class="num strong">${App.number(i.recommended_qty, 0)}</td>
      <td><span class="badge badge-${i.priority === 'high' ? 'danger' : (i.priority === 'medium' ? 'warning' : 'muted')}">${escapeHtml(i.priority)}</span></td>
    </tr>`).join('');
  } catch (e) { body.innerHTML = skeleton(escapeHtml(e.message), 5); }
}

async function loadAnomalies() {
  const box = document.getElementById('anomalyBox');
  try {
    const d = await api('anomalies');
    const items = d.items || [];
    if (!items.length) { box.innerHTML = '<div class="empty" style="padding:20px"><h4>Normal</h4><p>Tidak ada anomali signifikan.</p></div>'; return; }
    box.innerHTML = items.slice(0, 6).map(i => `<div class="flex-between" style="padding:9px 2px;border-bottom:1px dashed var(--line)">
      <div><div class="strong" style="font-size:12.5px">${escapeHtml(i.date)}</div><div class="sub">${escapeHtml(i.note)}</div></div>
      <span class="badge badge-${i.severity === 'high' ? 'danger' : 'warning'}">${fmtMoney(i.value)}</span>
    </div>`).join('');
  } catch (e) { box.innerHTML = `<div class="alert alert-error" style="margin:0">${escapeHtml(e.message)}</div>`; }
}

async function loadRfm() {
  const box = document.getElementById('rfmBox');
  try {
    const d = await api('rfm');
    const segments = d.segments || [];
    if (!segments.length) { box.innerHTML = '<div class="empty" style="padding:20px"><p>Belum ada data pelanggan bertransaksi.</p></div>'; return; }
    box.innerHTML = `<div style="display:grid;gap:9px">${segments.map(s => `
      <div class="flex-between" style="padding:9px 10px;border:1px solid var(--line);border-radius:10px">
        <div><strong style="font-size:12.8px">${escapeHtml(s.name)}</strong><div class="sub">${s.count} pelanggan · avg ${fmtMoney(s.avg_monetary)}</div></div>
        <span class="badge badge-brand">${fmtMoney(s.revenue)}</span>
      </div>`).join('')}</div>`;
  } catch (e) { box.innerHTML = `<div class="alert alert-error" style="margin:0">${escapeHtml(e.message)}</div>`; }
}

async function loadBasket() {
  const body = document.getElementById('basketBody');
  try {
    const d = await api('basket');
    const items = d.rules || [];
    if (!items.length) { body.innerHTML = skeleton('Belum ada kombinasi signifikan.', 4); return; }
    body.innerHTML = items.slice(0, 8).map(r => `<tr><td><div class="strong" style="font-size:12.5px">${escapeHtml(r.antecedent)}</div><div class="sub">+ ${escapeHtml(r.consequent)}</div></td><td class="num">${App.number(r.support * 100, 1)}%</td><td class="num">${App.number(r.confidence * 100, 1)}%</td><td class="num">${App.number(r.lift, 2)}×</td></tr>`).join('');
  } catch (e) { body.innerHTML = skeleton(escapeHtml(e.message), 4); }
}

async function loadMenu() {
  const body = document.getElementById('menuBody');
  try {
    const d = await api('menu');
    const items = d.items || [];
    if (!items.length) { body.innerHTML = skeleton('Belum ada data menu.', 6); return; }
    body.innerHTML = items.slice(0, 20).map(i => `<tr><td class="strong">${escapeHtml(i.name)}</td><td class="num">${App.number(i.qty, 0)}</td><td class="num">${fmtMoney(i.revenue)}</td><td class="num">${App.number(i.margin_pct, 1)}%</td><td><span class="badge badge-${i.class === 'star' ? 'success' : (i.class === 'plowhorse' ? 'info' : (i.class === 'puzzle' ? 'warning' : 'danger'))}">${escapeHtml(i.label)}</span></td><td class="sub">${escapeHtml(i.recommendation)}</td></tr>`).join('');
  } catch (e) { body.innerHTML = skeleton(escapeHtml(e.message), 6); }
}

function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
function iconSafe() { return ''; }

async function loadAll() {
  await Promise.allSettled([loadSummary(), loadForecast(), loadReorder(), loadAnomalies(), loadRfm(), loadBasket(), loadMenu()]);
}

document.getElementById('askBtn').addEventListener('click', ask);
document.getElementById('askInput').addEventListener('keydown', function (e) { if (e.key === 'Enter') ask(); });
document.querySelectorAll('[data-q]').forEach(b => b.addEventListener('click', function () {
  document.getElementById('askInput').value = this.dataset.q;
  ask();
}));

async function ask() {
  const q = document.getElementById('askInput').value.trim();
  if (!q) return;
  const box = document.getElementById('askAnswer');
  box.innerHTML = '<span class="muted">Menghitung…</span>';
  try {
    const res = await App.fetchJson('/api/ai/ask', { method: 'POST', body: { question: q } });
    box.innerHTML = `<div style="font-size:13px;color:var(--ink)">${escapeHtml(res.data.answer)}</div><div class="sub mt-1" style="color:var(--ink-3)">Intent: ${escapeHtml(res.data.intent || 'statistik')} · dihitung ${escapeHtml(res.data.generated_at || '')}</div>`;
  } catch (e) { box.innerHTML = `<span class="delta-down">${escapeHtml(e.message)}</span>`; }
}

loadAll();
</script>
