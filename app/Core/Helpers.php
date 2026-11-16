<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rupiah(mixed $amount, bool $prefix = true): string
{
    $n = (float) $amount;
    $formatted = number_format($n, 0, ',', '.');
    return $prefix ? 'Rp ' . $formatted : $formatted;
}

function rupiah_precise(mixed $amount): string
{
    $n = (float) $amount;
    $decimals = abs($n - round($n)) > 0.0001 ? 2 : 0;
    return 'Rp ' . number_format($n, $decimals, ',', '.');
}

function number_id(mixed $value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', '.');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function dt_local(?string $utc, ?string $timezone = null, string $format = 'd M Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '-';
    }
    try {
        $tz = new DateTimeZone($timezone ?: (Auth::user()['tenant_timezone'] ?? Config::get('APP_TIMEZONE', 'Asia/Jakarta')));
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone($tz);
        return $d->format($format);
    } catch (Throwable) {
        return $utc;
    }
}

function date_local(?string $utc, string $format = 'd M Y'): string
{
    return dt_local($utc, null, $format);
}

function ulid(): string
{
    $chars = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $time = (int) floor(microtime(true) * 1000);
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out = $chars[$time % 32] . $out;
        $time = intdiv($time, 32);
    }
    for ($i = 0; $i < 16; $i++) {
        $out .= $chars[random_int(0, 31)];
    }
    return $out;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flash(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

function old(string $key, mixed $default = ''): string
{
    return (string) ($_SESSION['_old'][$key] ?? $default);
}

function remember_old(array $input): void
{
    unset($input['_csrf'], $input['password'], $input['pin']);
    $_SESSION['_old'] = $input;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function setting(int $tenantId, string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (isset($cache[$tenantId][$key])) {
        return $cache[$tenantId][$key];
    }
    $row = App\Core\Database::one('SELECT svalue FROM settings WHERE tenant_id = ? AND skey = ?', [$tenantId, $key]);
    $cache[$tenantId][$key] = $row['svalue'] ?? $default;
    return $cache[$tenantId][$key];
}

function tenant_logo_url(int $tenantId): ?string
{
    $path = setting($tenantId, 'store_logo');
    if (!$path) {
        return null;
    }
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (!preg_match('#^uploads/logos/[A-Za-z0-9._-]+$#', $path)) {
        return null;
    }
    return is_file(BASE_PATH . '/public/' . $path) ? '/' . $path : null;
}

function badge_status(string $status): string
{
    $map = [
        'paid' => 'success', 'settled' => 'success', 'active' => 'success', 'completed' => 'success',
        'printed' => 'success', 'processed' => 'success', 'ready' => 'success',
        'pending' => 'warning', 'awaiting_payment' => 'warning', 'trial' => 'warning',
        'past_due' => 'warning', 'grace' => 'warning', 'open' => 'warning', 'queued' => 'warning',
        'preparing' => 'info', 'printing' => 'info', 'ordered' => 'info', 'draft' => 'muted',
        'failed' => 'danger', 'expired' => 'danger', 'cancelled' => 'danger', 'suspended' => 'danger',
        'revoked' => 'danger', 'rejected' => 'danger', 'partial' => 'warning',
    ];
    return $map[$status] ?? 'muted';
}

function status_label(string $status): string
{
    $labels = [
        'paid' => 'Lunas', 'settled' => 'Settled', 'pending' => 'Menunggu', 'awaiting_payment' => 'Menunggu Bayar',
        'completed' => 'Selesai', 'cancelled' => 'Dibatalkan', 'refunded' => 'Refund',
        'partially_refunded' => 'Refund Sebagian', 'open' => 'Buka', 'closed' => 'Tutup',
        'queued' => 'Antre', 'preparing' => 'Disiapkan', 'ready' => 'Siap', 'served' => 'Disajikan',
        'active' => 'Aktif', 'trial' => 'Trial', 'past_due' => 'Jatuh Tempo', 'grace' => 'Masa Tenggang',
        'suspended' => 'Ditangguhkan', 'revoked' => 'Dicabut', 'expired' => 'Kedaluwarsa',
        'draft' => 'Draft', 'ordered' => 'Dipesan', 'partial' => 'Sebagian', 'received' => 'Diterima',
        'printed' => 'Tercetak', 'printing' => 'Mencetak', 'failed' => 'Gagal', 'queued_print' => 'Antre Cetak',
        'free' => 'Kosong', 'occupied' => 'Terisi', 'reserved' => 'Reservasi',
        'retail' => 'Retail', 'dine_in' => 'Dine In', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery',
        'basic' => 'Basic', 'silver' => 'Silver', 'gold' => 'Gold', 'platinum' => 'Platinum',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function nav_active(string $prefix): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($prefix === '/') {
        return $path === '/' || $path === '/dashboard' ? 'active' : '';
    }
    return str_starts_with($path, $prefix) ? 'active' : '';
}
