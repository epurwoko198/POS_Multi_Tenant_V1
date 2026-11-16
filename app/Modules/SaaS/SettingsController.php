<?php
declare(strict_types=1);

namespace App\Modules\SaaS;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\License;
use App\Modules\Audit\AuditLog;

final class SettingsController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('settings.manage');
        $tenantId = $this->tenantId();
        $tenant = Database::one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        $outlets = Database::all('SELECT * FROM outlets WHERE tenant_id = ? ORDER BY name', [$tenantId]);
        $settings = Database::all('SELECT skey, svalue FROM settings WHERE tenant_id = ?', [$tenantId]);
        $settingsMap = [];
        foreach ($settings as $s) {
            $settingsMap[$s['skey']] = $s['svalue'];
        }
        $tokens = Database::all('SELECT id, name, scopes, last_used_at, created_at FROM api_tokens WHERE tenant_id = ? ORDER BY id DESC', [$tenantId]);
        $this->view('settings/index', [
            'title' => 'Pengaturan',
            'tenant' => $tenant,
            'outlets' => $outlets,
            'settingsMap' => $settingsMap,
            'tokens' => $tokens,
            'logoUrl' => tenant_logo_url($tenantId),
            'apiEnabled' => License::enabled('api'),
            'issuedToken' => $_SESSION['_issued_token'] ?? null,
        ]);
        unset($_SESSION['_issued_token']);
    }

    public function update(): void
    {
        $this->requirePermission('settings.manage');
        $tenantId = $this->tenantId();
        $before = Database::one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        Database::update('tenants', [
            'name' => trim((string) $this->input('name', $before['name'])),
            'email' => trim((string) $this->input('email', '')) ?: null,
            'phone' => trim((string) $this->input('phone', '')) ?: null,
            'address' => trim((string) $this->input('address', '')) ?: null,
            'timezone' => (string) $this->input('timezone', 'Asia/Jakarta'),
        ], 'id = ?', [$tenantId]);
        $outletId = $this->intInput('outlet_id');
        if ($outletId > 0) {
            $outlet = Database::one('SELECT * FROM outlets WHERE id = ? AND tenant_id = ?', [$outletId, $tenantId]);
            if ($outlet) {
                Database::update('outlets', [
                    'tax_rate' => max(0, min(100, $this->floatInput('tax_rate'))),
                    'service_charge_rate' => max(0, min(100, $this->floatInput('service_charge_rate'))),
                    'receipt_footer' => substr((string) $this->input('receipt_footer', ''), 0, 250),
                ], 'id = ? AND tenant_id = ?', [$outletId, $tenantId]);
                AuditLog::log('outlet_settings_updated', 'outlet', (string) $outletId, $outlet, [
                    'tax_rate' => $this->floatInput('tax_rate'),
                    'service_charge_rate' => $this->floatInput('service_charge_rate'),
                ]);
            }
        }
        foreach (['receipt_footer', 'loyalty_points_per_1000', 'negative_stock_policy', 'default_printer'] as $key) {
            $value = $this->input($key);
            if ($value === null) {
                continue;
            }
            Database::execute(
                'INSERT INTO settings (tenant_id, skey, svalue) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
                [$tenantId, $key, (string) $value]
            );
        }
        try {
            $this->updateStoreLogo($tenantId);
        } catch (\Throwable $e) {
            flash('error', 'Logo tidak disimpan: ' . $e->getMessage());
        }
        AuditLog::log('tenant_updated', 'tenant', (string) $tenantId, $before, ['name' => $this->input('name')]);
        flash('success', 'Pengaturan disimpan.');
        $this->redirect('/settings');
    }

    private function updateStoreLogo(int $tenantId): void
    {
        $row = Database::one("SELECT svalue FROM settings WHERE tenant_id = ? AND skey = 'store_logo'", [$tenantId]);
        $current = $row['svalue'] ?? null;

        if ((string) $this->input('remove_logo', '') === '1') {
            $this->deleteLogoFile($current);
            Database::execute("DELETE FROM settings WHERE tenant_id = ? AND skey = 'store_logo'", [$tenantId]);
            AuditLog::log('store_logo_removed', 'tenant', (string) $tenantId);
            $current = null;
        }

        $file = $_FILES['store_logo'] ?? null;
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload gagal (kode ' . (int) $file['error'] . ').');
        }
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            throw new \RuntimeException('Ukuran logo maksimal 2 MB.');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('Berkas upload tidak valid.');
        }
        $mime = function_exists('finfo_open')
            ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name'])
            : (string) mime_content_type((string) $file['tmp_name']);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            throw new \RuntimeException('Format harus PNG, JPG, atau WebP.');
        }
        $dir = BASE_PATH . '/public/uploads/logos';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Folder logo tidak dapat dibuat.');
        }
        $filename = 'logo-' . $tenantId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $filename)) {
            throw new \RuntimeException('Logo gagal disimpan.');
        }
        $relative = 'uploads/logos/' . $filename;
        Database::execute(
            'INSERT INTO settings (tenant_id, skey, svalue) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            [$tenantId, 'store_logo', $relative]
        );
        $this->deleteLogoFile($current);
        AuditLog::log('store_logo_updated', 'tenant', (string) $tenantId, null, ['path' => $relative]);
    }

    private function deleteLogoFile(?string $relative): void
    {
        if (!$relative || !preg_match('#^uploads/logos/[A-Za-z0-9._-]+$#', $relative)) {
            return;
        }
        $file = BASE_PATH . '/public/' . $relative;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function storeOutlet(): void
    {
        $this->requirePermission('settings.manage');
        $tenantId = $this->tenantId();
        $name = trim((string) $this->input('outlet_name', ''));
        $code = strtoupper(trim((string) $this->input('outlet_code', '')));
        if ($name === '' || $code === '') {
            flash('error', 'Nama dan kode outlet wajib diisi.');
            $this->redirect('/settings');
        }
        $address = trim((string) $this->input('outlet_address', ''));
        $phone = trim((string) $this->input('outlet_phone', ''));
        $timezone = (string) $this->input('timezone', 'Asia/Jakarta');
        $taxRate = max(0, min(100, $this->floatInput('outlet_tax')));
        try {
            $outletId = Database::tx(static function () use ($tenantId, $name, $code, $address, $phone, $timezone, $taxRate) {
                License::assertLimit('limit_outlets');
                $newOutletId = Database::insert('outlets', [
                    'public_id' => ulid(),
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'address' => $address,
                    'phone' => $phone,
                    'timezone' => $timezone,
                    'tax_rate' => $taxRate,
                    'service_charge_rate' => 0,
                    'receipt_footer' => 'Terima kasih telah berbelanja.',
                    'is_active' => 1,
                    'created_at' => now_utc(),
                ]);
                License::assertLimit('limit_registers');
                Database::insert('registers', [
                    'tenant_id' => $tenantId,
                    'outlet_id' => $newOutletId,
                    'code' => 'REG-01',
                    'name' => 'Kasir 1',
                    'device_key' => bin2hex(random_bytes(32)),
                    'is_active' => 1,
                    'created_at' => now_utc(),
                ]);
                return $newOutletId;
            });
            AuditLog::log('outlet_created', 'outlet', (string) $outletId, null, ['name' => $name, 'code' => $code]);
            flash('success', 'Outlet baru ditambahkan.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $this->redirect('/settings');
    }

    public function createToken(): void
    {
        $this->requirePermission('settings.manage');
        if (!License::enabled('api')) {
            flash('error', 'Modul API tidak aktif pada lisensi Anda.');
            $this->redirect('/settings');
        }
        $name = trim((string) $this->input('token_name', 'Integrasi'));
        $plain = 'pos_' . bin2hex(random_bytes(24));
        Database::insert('api_tokens', [
            'tenant_id' => $this->tenantId(),
            'name' => $name ?: 'Integrasi',
            'token_hash' => hash('sha256', $plain),
            'scopes' => '*',
            'created_at' => now_utc(),
        ]);
        $_SESSION['_issued_token'] = $plain;
        AuditLog::log('api_token_created', 'api_token', null, null, ['name' => $name]);
        flash('success', 'Token API dibuat. Salin sekarang, token hanya ditampilkan sekali.');
        $this->redirect('/settings');
    }

    public function revokeToken(string $id): void
    {
        $this->requirePermission('settings.manage');
        Database::execute('DELETE FROM api_tokens WHERE id = ? AND tenant_id = ?', [(int) $id, $this->tenantId()]);
        AuditLog::log('api_token_revoked', 'api_token', $id);
        flash('success', 'Token API dicabut.');
        $this->redirect('/settings');
    }
}
