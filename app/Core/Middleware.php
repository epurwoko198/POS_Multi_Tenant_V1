<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Audit\AuditLog;

final class Middleware
{
    public static function handle(string $name): void
    {
        switch ($name) {
            case 'auth':
                if (!Auth::check()) {
                    header('Location: /login?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
                    exit;
                }
                break;

            case 'guest':
                if (Auth::check()) {
                    header('Location: ' . (Auth::isSuperAdmin() ? '/admin' : '/dashboard'));
                    exit;
                }
                break;

            case 'csrf':
                if (!Csrf::verify()) {
                    AuditLog::security('csrf_rejected', ['path' => $_SERVER['REQUEST_URI'] ?? '']);
                    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(419);
                        echo json_encode(['error' => 'csrf', 'message' => 'Token keamanan tidak valid. Muat ulang halaman.']);
                        exit;
                    }
                    http_response_code(419);
                    View::render('errors/419', ['title' => 'Sesi kedaluwarsa'], 'guest');
                    exit;
                }
                break;

            case 'super':
                if (!Auth::isSuperAdmin()) {
                    http_response_code(403);
                    View::render('errors/403', [
                        'title' => 'Akses ditolak',
                        'message' => 'Halaman ini hanya untuk SaaS Super Admin.',
                    ], 'app');
                    exit;
                }
                break;

            case 'license':
                // Tenant suspension blocks operational pages but never billing,
                // license activation, or data export.
                if (!License::canTransact() && !self::isBillingPath()) {
                    http_response_code(402);
                    View::render('license/blocked', [
                        'title' => 'Langganan perlu perhatian',
                        'context' => License::context(),
                    ], 'app');
                    exit;
                }
                break;

            case 'api':
                // API token auth is handled inside ApiController.
                break;
        }
    }

    private static function isBillingPath(): bool
    {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        foreach (['/license', '/billing', '/logout', '/profile', '/reports/export'] as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return true;
            }
        }
        return false;
    }
}
