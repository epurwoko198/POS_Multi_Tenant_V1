<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Audit\AuditLog;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        $ip = client_ip();
        $attempt = Database::one('SELECT * FROM login_attempts WHERE identifier = ? AND ip = ?', [$email, $ip]);
        if ($attempt && $attempt['locked_until'] !== null && strtotime($attempt['locked_until'] . ' UTC') > time()) {
            flash('error', 'Terlalu banyak percobaan. Coba lagi setelah ' . dt_local($attempt['locked_until'], null, 'H:i') . '.');
            return false;
        }
        $row = Database::one(
            'SELECT u.*, r.permissions FROM users u JOIN roles r ON r.id = u.role_id WHERE LOWER(u.email) = ? LIMIT 1',
            [$email]
        );
        if (!$row || !password_verify($password, $row['password_hash'])) {
            $attempts = (int) ($attempt['attempts'] ?? 0) + 1;
            $lockedUntil = $attempts >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null;
            Database::execute(
                'INSERT INTO login_attempts (identifier, ip, attempts, locked_until) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE attempts = ?, locked_until = ?',
                [$email, $ip, $attempts, $lockedUntil, $attempts, $lockedUntil]
            );
            AuditLog::security('login_failed', ['email' => $email]);
            return false;
        }
        if ($row['status'] !== 'active') {
            flash('error', 'Akun tidak aktif. Hubungi administrator.');
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['login_at'] = time();
        unset($_SESSION['_csrf']);
        self::$user = null;
        self::$loaded = false;
        Database::execute('UPDATE users SET last_login_at = ? WHERE id = ?', [now_utc(), (int) $row['id']]);
        Database::execute('DELETE FROM login_attempts WHERE identifier = ? AND ip = ?', [$email, $ip]);
        AuditLog::log('login', 'user', (string) $row['id'], null, ['email' => $row['email']]);
        return true;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return self::$user = null;
        }
        $row = Database::one(
            'SELECT u.id, u.public_id, u.tenant_id, u.outlet_id, u.name, u.email, u.role_id, u.status,
                    r.code AS role_code, r.name AS role_name, r.permissions,
                    t.name AS tenant_name, t.status AS tenant_status, t.timezone AS tenant_timezone,
                    t.currency AS tenant_currency, t.plan_id, o.name AS outlet_name, o.code AS outlet_code
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN tenants t ON t.id = u.tenant_id
             LEFT JOIN outlets o ON o.id = u.outlet_id
             WHERE u.id = ? LIMIT 1',
            [$id]
        );
        if (!$row || $row['status'] !== 'active') {
            unset($_SESSION['user_id']);
            return self::$user = null;
        }
        $row['permissions_list'] = json_decode((string) $row['permissions'], true) ?: [];
        $row['tenant_id'] = $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null;
        $row['outlet_id'] = $row['outlet_id'] !== null ? (int) $row['outlet_id'] : null;
        $row['role_code'] = (string) $row['role_code'];
        return self::$user = $row;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function tenantId(): int
    {
        return (int) (self::user()['tenant_id'] ?? 0);
    }

    public static function outletId(): int
    {
        return (int) (self::user()['outlet_id'] ?? 0);
    }

    public static function isSuperAdmin(): bool
    {
        return (self::user()['role_code'] ?? '') === 'super_admin';
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        $perms = $user['permissions_list'];
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::can($p)) {
                return true;
            }
        }
        return false;
    }

    public static function logout(): void
    {
        AuditLog::log('logout', 'user', (string) self::id());
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
        self::$user = null;
        self::$loaded = true;
    }
}
