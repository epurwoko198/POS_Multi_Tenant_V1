<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\License;
use App\Modules\Audit\AuditLog;

final class UserController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('users.manage');
        $tenantId = $this->tenantId();
        $users = Database::all(
            'SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at, r.name AS role_name, r.code AS role_code,
                    o.name AS outlet_name
             FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN outlets o ON o.id = u.outlet_id
             WHERE u.tenant_id = ? ORDER BY u.name',
            [$tenantId]
        );
        $roles = Database::all("SELECT * FROM roles WHERE code <> 'super_admin' ORDER BY id");
        $outlets = Database::all('SELECT id, name FROM outlets WHERE tenant_id = ? ORDER BY name', [$tenantId]);
        $this->view('settings/users', [
            'title' => 'Pengguna & Peran',
            'users' => $users,
            'roles' => $roles,
            'outlets' => $outlets,
            'usage' => License::usage('limit_users'),
            'limit' => License::limit('limit_users'),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('users.manage');
        $tenantId = $this->tenantId();
        $name = trim((string) $this->input('name', ''));
        $email = strtolower(trim((string) $this->input('email', '')));
        $password = (string) $this->raw('password', '');
        $roleId = $this->intInput('role_id');
        $outletId = $this->intInput('outlet_id') ?: null;
        $role = Database::one("SELECT * FROM roles WHERE id = ? AND code <> 'super_admin'", [$roleId]);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$role) {
            flash('error', 'Nama, email valid, dan peran wajib diisi.');
            $this->redirect('/users');
        }
        if (strlen($password) < 8) {
            flash('error', 'Password minimal 8 karakter.');
            $this->redirect('/users');
        }
        try {
            License::assertLimit('limit_users');
            $id = Database::insert('users', [
                'public_id' => ulid(),
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'role_id' => $roleId,
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
                'status' => 'active',
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
            AuditLog::log('user_created', 'user', (string) $id, null, ['email' => $email, 'role' => $role['code']]);
            flash('success', 'Pengguna ' . $name . ' ditambahkan.');
        } catch (\Throwable $e) {
            flash('error', 'Gagal menambah pengguna: ' . $e->getMessage());
        }
        $this->redirect('/users');
    }

    public function toggle(string $id): void
    {
        $this->requirePermission('users.manage');
        if ((int) $id === Auth::id()) {
            flash('error', 'Anda tidak dapat menonaktifkan akun sendiri.');
            $this->redirect('/users');
        }
        $user = Database::one('SELECT * FROM users WHERE id = ? AND tenant_id = ?', [(int) $id, $this->tenantId()]);
        if (!$user) {
            flash('error', 'Pengguna tidak ditemukan.');
            $this->redirect('/users');
        }
        $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
        Database::execute('UPDATE users SET status = ? WHERE id = ?', [$newStatus, (int) $id]);
        AuditLog::log('user_status', 'user', $id, ['status' => $user['status']], ['status' => $newStatus]);
        flash('success', 'Status pengguna diperbarui.');
        $this->redirect('/users');
    }

    public function reset(string $id): void
    {
        $this->requirePermission('users.manage');
        $user = Database::one('SELECT * FROM users WHERE id = ? AND tenant_id = ?', [(int) $id, $this->tenantId()]);
        if (!$user) {
            flash('error', 'Pengguna tidak ditemukan.');
            $this->redirect('/users');
        }
        $newPassword = 'Pos-' . bin2hex(random_bytes(4));
        Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [
            password_hash($newPassword, PASSWORD_ARGON2ID), (int) $id,
        ]);
        AuditLog::log('user_password_reset', 'user', $id);
        flash('success', 'Password sementara untuk ' . $user['email'] . ': ' . $newPassword);
        $this->redirect('/users');
    }
}
