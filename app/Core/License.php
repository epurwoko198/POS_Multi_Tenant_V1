<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * SaaS license engine.
 *
 * Entitlement model:
 *   1. Without an active license, the tenant's plan (plan_features) is the default.
 *   2. An active signed license (licenses) is authoritative for modules, so the
 *      platform can grant add-on modules beyond the plan or restrict a tenant.
 *   3. Plan and license limits are combined with the strictest value.
 * Tenant-level overrides (tenant_features) can only disable, never enable.
 *
 * Token format: base64url(json).base64url(HMAC-SHA256(payload, key))
 * Claims: lid, tid, plan, modules{code:bool}, limits{key:int}, iat, exp, kid, domain
 */
final class License
{
    public const MODULES = [
        'pos' => 'POS & Penjualan',
        'catalog' => 'Katalog Produk',
        'inventory' => 'Inventori & Stok',
        'purchasing' => 'Pembelian & Supplier',
        'restaurant' => 'Restoran & Meja',
        'kds' => 'Kitchen Display',
        'crm' => 'CRM Pelanggan',
        'loyalty' => 'Loyalty & Poin',
        'reports' => 'Laporan',
        'reports_advanced' => 'Laporan Lanjutan',
        'ai' => 'AI Analytics',
        'api' => 'REST API',
        'print' => 'Print / Nota',
        'multi_outlet' => 'Multi Outlet',
    ];

    public const LIMITS = [
        'limit_outlets' => 'Outlet',
        'limit_users' => 'Pengguna',
        'limit_products' => 'Produk',
        'limit_registers' => 'Register',
        'limit_tx_month' => 'Transaksi / bulan',
    ];

    private static array $contexts = [];
    private static array $expiryNotices = [];

    public static function context(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? Auth::tenantId();
        if (isset(self::$contexts[$tenantId])) {
            return self::$contexts[$tenantId];
        }
        $all = array_fill_keys(array_keys(self::MODULES), true);
        $unlimited = array_fill_keys(array_keys(self::LIMITS), -1);

        if ($tenantId <= 0) {
            return self::$contexts[$tenantId] = [
                'tenant' => null, 'plan' => null, 'subscription' => null, 'license' => null,
                'features' => $all, 'limits' => $unlimited, 'status' => 'platform',
                'can_transact' => true, 'message' => 'Super Admin', 'expires_at' => null,
            ];
        }

        $tenant = Database::one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        if (!$tenant) {
            return self::$contexts[$tenantId] = [
                'tenant' => null, 'plan' => null, 'subscription' => null, 'license' => null,
                'features' => [], 'limits' => array_fill_keys(array_keys(self::LIMITS), 0),
                'status' => 'missing', 'can_transact' => false,
                'message' => 'Tenant tidak ditemukan', 'expires_at' => null,
            ];
        }
        $plan = $tenant['plan_id'] ? Database::one('SELECT * FROM plans WHERE id = ?', [(int) $tenant['plan_id']]) : null;
        $subscription = Database::one(
            'SELECT * FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1',
            [$tenantId]
        );
        $license = Database::one(
            "SELECT * FROM licenses WHERE tenant_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$tenantId]
        );
        if ($license && strtotime($license['expires_at'] . ' UTC') < time()) {
            $license = null;
            Database::execute("UPDATE licenses SET status = 'expired' WHERE tenant_id = ? AND status = 'active'", [$tenantId]);
        }

        $planFeatures = [];
        if ($plan) {
            foreach (Database::all('SELECT * FROM plan_features WHERE plan_id = ?', [(int) $plan['id']]) as $f) {
                $planFeatures[$f['feature_code']] = [
                    'enabled' => (int) $f['enabled'] === 1,
                    'limit' => $f['limit_value'] !== null ? (int) $f['limit_value'] : null,
                ];
            }
        }
        $overrides = [];
        foreach (Database::all('SELECT feature_code, enabled FROM tenant_features WHERE tenant_id = ?', [$tenantId]) as $f) {
            $overrides[$f['feature_code']] = (int) $f['enabled'] === 1;
        }
        $licenseModules = $license ? (json_decode((string) $license['features_json'], true) ?: []) : [];
        $licenseLimits = $license ? (json_decode((string) $license['limits_json'], true) ?: []) : [];

        $features = [];
        foreach (array_keys(self::MODULES) as $code) {
            // An active license is the authoritative module list; the plan is
            // the fallback before a license is activated.
            $enabled = $license
                ? (bool) ($licenseModules[$code] ?? false)
                : (bool) ($planFeatures[$code]['enabled'] ?? false);
            if (array_key_exists($code, $overrides) && $overrides[$code] === false) {
                $enabled = false;
            }
            $features[$code] = $enabled;
        }

        $limits = [];
        foreach (array_keys(self::LIMITS) as $key) {
            $planLimit = array_key_exists($key, $planFeatures) ? $planFeatures[$key]['limit'] : -1;
            $planLimit = $planLimit === null ? -1 : $planLimit;
            // The active license is authoritative for quotas as well, so the
            // platform can sell add-on capacity above the plan. Without a
            // license the plan value applies (trial).
            $limits[$key] = $license && array_key_exists($key, $licenseLimits)
                ? (int) $licenseLimits[$key]
                : $planLimit;
        }

        $canTransact = self::computeCanTransact($tenant, $subscription, $license);
        $message = 'Langganan aktif';
        if (!$plan) {
            $message = 'Paket belum dipilih';
        } elseif (!$license && $canTransact) {
            $message = 'Masa trial aktif';
        } elseif (!$license) {
            $message = 'Lisensi belum aktif';
        } elseif ($canTransact === false) {
            $message = 'Langganan tidak aktif';
        }

        return self::$contexts[$tenantId] = [
            'tenant' => $tenant,
            'plan' => $plan,
            'subscription' => $subscription,
            'license' => $license,
            'features' => $features,
            'limits' => $limits,
            'status' => (string) $tenant['status'],
            'can_transact' => $canTransact,
            'message' => $message,
            'expires_at' => $license['expires_at'] ?? ($subscription['current_period_end'] ?? null),
        ];
    }

    private static function computeCanTransact(array $tenant, ?array $subscription, ?array $license): bool
    {
        $status = (string) $tenant['status'];
        if (in_array($status, ['suspended', 'cancelled'], true)) {
            return false;
        }
        if (!$license) {
            // No active license: only an unexpired trial may transact.
            return $status === 'trial'
                && ($tenant['trial_ends_at'] === null || strtotime($tenant['trial_ends_at'] . ' UTC') >= time());
        }
        if (strtotime($license['expires_at'] . ' UTC') < time()) {
            return false;
        }
        if ($subscription) {
            $now = time();
            if (strtotime($subscription['current_period_end'] . ' UTC') < $now) {
                if ($subscription['grace_until'] !== null && strtotime($subscription['grace_until'] . ' UTC') > $now) {
                    return true;
                }
                return false;
            }
        }
        if ($status === 'trial' && $tenant['trial_ends_at'] !== null) {
            if (strtotime($tenant['trial_ends_at'] . ' UTC') < time()) {
                return false;
            }
        }
        return true;
    }

    public static function enabled(string $feature): bool
    {
        if (Auth::isSuperAdmin()) {
            return true;
        }
        $ctx = self::context();
        return (bool) ($ctx['features'][$feature] ?? false);
    }

    public static function canTransact(): bool
    {
        $ctx = self::context();
        return (bool) ($ctx['can_transact'] ?? false);
    }

    public static function enabledFor(int $tenantId, string $feature): bool
    {
        $ctx = self::context($tenantId);
        return (bool) ($ctx['features'][$feature] ?? false);
    }

    public static function canTransactFor(int $tenantId): bool
    {
        return (bool) (self::context($tenantId)['can_transact'] ?? false);
    }

    public static function limit(string $limitKey): int
    {
        $ctx = self::context();
        return (int) ($ctx['limits'][$limitKey] ?? -1);
    }

    public static function usage(string $limitKey, ?int $tenantId = null): int
    {
        $tenantId = $tenantId ?? Auth::tenantId();
        return match ($limitKey) {
            'limit_outlets' => (int) Database::value('SELECT COUNT(*) FROM outlets WHERE tenant_id = ?', [$tenantId], 0),
            'limit_users' => (int) Database::value('SELECT COUNT(*) FROM users WHERE tenant_id = ?', [$tenantId], 0),
            'limit_products' => (int) Database::value('SELECT COUNT(*) FROM products WHERE tenant_id = ?', [$tenantId], 0),
            'limit_registers' => (int) Database::value('SELECT COUNT(*) FROM registers WHERE tenant_id = ?', [$tenantId], 0),
            'limit_tx_month' => (int) Database::value(
                'SELECT COUNT(*) FROM sales_orders WHERE tenant_id = ? AND created_at >= ?',
                [$tenantId, gmdate('Y-m-01 00:00:00')],
                0
            ),
            default => 0,
        };
    }

    public static function remaining(string $limitKey): int
    {
        $limit = self::limit($limitKey);
        if ($limit < 0) {
            return -1;
        }
        return max(0, $limit - self::usage($limitKey));
    }

    /** Returns an error message when the quota is exhausted, null when allowed. */
    public static function checkLimit(string $limitKey, int $add = 1): ?string
    {
        if (Auth::isSuperAdmin()) {
            return null;
        }
        $ctx = self::context();
        if (!($ctx['can_transact']) && in_array($limitKey, ['limit_tx_month'], true)) {
            return 'Langganan tidak aktif. Perbarui langganan untuk melanjutkan transaksi.';
        }
        $limit = self::limit($limitKey);
        if ($limit < 0) {
            return null;
        }
        if (self::usage($limitKey) + $add > $limit) {
            return 'Kuota ' . (self::LIMITS[$limitKey] ?? $limitKey) . ' Anda (' . $limit . ') telah tercapai. Perbarui lisensi atau paket untuk menambah.';
        }
        return null;
    }

    public static function assertLimit(string $limitKey, int $add = 1): void
    {
        $err = self::checkLimit($limitKey, $add);
        if ($err !== null) {
            throw new RuntimeException($err);
        }
    }

        /**
     * In-app expiry notice. Computed on every request, so no cron is needed
     * for the dashboard/topbar warning. Returns level none|ok|warning|critical|expired.
     */
    public static function expiryNotice(?int $tenantId = null, int $days = 30): array
    {
        $tenantId = $tenantId ?? Auth::tenantId();
        if ($tenantId <= 0) {
            return ['level' => 'none', 'days_left' => null, 'expires_at' => null, 'message' => 'Tidak ada tanggal kedaluwarsa.'];
        }
        if (isset(self::$expiryNotices[$tenantId])) {
            return self::$expiryNotices[$tenantId];
        }
        $ctx = self::context($tenantId);
        // Prefer the effective license. When it just expired, context() falls
        // back to the subscription, so load the most recent expired license to
        // keep the notice about the license itself.
        $license = $ctx['license'] ?? Database::one(
            "SELECT * FROM licenses WHERE tenant_id = ? AND status = 'expired' ORDER BY id DESC LIMIT 1",
            [$tenantId]
        );
        $expires = $license['expires_at'] ?? ($ctx['expires_at'] ?? null);
        if (!$expires) {
            return self::$expiryNotices[$tenantId] = ['level' => 'none', 'days_left' => null, 'expires_at' => null, 'message' => 'Tidak ada tanggal kedaluwarsa.'];
        }
        $daysLeft = (int) ceil((strtotime($expires . ' UTC') - time()) / 86400);
        if ($daysLeft < 0) {
            $level = 'expired';
            $message = 'Lisensi sudah kedaluwarsa. Perpanjang untuk melanjutkan transaksi.';
        } elseif ($daysLeft <= 7) {
            $level = 'critical';
            $message = $daysLeft === 0
                ? 'Lisensi berakhir hari ini. Segera perpanjang.'
                : 'Lisensi berakhir dalam ' . $daysLeft . ' hari. Segera perpanjang.';
        } elseif ($daysLeft <= $days) {
            $level = 'warning';
            $message = 'Lisensi akan berakhir dalam ' . $daysLeft . ' hari.';
        } else {
            $level = 'ok';
            $message = 'Lisensi aktif hingga ' . dt_local($expires, null, 'd M Y') . '.';
        }
        return self::$expiryNotices[$tenantId] = [
            'level' => $level,
            'days_left' => $daysLeft,
            'expires_at' => $expires,
            'message' => $message,
        ];
    }

    // ------------------------------------------------------------- token issue

    public static function issue(
        int $tenantId,
        int $planId,
        int $days,
        ?string $domain = null,
        ?int $actorId = null,
        ?array $modules = null,
        ?array $limitOverrides = null
    ): array {
        $tenant = Database::one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        $plan = Database::one('SELECT * FROM plans WHERE id = ?', [$planId]);
        if (!$tenant || !$plan) {
            throw new RuntimeException('Tenant atau paket tidak ditemukan.');
        }
        $features = [];
        $limits = [];
        foreach (Database::all('SELECT * FROM plan_features WHERE plan_id = ?', [$planId]) as $f) {
            if (str_starts_with($f['feature_code'], 'limit_')) {
                $limits[$f['feature_code']] = $f['limit_value'] !== null ? (int) $f['limit_value'] : -1;
            } else {
                $features[$f['feature_code']] = (int) $f['enabled'] === 1;
            }
        }
        // When the admin explicitly picks modules, the license carries exactly
        // that selection. An empty selection keeps the plan defaults.
        if ($modules !== null) {
            $selected = array_fill_keys(array_keys(self::MODULES), false);
            foreach ($modules as $code) {
                if (array_key_exists((string) $code, $selected)) {
                    $selected[(string) $code] = true;
                }
            }
            $features = $selected;
        }
        // Unspecified quota keys keep the plan value; -1 explicitly means
        // unlimited and 0 closes the quota.
        if ($limitOverrides !== null) {
            foreach (array_keys(self::LIMITS) as $key) {
                if (array_key_exists($key, $limitOverrides) && is_numeric($limitOverrides[$key])) {
                    $limits[$key] = max(-1, min(1000000, (int) $limitOverrides[$key]));
                }
            }
        }
        // A new license replaces the previous active one instead of silently
        // falling back to it when the new license is later revoked.
        Database::execute(
            "UPDATE licenses SET status = 'revoked', revoked_at = ?, revoked_reason = 'Digantikan lisensi baru' WHERE tenant_id = ? AND status = 'active'",
            [now_utc(), $tenantId]
        );
        $now = time();
        $keyId = (string) Config::get('LICENSE_KEY_ID', 'LIC-1');
        $claims = [
            'lid' => ulid(),
            'tid' => $tenantId,
            'tenant' => $tenant['name'],
            'plan' => $plan['code'],
            'modules' => $features,
            'limits' => $limits,
            'domain' => $domain,
            'iat' => $now,
            'exp' => $now + ($days * 86400),
            'kid' => $keyId,
            'iss' => 'pos-saas-license-engine',
        ];
        $token = self::sign($claims);
        $id = Database::insert('licenses', [
            'public_id' => ulid(),
            'tenant_id' => $tenantId,
            'key_id' => $keyId,
            'token' => $token,
            'plan_code' => $plan['code'],
            'features_json' => json_encode($features, JSON_UNESCAPED_UNICODE),
            'limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE),
            'domain' => $domain,
            'issued_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + ($days * 86400)),
            'status' => 'active',
            'created_by' => $actorId,
        ]);
        Database::insert('license_events', [
            'tenant_id' => $tenantId,
            'license_id' => $id,
            'event' => 'issued',
            'meta_json' => json_encode(['plan' => $plan['code'], 'days' => $days, 'domain' => $domain, 'modules' => $features, 'limits' => $limits]),
            'actor_id' => $actorId,
        ]);
        self::$contexts = [];
        return ['id' => $id, 'key_id' => $claims['lid'], 'token' => $token, 'expires_at' => $claims['exp']];
    }

    public static function sign(array $claims): string
    {
        $json = json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = self::b64url($json);
        $sig = hash_hmac('sha256', $payload, self::signingKey(), true);
        return $payload . '.' . self::b64url($sig);
    }

    public static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, self::signingKey(), true);
        if (!hash_equals($expected, self::b64urlDecode($sig))) {
            return null;
        }
        $claims = json_decode(self::b64urlDecode($payload), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['tid'])) {
            return null;
        }
        if ((int) $claims['exp'] < time()) {
            return null;
        }
        return $claims;
    }

    public static function activate(int $tenantId, string $token): array
    {
        $claims = self::verifyToken($token);
        if ($claims === null) {
            throw new RuntimeException('Token lisensi tidak valid atau sudah kedaluwarsa.');
        }
        if ((int) $claims['tid'] !== $tenantId) {
            throw new RuntimeException('Token lisensi ini bukan untuk perusahaan Anda.');
        }
        $domain = $claims['domain'] ?? null;
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: '';
        if ($domain && $host && !in_array($host, ['localhost', '127.0.0.1'], true) && !str_ends_with($host, strtolower((string) $domain))) {
            throw new RuntimeException('Lisensi terikat ke domain ' . $domain . '.');
        }
        $existing = Database::one('SELECT * FROM licenses WHERE tenant_id = ? AND token = ?', [$tenantId, $token]);
        if ($existing) {
            Database::execute("UPDATE licenses SET status = 'active', revoked_at = NULL WHERE id = ?", [(int) $existing['id']]);
            return $existing;
        }
        Database::execute("UPDATE licenses SET status = 'revoked', revoked_at = ? WHERE tenant_id = ? AND status = 'active'", [now_utc(), $tenantId]);
        $id = Database::insert('licenses', [
            'public_id' => ulid(),
            'tenant_id' => $tenantId,
            'key_id' => (string) ($claims['kid'] ?? 'LIC'),
            'token' => $token,
            'plan_code' => (string) ($claims['plan'] ?? 'custom'),
            'features_json' => json_encode($claims['modules'] ?? [], JSON_UNESCAPED_UNICODE),
            'limits_json' => json_encode($claims['limits'] ?? [], JSON_UNESCAPED_UNICODE),
            'domain' => $domain,
            'issued_at' => gmdate('Y-m-d H:i:s', (int) $claims['iat']),
            'expires_at' => gmdate('Y-m-d H:i:s', (int) $claims['exp']),
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);
        Database::insert('license_events', [
            'tenant_id' => $tenantId,
            'license_id' => $id,
            'event' => 'activated',
            'meta_json' => json_encode(['claims' => $claims]),
            'actor_id' => Auth::id(),
        ]);
        self::$contexts = [];
        return ['id' => $id, 'expires_at' => $claims['exp']];
    }

    public static function revoke(int $licenseId, string $reason): void
    {
        $license = Database::one('SELECT * FROM licenses WHERE id = ?', [$licenseId]);
        if (!$license) {
            throw new RuntimeException('Lisensi tidak ditemukan.');
        }
        Database::execute("UPDATE licenses SET status = 'revoked', revoked_at = ?, revoked_reason = ? WHERE id = ?", [now_utc(), $reason, $licenseId]);
        Database::insert('license_events', [
            'tenant_id' => (int) $license['tenant_id'],
            'license_id' => $licenseId,
            'event' => 'revoked',
            'meta_json' => json_encode(['reason' => $reason]),
            'actor_id' => Auth::id(),
        ]);
        self::$contexts = [];
    }

    private static function signingKey(): string
    {
        $key = (string) Config::get('LICENSE_SIGNING_KEY', '');
        if ($key === '') {
            $key = (string) Config::get('APP_KEY', 'insecure-development-key');
        }
        if (strlen($key) < 32) {
            throw new RuntimeException('APP_KEY / LICENSE_SIGNING_KEY minimal 32 karakter.');
        }
        return $key;
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $value): string
    {
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
