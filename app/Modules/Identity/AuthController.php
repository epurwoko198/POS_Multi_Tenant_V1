<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Audit\AuditLog;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect($this->homePath());
        }
        $planCount = (int) Database::value("SELECT COUNT(*) FROM plans WHERE is_active = 1", [], 4);
        $this->view('auth/login', ['planCount' => $planCount], 'guest');
    }

    public function login(): void
    {
        $email = (string) $this->input('email', '');
        $password = (string) $this->raw('password', '');
        remember_old(['email' => $email]);
        if ($email === '' || $password === '') {
            flash('error', 'Email dan password wajib diisi.');
            $this->redirect('/login');
        }
        if (!Auth::attempt($email, $password)) {
            if (empty($_SESSION['_flash'])) {
                flash('error', 'Email atau password salah.');
            }
            $this->redirect('/login');
        }
        clear_old();
        $user = Auth::user();
        flash('success', 'Selamat datang, ' . $user['name'] . '.');
        $next = (string) ($_GET['next'] ?? '');
        if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            $this->redirect($next);
        }
        $this->redirect($this->homePath());
    }

    private function homePath(): string
    {
        if (Auth::isSuperAdmin()) {
            return '/admin';
        }
        return match ((string) (Auth::user()['role_code'] ?? '')) {
            'cashier', 'waiter' => '/pos/dashboard',
            'kitchen' => '/kds',
            default => '/dashboard',
        };
    }

    public function logout(): void
    {
        Auth::logout();
        redirect_to('/login');
    }

    public function profile(): void
    {
        $user = Auth::user();
        $this->view('auth/profile', ['title' => 'Profil & Keamanan', 'user' => $user]);
    }

    public function updateProfile(): void
    {
        $current = (string) $this->raw('current_password', '');
        $new = (string) $this->raw('new_password', '');
        $confirm = (string) $this->raw('confirm_password', '');
        $user = Database::one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            flash('error', 'Password saat ini salah.');
            $this->redirect('/profile');
        }
        if (strlen($new) < 8) {
            flash('error', 'Password baru minimal 8 karakter.');
            $this->redirect('/profile');
        }
        if ($new !== $confirm) {
            flash('error', 'Konfirmasi password tidak cocok.');
            $this->redirect('/profile');
        }
        Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [
            password_hash($new, PASSWORD_ARGON2ID), Auth::id(),
        ]);
        AuditLog::log('password_changed', 'user', (string) Auth::id());
        flash('success', 'Password berhasil diperbarui.');
        $this->redirect('/profile');
    }
}
