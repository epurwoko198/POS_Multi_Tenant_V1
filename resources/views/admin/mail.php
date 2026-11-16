<?php
use App\Core\Csrf;

$ready = $config['enabled'] && $missing === [];
$status = $ready ? ['success', 'Siap'] : ($config['enabled'] ? ['warning', 'Belum lengkap'] : ['muted', 'Nonaktif']);
?>
<div class="page-head">
  <div><h2>Email &amp; SMTP</h2><p>Konfigurasi pengirim email platform dan riwayat pengingat lisensi H-30/H-7.</p></div>
  <span class="badge badge-<?= $status[0] ?>"><span class="dot"></span><?= $status[1] ?></span>
</div>

<?php if ($missing): ?>
  <div class="alert alert-warning"><?= icon('alert') ?><div>Konfigurasi belum lengkap: <strong><?= e(implode(', ', $missing)) ?></strong>. Pengingat otomatis tidak akan berjalan sampai dilengkapi.</div></div>
<?php endif; ?>

<div class="grid grid-2-1">
  <div class="card">
    <div class="card-head"><div><h3>Konfigurasi SMTP</h3><p>Berlaku untuk seluruh tenant dan email platform</p></div><span class="badge badge-info">AES-256-GCM</span></div>
    <div class="card-body">
      <form method="post" action="/admin/mail">
        <?= Csrf::field() ?>
        <label class="check mb-2"><input type="checkbox" name="enabled" value="1" <?= $config['enabled'] ? 'checked' : '' ?>> Aktifkan pengiriman email dan pengingat lisensi</label>
        <div class="form-grid">
          <div class="field full"><label>SMTP host *</label><input class="input" name="host" value="<?= e($config['host']) ?>" placeholder="smtp.example.com" autocomplete="off"></div>
          <div class="field"><label>Port *</label><input class="input" type="number" name="port" min="1" max="65535" value="<?= (int) $config['port'] ?>" required></div>
          <div class="field"><label>Enkripsi</label>
            <select class="select" name="encryption">
              <option value="tls" <?= $config['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS (biasanya 587)</option>
              <option value="ssl" <?= $config['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS langsung (biasanya 465)</option>
              <option value="none" <?= $config['encryption'] === 'none' ? 'selected' : '' ?>>Tanpa enkripsi (hanya dev/internal)</option>
            </select>
          </div>
          <div class="field"><label>Autentikasi</label>
            <select class="select" name="auth">
              <option value="login" <?= $config['auth'] === 'login' ? 'selected' : '' ?>>AUTH LOGIN</option>
              <option value="plain" <?= $config['auth'] === 'plain' ? 'selected' : '' ?>>AUTH PLAIN</option>
            </select>
          </div>
          <div class="field"><label>Username</label><input class="input" name="username" value="<?= e($config['username']) ?>" autocomplete="off" placeholder="Kosongkan jika tanpa autentikasi"></div>
          <div class="field full"><label>Password SMTP</label>
            <input class="input" type="password" name="password" value="" autocomplete="new-password" placeholder="<?= $passwordSet ? '•••••••• (biarkan kosong untuk mempertahankan)' : 'Password SMTP' ?>">
            <div class="small muted mt-1">Password disimpan terenkripsi. Kosongkan kolom ini saat menyimpan perubahan lain.</div>
            <?php if ($passwordSet): ?><label class="check mt-1"><input type="checkbox" name="clear_password" value="1"> Hapus password tersimpan</label><?php endif; ?>
          </div>
          <div class="field"><label>Email pengirim *</label><input class="input" type="email" name="from_email" value="<?= e($config['from_email']) ?>" placeholder="no-reply@example.com"></div>
          <div class="field"><label>Nama pengirim</label><input class="input" name="from_name" value="<?= e($config['from_name']) ?>" placeholder="Berkah POS"></div>
        </div>
        <button class="btn btn-primary" type="submit"><?= icon('check') ?> Simpan Konfigurasi</button>
      </form>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><div><h3>Email uji</h3><p>Kirim pesan untuk memvalidasi koneksi</p></div></div>
      <div class="card-body">
        <form method="post" action="/admin/mail/test">
          <?= Csrf::field() ?>
          <div class="field"><label>Kirim ke</label><input class="input" type="email" name="recipient" value="" placeholder="admin@example.com" required></div>
          <button class="btn btn-block" type="submit" <?= $config['enabled'] ? '' : 'disabled' ?>><?= icon('mail') ?> Kirim Email Uji</button>
        </form>
        <div class="alert alert-info mt-2"><?= icon('alert') ?><div>Jika gagal, periksa host, port, enkripsi, kredensial, dan izin koneksi keluar server.</div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div><h3>Penjadwalan</h3><p>Pengingat H-30 dan H-7</p></div></div>
      <div class="card-body">
        <p class="small muted">Jalankan sekali sehari lewat cron. Setiap lisensi hanya menerima satu email per ambang; kegagalan dicoba lagi pada eksekusi berikutnya.</p>
        <div class="license-token mt-2">php bin/license_reminders.php --dry-run
php bin/license_reminders.php</div>
        <div class="small muted mt-2">Contoh crontab harian 08:00:</div>
        <div class="license-token mt-1">0 8 * * * cd /path/ke/aplikasi && php bin/license_reminders.php &gt;&gt; storage/logs/reminders.log 2&gt;&amp;1</div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-1">
  <div class="card-head"><div><h3>Riwayat pengingat</h3><p>30 percobaan terakhir, termasuk yang gagal</p></div><span class="badge badge-muted"><?= count($reminders) ?> catatan</span></div>
  <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Waktu</th><th>Tenant</th><th>Lisensi</th><th>Ambang</th><th>Penerima</th><th>Status</th><th>Keterangan</th></tr></thead>
      <tbody>
      <?php if (!$reminders): ?><tr><td colspan="7" class="muted center" style="padding:24px">Belum ada pengingat yang dikirim.</td></tr><?php endif; ?>
      <?php foreach ($reminders as $r): ?>
        <?php
          $badge = match ($r['status']) {
              'sent' => 'success',
              'failed' => 'danger',
              default => 'warning',
          };
        ?>
        <tr>
          <td class="sub nowrap"><?= e(dt_local($r['sent_at'] ?: $r['updated_at'], null, 'd M H:i:s')) ?></td>
          <td><a class="strong" href="/admin/tenants/<?= (int) $r['tenant_id'] ?>"><?= e($r['tenant_name']) ?></a></td>
          <td class="mono sub"><?= e($r['key_id'] ?: '-') ?></td>
          <td><span class="badge badge-muted">H-<?= (int) $r['threshold_days'] ?></span></td>
          <td class="sub"><?= e($r['recipient'] ?: '-') ?></td>
          <td><span class="badge badge-<?= $badge ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td class="sub" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e((string) ($r['last_error'] ?: '')) ?>"><?= e($r['last_error'] ?: ($r['status'] === 'sent' ? 'Terkirim' : '-')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
