# Berkah POS — Platform POS SaaS Multi-Tenant

Aplikasi POS SaaS lengkap sesuai `Blueprint_Arsitektur_POS_SaaS_PHP84.docx`: PHP 8.3 + mysqli,
dashboard web central, mesin AI Python lokal, dan lisensi SaaS berlapis yang mengaktifkan modul
sesuai paket, lisensi bertanda tangan, dan override tenant.

## Fitur

| Modul | Isi |
|---|---|
| POS | Dashboard kasir (`/pos/dashboard`), katalog cepat, keranjang, diskon, pajak, multi metode bayar (cash/QRIS/EDC/transfer/e-wallet), idempotency, pembatalan order, nota cetak berlogo |
| Kasir | Buka/tutup shift, modal awal, cash movement, rekap selisih kas |
| Katalog | Produk, SKU/barcode, kategori, pajak, harga jual/HPP, resep (BOM) |
| Inventori | Ledger append-only, saldo materialized, penyesuaian, transfer antar outlet, reorder point |
| Pembelian | Supplier, purchase order, penerimaan barang ke stok |
| Restoran | Meja, status meja, KDS, tiket dapur per item |
| CRM & Loyalty | Pelanggan, tier, poin, riwayat belanja |
| Laporan | KPI, tren harian, produk terlaris, mix pembayaran, per outlet, shift, ekspor CSV |
| AI Analytics | Ringkasan, forecast, anomali, reorder, RFM, market basket, menu engineering, tanya-jawab bahasa natural |
| SaaS Admin | Tenant, paket, fitur per paket, lisensi (issue/revoke/detail riwayat modul & kuota), invoice langganan, konfigurasi SMTP, pengingat email H-30/H-7, audit lintas tenant |
| Integrasi | REST API bertoken, webhook pembayaran bertanda tangan, print agent lokal, outbox event |
| Pengaturan | Profil tenant, pajak/service charge per outlet, logo toko untuk nota, preferensi stok, printer, token API |

Semua modul operasional melewati middleware `license`. Admin dapat memilih modul dan kuota per
lisensi saat menerbitkan (termasuk modul/kuota tambahan di luar paket); bila tidak diisi, paket
menjadi default. Lisensi aktif adalah penentu modul dan kuota. Override tenant hanya dapat
mematikan. Rincian ada di [docs/LISENSI.md](docs/LISENSI.md).

Notifikasi lisensi dihitung otomatis saat halaman dibuka (tanpa cron): kuning saat ≤ 30 hari,
merah saat ≤ 7 hari atau sudah kedaluwarsa. Notifikasi tampil di topbar/sidebar, dashboard owner,
halaman `/license`, dashboard POS, dan daftar lisensi Super Admin.

Logo toko diunggah di **Pengaturan → Logo toko** (PNG/JPG/WebP, maks. 2 MB) dan tampil di nota
browser serta payload print. Print agent ESC/POS tetap mencetak teks karena printer thermal tidak
menerima gambar tanpa driver raster tambahan.

Setelah login, kasir dan waiter diarahkan ke `/pos/dashboard` (omzet hari ini, status shift, aksi
cepat, transaksi terbaru, stok kritis), kitchen ke `/kds`, sedangkan owner/manager ke `/dashboard`.

## Stack

- PHP 8.3 (mysqli, curl, openssl, mbstring), tanpa framework — modular monolith dengan autoloader PSR-4 `App\`.
- MariaDB/MySQL 8 (InnoDB, DECIMAL untuk uang, UTC di database).
- Python 3.11+ **stdlib saja** untuk AI dan print agent (tanpa cloud, tanpa dependensi pip).
- Chart.js dan qrcodejs di-vendor lokal di `public/assets/vendor/`.

## Instalasi lokal

```bash
cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # isi APP_KEY
# sesuaikan DB_* dan APP_URL
php bin/install.php --host=127.0.0.1 --port=3306 --user=root --pass= --db=pos_saas
php bin/migrate.php   # untuk upgrade database lama; aman dijalankan berulang
```

Installer membuat database, seed, password Argon2id, lisensi demo, invoice, dan riwayat penjualan
120 hari. Gunakan `--force` hanya untuk database lokal (menghapus database target), `--no-history`
untuk instalasi tanpa data demo.

Jalankan:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

Untuk produksi gunakan Nginx/Apache + PHP-FPM dengan document root `public/` (`.htaccess` sudah
tersedia untuk Apache). Jangan menaruh folder proyek di document root.

### AI service

```bash
AI_SERVICE_TOKEN=<token-dari-.env> AI_SHARE_TOKEN=<share-token-dari-.env> python python/ai_service.py
```

Service mendengarkan `127.0.0.1:8765`. Aplikasi mengirim dataset tenant di body request analisis,
jadi tidak ada callback balik ke PHP dan service tetap bisa berjalan di mesin yang sama tanpa
kredensial database. Endpoint `/internal/ai/dataset` tetap tersedia untuk integrasi eksternal
dengan header `X-AI-Token: <AI_SHARE_TOKEN>`.

### Print agent

```bash
POS_BASE_URL=http://127.0.0.1:8080 DEVICE_KEY=<device-key-register> python python/print_agent.py
```

Agent hanya melakukan koneksi keluar, mengambil job dari `/api/v1/print-jobs`, mencetak ESC/POS,
lalu mengirim ack. Tidak membuka port masuk.

### Email & pengingat lisensi

Super Admin mengatur SMTP di `/admin/mail`: host, port, STARTTLS/SSL, AUTH LOGIN/PLAIN, username,
password, serta alamat dan nama pengirim. Nilai dari UI disimpan di `platform_settings` dan password
dienkripsi AES-256-GCM dengan `APP_KEY`. Alternatif `.env` (`SMTP_*`) tetap dibaca sebagai fallback;
nilai dari UI diutamakan. Halaman yang sama menyediakan tombol **Kirim Email Uji** dan riwayat
pengiriman.

Pengingat H-30 dan H-7 dijalankan oleh cron harian:

```bash
php bin/license_reminders.php --dry-run     # lihat kandidat tanpa mengirim
php bin/license_reminders.php               # kirim yang belum pernah sukses
```

Setiap kombinasi lisensi + ambang disimpan di `license_reminders` dengan unique key, sehingga
cron yang dijalankan berulang tidak mengirim email ganda. Pengiriman yang gagal dicatat dan dicoba
lagi pada eksekusi berikutnya. Contoh crontab (08:00):

```cron
0 8 * * * cd /path/ke/aplikasi && php bin/license_reminders.php >> storage/logs/reminders.log 2>&1
```

## Akun demo

| Peran | Email | Password |
|---|---|---|
| Owner | `owner@demo.id` | `Demo#12345` |
| Kasir | `kasir@demo.id` | `Demo#12345` |
| Manager | `manager@demo.id` | `Demo#12345` |
| Dapur | `dapur@demo.id` | `Demo#12345` |
| Super Admin SaaS | `admin@possaas.id` | `Admin#12345` |

Super Admin tidak terikat tenant dan dapat mengelola tenant, paket, lisensi, invoice, dan audit.

## Struktur

```
app/Core/              Config, Database (mysqli prepared), Router, Middleware, Auth, License,
                       Mailer, MailSettings, View
app/Modules/           Auth, Dashboard, POS, Cash, Catalog, Inventory, Purchasing, Restaurant,
                       CRM, Reports, AI, SaaS, Integrations
app/Infrastructure/    Payment gateway (Mock, Midtrans, Xendit), print
resources/views/       Layout app/admin/guest/pos/kds/print + halaman modul
routes/                web.php, api.php, webhook.php
database/              schema.sql (47 tabel + view), seed.sql
bin/                   install.php, migrate.php, seed_history.php, license_reminders.php
python/                analytics.py, ai_service.py, print_agent.py
public/                front controller, router dev, assets
storage/               log, cache AI, upload
```

Arsitektur dan titik ekstensi: [docs/ARSITEKTUR.md](docs/ARSITEKTUR.md).
REST API, webhook, dan AI: [docs/API.md](docs/API.md).

## Keamanan

- Prepared statement untuk seluruh query; transaksi + `FOR UPDATE` pada settlement pembayaran.
- CSRF token pada semua mutasi web; cookie sesi `HttpOnly`/`SameSite=Lax`, `Secure` saat HTTPS.
- Password Argon2id; rate limit login; audit log untuk aksi sensitif.
- Nota final hanya tersedia setelah pembayaran terverifikasi lunas.
- Webhook wajib signature valid dan idempoten melalui `webhook_inbox`.
- API token disimpan sebagai hash SHA-256, bukan plaintext.
- Lisensi ditandatangani HMAC-SHA256; kunci tidak pernah dikirim ke browser.
- Password SMTP disimpan terenkripsi AES-256-GCM; tidak pernah dikembalikan ke browser atau log.

## Status verifikasi

Diuji langsung pada instalasi lokal (MariaDB 11.4, PHP 8.3, Python 3.12) dengan 100+ assertion
fungsional:

- login owner/kasir/super admin, redirect, dan RBAC (`kasir` ditolak di `/users`, diterima di `/pos`);
- dashboard, POS, katalog, inventori, pembelian, restoran, KDS, CRM, laporan, AI, pengaturan,
  pengguna, lisensi, kas, dan seluruh halaman admin merespons 200;
- checkout cash: order `completed`, pembayaran `paid`, ledger stok resep, print job, nota 200;
- idempotency: checkout ulang dengan key sama mengembalikan `duplicate: true` tanpa transaksi ganda;
- katalog: kategori, produk baru, stok awal, penyesuaian (termasuk qty desimal), dan transfer antar outlet;
- pembelian: supplier, purchase order, penerimaan barang, update stok dan HPP moving average;
- restoran/KDS: meja, tiket dapur per station, transisi queued → preparing → ready, meja occupied;
- CRM/loyalty: pelanggan baru, poin dan tier bertambah setelah transaksi;
- pengguna: buat kasir, login kasir baru, nonaktifkan akun, login ditolak; RBAC kasir 403 di pembelian;
- kas: mutasi masuk, tutup shift dengan selisih 0, dan riwayat shift;
- lisensi: issue lisensi dengan pilihan modul dan kuota, modul di luar pilihan tertutup (403),
  kuota lisensi di atas paket berlaku, revoke lisensi memblokir transaksi (402) sampai lisensi baru
  diterbitkan;
- detail lisensi: riwayat multi-lisensi, diff modul (+/−), kuota per lisensi, dan timeline event
  issue/revoke/aktivasi; hanya Super Admin (owner mendapat 403);
- login rate limit: 5 percobaan salah mengunci akun selama 15 menit;
- QRIS: order `awaiting_payment`, QR string dibuat, nota 409; setelah simulasi lunas menjadi
  `completed` dan nota 200;
- webhook tanpa signature ditolak 401; REST API tanpa token 401 dan dengan token 200;
- feature gate: mematikan `restaurant` lewat override tenant membuat `/restaurant` 403, lalu 200
  setelah diaktifkan kembali;
- invoice SaaS dibuat dan ditandai lunas, langganan aktif kembali;
- seluruh endpoint AI (`summary`, `forecast`, `reorder`, `rfm`, `basket`, `menu`, `anomalies`, `ask`)
  merespons 200 dengan data seed;
- notifikasi lisensi: tanggal lisensi diubah ke H-5 dan H-1 lewat masa berlaku; topbar, dashboard,
  `/license`, dan tabel admin menampilkan level yang sesuai, lalu tanggal/status dipulihkan;
- email pengingat: SMTP lokal menangkap email uji dan pengingat H-30/H-7; subjek, penerima, isi,
  tanggal, dan link benar; eksekusi ulang melewati pengingat yang sudah sukses; kegagalan koneksi
  tercatat dan berhasil dikirim ulang setelah port SMTP diperbaiki; password tersimpan `enc:v1:`;
- logo toko: upload PNG tersimpan di `uploads/logos`, URL 200 `image/png`, muncul di nota 200,
  preview pengaturan tampil, lalu penghapusan menghapus file dan logo dari nota;
- redirect login: kasir → `/pos/dashboard`, dapur → `/kds`, owner → `/dashboard`; dashboard POS
  diakses owner dan kasir dengan 200;
- `php -l` seluruh file PHP dan `python -m py_compile` lolos.

Belum diuji: integrasi Midtrans/Xendit dengan kredensial sandbox nyata, printer thermal fisik
(logo raster pada ESC/POS), pengiriman ke provider email nyata (Gmail/SES/Mailgun), dan beban
produksi multi-worker. Konfigurasi gateway sudah tersedia tetapi
default memakai provider `mock`. UI refund belum tersedia; antarmuka `refund()` sudah ada di
setiap adapter gateway dan tabel `refunds` sudah disiapkan untuk pengembangan berikutnya.

## Pengembangan lanjutan

Titik ekstensi utama: gateway pembayaran baru (`app/Infrastructure/Payments`), modul baru
(`app/Modules`) dengan route + permission + feature code, laporan baru (`ReportService`), dan
model AI baru (`python/analytics.py`). Lisensi modul baru cukup ditambahkan di `License::MODULES`
dan `plan_features`.
