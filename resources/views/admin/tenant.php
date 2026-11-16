<?php use App\Core\Csrf; ?>
<div class="page-head">
  <div><h2><?= e($tenant['name']) ?></h2><p><?= e($tenant['slug']) ?> · <?= e($tenant['email'] ?: 'tanpa email') ?> · <?= e($tenant['timezone']) ?></p></div>
  <div class="flex">
    <a class="btn" href="/admin/tenants">Kembali</a>
    <?php if (!empty($context['license']['id'])): ?><a class="btn" href="/admin/licenses/<?= (int) $context['license']['id'] ?>">Riwayat Lisensi</a><?php endif; ?>
    <a class="btn btn-primary" href="/admin/licenses">Terbitkan Lisensi</a>
  </div>
</div>

<div class="grid grid-3">
  <div class="card"><div class="card-body">
    <div class="small muted">Paket</div>
    <div style="font-size:19px;font-weight:700"><?= e((string) ($context['plan']['name'] ?? '-')) ?></div>
    <div class="small muted mt-1">Status: <?= e(status_label((string) $tenant['status'])) ?></div>
    <div class="small muted">Lisensi s/d: <?= $context['license'] ? e(dt_local($context['license']['expires_at'], null, 'd M Y')) : '—' ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="small muted">Outlet</div>
    <div style="font-size:19px;font-weight:700"><?= count($outlets) ?></div>
    <div class="small muted mt-1"><?php foreach ($outlets as $o): ?><span class="chip" style="margin:2px"><?= e($o['code']) ?></span><?php endforeach; ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="small muted">Pengguna</div>
    <div style="font-size:19px;font-weight:700"><?= count($users) ?></div>
    <div class="small muted mt-1"><?php foreach (array_slice($users, 0, 3) as $u): ?><div><?= e($u['name']) ?></div><?php endforeach; ?></div>
  </div></div>
</div>

<div class="grid grid-2-1 mt-1">
  <div class="card">
    <div class="card-head"><div><h3>Override modul</h3><p>Hanya dapat mematikan modul yang diizinkan paket/lisensi.</p></div></div>
    <div class="card-body">
      <form method="post" action="/admin/tenants/<?= (int) $tenant['id'] ?>/features">
        <?= Csrf::field() ?>
        <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">
          <?php foreach ($modules as $code => $label): ?>
            <?php $effective = !empty($context['features'][$code]); $override = $features[$code] ?? null; ?>
            <label style="border:1px solid var(--line);border-radius:10px;padding:10px 12px;display:flex;gap:9px;align-items:center;background:<?= $effective ? '#f8faff' : '#fafafa' ?>">
              <input type="checkbox" name="modules[<?= e($code) ?>]" value="1" <?= $effective ? 'checked' : '' ?> style="accent-color:var(--brand)">
              <span style="flex:1"><strong style="font-size:12.5px"><?= e($label) ?></strong>
                <span class="small muted" style="display:block"><?= $override === null ? 'Mengikuti paket/lisensi' : ($override ? 'Override: aktif' : 'Override: nonaktif') ?></span>
              </span>
              <span class="badge badge-<?= $effective ? 'success' : 'muted' ?>"><?= $effective ? 'on' : 'off' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-primary mt-2" type="submit">Simpan Override</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Pengguna</h3></div>
    <div class="table-wrap">
      <table class="table compact">
        <thead><tr><th>Nama</th><th>Email</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr><td class="strong"><?= e($u['name']) ?></td><td class="sub"><?= e($u['email']) ?></td><td><span class="badge badge-<?= $u['status'] === 'active' ? 'success' : 'muted' ?>"><?= e(status_label($u['status'])) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
