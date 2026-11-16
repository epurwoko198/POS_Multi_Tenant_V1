<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $data = [], string $layout = 'app'): void
    {
        View::render($view, $data, $layout);
    }

    protected function json(mixed $data, int $status = 200): never
    {
        json_response($data, $status);
    }

    protected function redirect(string $path): never
    {
        redirect_to($path);
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_POST)) {
            return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
        }
        return $default;
    }

    protected function raw(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    protected function intInput(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    protected function floatInput(string $key, float $default = 0): float
    {
        $v = $this->input($key, $default);
        if (is_string($v)) {
            $v = str_replace(' ', '', trim($v));
            if (str_contains($v, ',') && str_contains($v, '.')) {
                // Indonesian format: 1.234.567,89
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            } elseif (str_contains($v, ',')) {
                $v = str_replace(',', '.', $v);
            } elseif (substr_count($v, '.') > 1) {
                // Multiple dots can only be thousands grouping.
                $v = str_replace('.', '', $v);
            }
        }
        return is_numeric($v) ? (float) $v : $default;
    }

    /** Read JSON body merged with form data (API endpoints). */
    protected function payload(): array
    {
        $body = file_get_contents('php://input') ?: '';
        $json = [];
        if ($body !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
        return array_merge($_POST, $json);
    }

    protected function requirePermission(string $permission): void
    {
        if (!Auth::can($permission)) {
            http_response_code(403);
            $this->view('errors/403', [
                'title' => 'Akses ditolak',
                'message' => 'Peran Anda tidak memiliki izin ' . $permission . '.',
            ]);
            exit;
        }
    }

    protected function requireFeature(string $feature): void
    {
        if (!License::enabled($feature)) {
            http_response_code(403);
            $this->view('license/upgrade', [
                'title' => 'Modul tidak aktif',
                'feature' => $feature,
                'context' => License::context(),
            ]);
            exit;
        }
    }

    protected function tenantId(): int
    {
        return Auth::tenantId();
    }

    protected function outletId(): int
    {
        return Auth::outletId();
    }
}
