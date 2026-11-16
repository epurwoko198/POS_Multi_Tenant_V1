<?php
declare(strict_types=1);

namespace App\Modules\SaaS;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\License;
use App\Core\Mailer;
use App\Core\MailSettings;
use App\Modules\Audit\AuditLog;

final class AdminController extends Controller
{
    public function index(): void
    {
        $stats = [
            'tenants' => (int) Database::value('SELECT COUNT(*) FROM tenants', [], 0),
            'active' => (int) Database::value("SELECT COUNT(*) FROM tenants WHERE status IN ('active','trial','grace')", [], 0),
            'trial' => (int) Database::value("SELECT COUNT(*) FROM tenants WHERE status = 'trial'", [], 0),
            'suspended' => (int) Database::value("SELECT COUNT(*) FROM tenants WHERE status = 'suspended'", [], 0),
            'mrr' => (float) Database::value(
                "SELECT COALESCE(SUM(p.price_monthly),0) FROM tenants t JOIN plans p ON p.id = t.plan_id WHERE t.status IN ('active','grace')",
                [],
                0
            ),
            'licenses' => (int) Database::value("SELECT COUNT(*) FROM licenses WHERE status = 'active'", [], 0),
            'expiring' => (int) Database::value(
                "SELECT COUNT(*) FROM licenses WHERE status = 'active' AND expires_at BETWEEN UTC_TIMESTAMP(3) AND DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 30 DAY)",
                [],
                0
            ),
            'revenue' => (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM subscription_invoices WHERE status = 'paid'", [], 0),
        ];
        $tenants = Database::all(
            'SELECT t.*, p.name AS plan_name, p.code AS plan_code,
                    (SELECT status FROM subscriptions s WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS sub_status,
                    (SELECT expires_at FROM licenses l WHERE l.tenant_id = t.id AND l.status = "active" ORDER BY l.id DESC LIMIT 1) AS license_expires,
                    (SELECT COUNT(*) FROM outlets o WHERE o.tenant_id = t.id) AS outlets,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS users
             FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id ORDER BY t.id DESC LIMIT 25'
        );
        $expiringLicenses = Database::all(
            "SELECT l.id, l.key_id, l.plan_code, l.expires_at, t.id AS tenant_id, t.name AS tenant_name,
                    TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(3), l.expires_at) AS days_left
             FROM licenses l JOIN tenants t ON t.id = l.tenant_id
             WHERE l.status IN ('active', 'expired')
               AND l.expires_at BETWEEN DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 60 DAY) AND DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 30 DAY)
             ORDER BY l.expires_at ASC LIMIT 20"
        );
        $byPlan = Database::all('SELECT p.name, COUNT(t.id) AS total FROM plans p LEFT JOIN tenants t ON t.plan_id = p.id GROUP BY p.id, p.name ORDER BY p.sort_order');
        $audit = Database::all(
            'SELECT a.*, u.name AS user_name, t.name AS tenant_name FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tenants t ON t.id = a.tenant_id
             ORDER BY a.id DESC LIMIT 12'
        );
        $mailConfig = MailSettings::all();
        $this->view('admin/index', [
            'title' => 'Platform Overview',
            'stats' => $stats,
            'tenants' => $tenants,
            'byPlan' => $byPlan,
            'audit' => $audit,
            'expiringLicenses' => $expiringLicenses,
            'mailReady' => MailSettings::isConfigured($mailConfig),
        ], 'admin');
    }

    public function tenants(): void
    {
        $tenants = Database::all(
            'SELECT t.*, p.name AS plan_name, p.code AS plan_code,
                    (SELECT expires_at FROM licenses l WHERE l.tenant_id = t.id AND l.status = "active" ORDER BY l.id DESC LIMIT 1) AS license_expires,
                    (SELECT COUNT(*) FROM outlets o WHERE o.tenant_id = t.id) AS outlets,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS users,
                    (SELECT COUNT(*) FROM sales_orders so WHERE so.tenant_id = t.id) AS orders
             FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id ORDER BY t.id DESC'
        );
        $plans = Database::all('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order');
        $this->view('admin/tenants', [
            'title' => 'Tenant',
            'tenants' => $tenants,
            'plans' => $plans,
            'newPassword' => $_SESSION['_new_tenant_password'] ?? null,
        ], 'admin');
        unset($_SESSION['_new_tenant_password']);
    }

    public function storeTenant(): void
    {
        $name = trim((string) $this->input('name', ''));
        $slug = strtolower(trim((string) $this->input('slug', '')));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug) ?: '';
        $planId = $this->intInput('plan_id');
        $plan = Database::one('SELECT * FROM plans WHERE id = ? AND is_active = 1', [$planId]);
        $ownerName = trim((string) $this->input('owner_name', ''));
        $ownerEmail = strtolower(trim((string) $this->input('owner_email', '')));
        if ($name === '' || $slug === '' || !$plan || $ownerName === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Data tenant tidak lengkap atau tidak valid.');
            $this->redirect('/admin/tenants');
        }
        try {
            $password = 'Tnt-' . bin2hex(random_bytes(4));
            $result = Database::tx(static function () use ($name, $slug, $plan, $planId, $ownerName, $ownerEmail, $password) {
                $tenantId = Database::insert('tenants', [
                    'public_id' => ulid(),
                    'name' => $name,
                    'slug' => $slug,
                    'email' => $ownerEmail,
                    'currency' => 'IDR',
                    'timezone' => 'Asia/Jakarta',
                    'status' => 'trial',
                    'plan_id' => $planId,
                    'trial_ends_at' => gmdate('Y-m-d H:i:s', time() + ((int) $plan['trial_days'] * 86400)),
                    'created_at' => now_utc(),
                    'updated_at' => now_utc(),
                ]);
                $outletId = Database::insert('outlets', [
                    'public_id' => ulid(),
                    'tenant_id' => $tenantId,
                    'code' => 'OUT-01',
                    'name' => 'Outlet Utama',
                    'timezone' => 'Asia/Jakarta',
                    'tax_rate' => 11,
                    'service_charge_rate' => 0,
                    'receipt_footer' => 'Terima kasih telah berbelanja.',
                    'is_active' => 1,
                    'created_at' => now_utc(),
                ]);
                Database::insert('warehouses', [
                    'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'code' => 'WH-01', 'name' => 'Gudang Utama', 'is_default' => 1,
                ]);
                Database::insert('registers', [
                    'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'code' => 'REG-01', 'name' => 'Kasir 1',
                    'device_key' => bin2hex(random_bytes(32)), 'is_active' => 1, 'created_at' => now_utc(),
                ]);
                $role = Database::one("SELECT id FROM roles WHERE code = 'owner'");
                Database::insert('users', [
                    'public_id' => ulid(),
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'role_id' => (int) $role['id'],
                    'name' => $ownerName,
                    'email' => $ownerEmail,
                    'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
                    'status' => 'active',
                    'created_at' => now_utc(),
                    'updated_at' => now_utc(),
                ]);
                Database::insert('subscriptions', [
                    'public_id' => ulid(),
                    'tenant_id' => $tenantId,
                    'plan_id' => $planId,
                    'status' => 'trial',
                    'billing_cycle' => 'monthly',
                    'started_at' => now_utc(),
                    'current_period_start' => now_utc(),
                    'current_period_end' => gmdate('Y-m-d H:i:s', time() + ((int) $plan['trial_days'] * 86400)),
                    'created_at' => now_utc(),
                ]);
                Database::insert('settings', [
                    'tenant_id' => $tenantId, 'skey' => 'loyalty_points_per_1000', 'svalue' => '1',
                ]);
                AuditLog::log('tenant_created', 'tenant', (string) $tenantId, null, ['name' => $name, 'plan' => $plan['code']], $tenantId);
                return ['tenant_id' => $tenantId, 'password' => $password];
            });
            License::issue($result['tenant_id'], $planId, max(1, (int) $plan['trial_days']), null, Auth::id());
            $_SESSION['_new_tenant_password'] = $result['password'];
            flash('success', 'Tenant ' . $name . ' dibuat beserta lisensi ' . $plan['name'] . '.');
        } catch (\Throwable $e) {
            flash('error', 'Gagal membuat tenant: ' . $e->getMessage());
        }
        $this->redirect('/admin/tenants');
    }

    public function plans(): void
    {
        $plans = Database::all('SELECT * FROM plans ORDER BY sort_order');
        $features = [];
        $limits = [];
        foreach ($plans as $plan) {
            foreach (Database::all('SELECT * FROM plan_features WHERE plan_id = ?', [(int) $plan['id']]) as $f) {
                if (str_starts_with($f['feature_code'], 'limit_')) {
                    $limits[(int) $plan['id']][$f['feature_code']] = $f['limit_value'];
                } else {
                    $features[(int) $plan['id']][$f['feature_code']] = (int) $f['enabled'] === 1;
                }
            }
        }
        $this->view('admin/plans', [
            'title' => 'Paket & Feature Flags',
            'plans' => $plans,
            'features' => $features,
            'limits' => $limits,
            'modules' => License::MODULES,
            'limitLabels' => License::LIMITS,
        ], 'admin');
    }

    public function savePlan(string $id): void
    {
        $planId = (int) $id;
        $plan = Database::one('SELECT * FROM plans WHERE id = ?', [$planId]);
        if (!$plan) {
            flash('error', 'Paket tidak ditemukan.');
            $this->redirect('/admin/plans');
        }
        Database::update('plans', [
            'name' => trim((string) $this->input('name', $plan['name'])),
            'price_monthly' => max(0, $this->floatInput('price_monthly')),
            'price_yearly' => max(0, $this->floatInput('price_yearly')),
            'trial_days' => max(0, $this->intInput('trial_days')),
            'is_active' => $this->input('is_active') ? 1 : 0,
        ], 'id = ?', [$planId]);
        $modules = (array) ($_POST['modules'] ?? []);
        foreach (array_keys(License::MODULES) as $code) {
            $enabled = isset($modules[$code]) ? 1 : 0;
            Database::execute(
                'INSERT INTO plan_features (plan_id, feature_code, enabled, limit_value) VALUES (?,?,?,NULL)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                [$planId, $code, $enabled]
            );
        }
        $limits = (array) ($_POST['limits'] ?? []);
        foreach (array_keys(License::LIMITS) as $key) {
            $value = isset($limits[$key]) && $limits[$key] !== '' ? (int) $limits[$key] : -1;
            Database::execute(
                'INSERT INTO plan_features (plan_id, feature_code, enabled, limit_value) VALUES (?,?,1,?)
                 ON DUPLICATE KEY UPDATE limit_value = VALUES(limit_value)',
                [$planId, $key, $value]
            );
        }
        AuditLog::log('plan_updated', 'plan', (string) $planId, $plan, ['modules' => $modules, 'limits' => $limits]);
        flash('success', 'Paket ' . $plan['name'] . ' diperbarui.');
        $this->redirect('/admin/plans');
    }

    public function licenses(): void
    {
        $licenses = Database::all(
            'SELECT l.*, t.name AS tenant_name FROM licenses l JOIN tenants t ON t.id = l.tenant_id ORDER BY l.id DESC LIMIT 100'
        );
        $tenants = Database::all('SELECT id, name, plan_id FROM tenants ORDER BY name');
        $plans = Database::all('SELECT id, name FROM plans WHERE is_active = 1 ORDER BY sort_order');
        $this->view('admin/licenses', [
            'title' => 'Lisensi',
            'licenses' => $licenses,
            'tenants' => $tenants,
            'plans' => $plans,
            'modules' => License::MODULES,
            'limits' => License::LIMITS,
            'issued' => $_SESSION['_issued_license'] ?? null,
        ], 'admin');
        unset($_SESSION['_issued_license']);
    }

    public function licenseDetail(string $id): void
    {
        $license = Database::one(
            'SELECT l.*, t.name AS tenant_name, t.slug, t.status AS tenant_status
             FROM licenses l JOIN tenants t ON t.id = l.tenant_id WHERE l.id = ?',
            [(int) $id]
        );
        if (!$license) {
            flash('error', 'Lisensi tidak ditemukan.');
            $this->redirect('/admin/licenses');
        }
        $tenantId = (int) $license['tenant_id'];
        $tenant = Database::one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        $licenses = Database::all('SELECT * FROM licenses WHERE tenant_id = ? ORDER BY id ASC', [$tenantId]);

        $history = [];
        $previousModules = null;
        foreach ($licenses as $row) {
            $moduleMap = json_decode((string) $row['features_json'], true) ?: [];
            $enabled = array_keys(array_filter($moduleMap));
            $added = $previousModules === null ? [] : array_values(array_diff($enabled, $previousModules));
            $removed = $previousModules === null ? [] : array_values(array_diff($previousModules, $enabled));
            $limitMap = json_decode((string) $row['limits_json'], true) ?: [];
            $history[] = $row + [
                'module_map' => $moduleMap,
                'enabled_modules' => $enabled,
                'added_modules' => $added,
                'removed_modules' => $removed,
                'is_first' => $previousModules === null,
                'limit_map' => $limitMap,
            ];
            $previousModules = $enabled;
        }

        $events = Database::all(
            'SELECT e.*, u.name AS actor_name, l.key_id, l.plan_code
             FROM license_events e
             LEFT JOIN users u ON u.id = e.actor_id
             LEFT JOIN licenses l ON l.id = e.license_id
             WHERE e.tenant_id = ? ORDER BY e.id DESC LIMIT 100',
            [$tenantId]
        );
        foreach ($events as &$event) {
            $event['meta'] = json_decode((string) $event['meta_json'], true) ?: [];
        }
        unset($event);

        $context = License::context($tenantId);
        $current = Database::one(
            "SELECT * FROM licenses WHERE tenant_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$tenantId]
        );

        $this->view('admin/license_detail', [
            'title' => 'Detail Lisensi',
            'license' => $license,
            'tenant' => $tenant,
            'history' => $history,
            'events' => $events,
            'context' => $context,
            'current' => $current,
            'modules' => License::MODULES,
            'limits' => License::LIMITS,
        ], 'admin');
    }

    public function issueLicense(): void
    {
        $tenantId = $this->intInput('tenant_id');
        $planId = $this->intInput('plan_id');
        $days = max(1, min(3650, $this->intInput('days', 365)));
        $domain = trim((string) $this->input('domain', '')) ?: null;
        $moduleInput = $_POST['modules'] ?? [];
        $selectedModules = [];
        if (is_array($moduleInput)) {
            foreach (array_keys($moduleInput) as $code) {
                if (isset(License::MODULES[(string) $code]) && !empty($moduleInput[$code])) {
                    $selectedModules[] = (string) $code;
                }
            }
        }
        $limitInput = $_POST['limits'] ?? [];
        $limitOverrides = [];
        if (is_array($limitInput)) {
            foreach (array_keys(License::LIMITS) as $key) {
                if (isset($limitInput[$key]) && is_numeric($limitInput[$key])) {
                    $limitOverrides[$key] = max(-1, min(1000000, (int) $limitInput[$key]));
                }
            }
        }
        try {
            $license = License::issue(
                $tenantId,
                $planId,
                $days,
                $domain,
                Auth::id(),
                $selectedModules === [] ? null : $selectedModules,
                $limitOverrides === [] ? null : $limitOverrides
            );
            $_SESSION['_issued_license'] = $license;
            flash('success', 'Lisensi diterbitkan dan langsung aktif. Token tersedia di bawah.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $this->redirect('/admin/licenses');
    }

    public function revokeLicense(string $id): void
    {
        try {
            License::revoke((int) $id, substr((string) $this->input('reason', 'Dicabut administrator'), 0, 250));
            flash('success', 'Lisensi dicabut.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $this->redirect('/admin/licenses');
    }

    public function invoices(): void
    {
        $invoices = Database::all(
            'SELECT i.*, t.name AS tenant_name FROM subscription_invoices i JOIN tenants t ON t.id = i.tenant_id ORDER BY i.id DESC LIMIT 100'
        );
        $tenants = Database::all('SELECT id, name FROM tenants ORDER BY name');
        $plans = Database::all('SELECT * FROM plans WHERE is_active = 1');
        $this->view('admin/invoices', [
            'title' => 'Invoice SaaS',
            'invoices' => $invoices,
            'tenants' => $tenants,
            'plans' => $plans,
        ], 'admin');
    }

    public function storeInvoice(): void
    {
        $tenantId = $this->intInput('tenant_id');
        $tenant = Database::one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        $amount = max(0, $this->floatInput('amount'));
        if (!$tenant || $amount <= 0) {
            flash('error', 'Tenant dan nominal invoice wajib diisi.');
            $this->redirect('/admin/invoices');
        }
        $number = 'INV-SAAS-' . gmdate('Ym') . '-' . strtoupper(substr(ulid(), -4));
        Database::insert('subscription_invoices', [
            'public_id' => ulid(),
            'tenant_id' => $tenantId,
            'subscription_id' => (int) (Database::value('SELECT id FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1', [$tenantId], 0)),
            'number' => $number,
            'amount' => $amount,
            'status' => 'open',
            'period_start' => now_utc(),
            'period_end' => gmdate('Y-m-d H:i:s', strtotime('+1 month')),
            'due_date' => gmdate('Y-m-d', strtotime('+7 days')),
            'created_at' => now_utc(),
        ]);
        AuditLog::log('saas_invoice_created', 'subscription_invoice', null, null, ['tenant' => $tenantId, 'amount' => $amount]);
        flash('success', 'Invoice ' . $number . ' dibuat.');
        $this->redirect('/admin/invoices');
    }

    public function payInvoice(string $id): void
    {
        $invoice = Database::one('SELECT * FROM subscription_invoices WHERE id = ?', [(int) $id]);
        if (!$invoice) {
            flash('error', 'Invoice tidak ditemukan.');
            $this->redirect('/admin/invoices');
        }
        Database::execute("UPDATE subscription_invoices SET status = 'paid', paid_at = ? WHERE id = ?", [now_utc(), (int) $id]);
        Database::execute(
            "UPDATE subscriptions SET status = 'active', current_period_start = ?, current_period_end = ?, grace_until = NULL WHERE tenant_id = ? ORDER BY id DESC LIMIT 1",
            [now_utc(), gmdate('Y-m-d H:i:s', strtotime('+1 month')), (int) $invoice['tenant_id']]
        );
        Database::execute("UPDATE tenants SET status = 'active' WHERE id = ?", [(int) $invoice['tenant_id']]);
        AuditLog::log('saas_invoice_paid', 'subscription_invoice', (string) $id, ['status' => $invoice['status']], ['status' => 'paid']);
        flash('success', 'Invoice ditandai lunas dan langganan diperpanjang.');
        $this->redirect('/admin/invoices');
    }

    public function mail(): void
    {
        $config = MailSettings::all(true);
        $this->view('admin/mail', [
            'title' => 'Email & SMTP',
            'config' => $config,
            'missing' => MailSettings::missing($config),
            'passwordSet' => $config['password'] !== '' || MailSettings::passwordStored(),
            'reminders' => LicenseReminderService::recent(30),
        ], 'admin');
    }

    public function saveMail(): void
    {
        $enabled = $this->input('enabled') !== null;
        $host = trim((string) $this->input('host', ''));
        $port = $this->intInput('port', 587);
        $encryption = (string) $this->input('encryption', 'tls');
        $auth = (string) $this->input('auth', 'login');
        $username = trim((string) $this->input('username', ''));
        $password = (string) $this->raw('password', '');
        $clearPassword = $this->input('clear_password') !== null;
        $fromEmail = strtolower(trim((string) $this->input('from_email', '')));
        $fromName = trim((string) $this->input('from_name', ''));
        $errors = [];
        if ($enabled && $host === '') {
            $errors[] = 'SMTP host wajib diisi saat fitur diaktifkan.';
        }
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port SMTP harus 1–65535.';
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            $errors[] = 'Enkripsi SMTP tidak valid.';
        }
        if (!in_array($auth, ['login', 'plain'], true)) {
            $errors[] = 'Mode autentikasi SMTP tidak valid.';
        }
        if ($enabled && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email pengirim wajib valid saat fitur diaktifkan.';
        }
        if ($clearPassword && $username !== '') {
            $errors[] = 'Kosongkan username jika SMTP tidak memakai autentikasi.';
        }
        if ($username !== '' && $password === '' && !$clearPassword && !MailSettings::passwordStored() && (string) MailSettings::all(true)['password'] === '') {
            $errors[] = 'Password SMTP wajib diisi untuk username baru.';
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
            $this->redirect('/admin/mail');
        }
        try {
            MailSettings::save([
                'enabled' => $enabled,
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'auth' => $auth,
                'username' => $username,
                'password' => $password,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
            ], $clearPassword);
            AuditLog::log('smtp_settings_updated', 'platform', null, null, [
                'enabled' => $enabled,
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'from_email' => $fromEmail,
                'password_changed' => $password !== '' || $clearPassword,
            ]);
            flash('success', 'Konfigurasi SMTP disimpan.');
        } catch (\Throwable $e) {
            flash('error', 'Gagal menyimpan SMTP: ' . $e->getMessage());
        }
        $this->redirect('/admin/mail');
    }

    public function testMail(): void
    {
        $to = strtolower(trim((string) $this->input('recipient', '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Email tujuan tes tidak valid.');
            $this->redirect('/admin/mail');
        }
        try {
            $config = MailSettings::all(true);
            if (!$config['enabled']) {
                throw new \RuntimeException('Aktifkan SMTP terlebih dahulu.');
            }
            $mailer = new Mailer($config);
            $app = e((string) \App\Core\Config::get('APP_NAME', 'Berkah POS'));
            $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:20px auto;padding:24px;border:1px solid #e6eaf2;border-radius:12px">'
                . '<h2 style="margin:0 0 10px">SMTP berhasil terhubung</h2>'
                . '<p style="line-height:1.6">Email ini dikirim dari <strong>' . $app . '</strong> untuk menguji konfigurasi SMTP platform.</p>'
                . '<p style="color:#64748b;font-size:13px">Waktu server: ' . e(now_utc()) . ' UTC</p></div>';
            $text = "SMTP berhasil terhubung.\n\nEmail ini dikirim dari " . $app . " untuk menguji konfigurasi SMTP platform.\nWaktu server: " . now_utc() . " UTC";
            $mailer->send($to, 'Tes SMTP · ' . $app, $html, $text);
            AuditLog::log('smtp_test_sent', 'platform', null, null, ['recipient' => $to]);
            flash('success', 'Email uji berhasil dikirim ke ' . $to . '.');
        } catch (\Throwable $e) {
            AuditLog::log('smtp_test_failed', 'platform', null, null, ['recipient' => $to, 'error' => substr($e->getMessage(), 0, 240)]);
            flash('error', 'Email uji gagal: ' . $e->getMessage());
        }
        $this->redirect('/admin/mail');
    }

    public function audit(): void
    {
        $logs = Database::all(
            'SELECT a.*, u.name AS user_name, t.name AS tenant_name FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id LEFT JOIN tenants t ON t.id = a.tenant_id
             ORDER BY a.id DESC LIMIT 300'
        );
        $this->view('admin/audit', ['title' => 'Audit Lintas Tenant', 'logs' => $logs], 'admin');
    }

    public function tenantDetail(string $id): void
    {
        $tenantId = (int) $id;
        $tenant = Database::one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        if (!$tenant) {
            flash('error', 'Tenant tidak ditemukan.');
            $this->redirect('/admin/tenants');
        }
        $features = [];
        foreach (Database::all('SELECT * FROM tenant_features WHERE tenant_id = ?', [$tenantId]) as $f) {
            $features[$f['feature_code']] = (int) $f['enabled'] === 1;
        }
        $ctx = License::context($tenantId);
        $outlets = Database::all('SELECT * FROM outlets WHERE tenant_id = ?', [$tenantId]);
        $users = Database::all('SELECT id, name, email, status FROM users WHERE tenant_id = ?', [$tenantId]);
        $this->view('admin/tenant', [
            'title' => 'Detail Tenant',
            'tenant' => $tenant,
            'features' => $features,
            'context' => $ctx,
            'outlets' => $outlets,
            'users' => $users,
            'modules' => License::MODULES,
        ], 'admin');
    }

    public function setFeatures(string $id): void
    {
        $tenantId = (int) $id;
        $modules = (array) ($_POST['modules'] ?? []);
        foreach (array_keys(License::MODULES) as $code) {
            $enabled = isset($modules[$code]) ? 1 : 0;
            Database::execute(
                'INSERT INTO tenant_features (tenant_id, feature_code, enabled) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                [$tenantId, $code, $enabled]
            );
        }
        AuditLog::log('tenant_features_updated', 'tenant', (string) $tenantId, null, ['modules' => $modules], $tenantId);
        flash('success', 'Modul tenant diperbarui.');
        $this->redirect('/admin/tenants/' . $tenantId);
    }
}
