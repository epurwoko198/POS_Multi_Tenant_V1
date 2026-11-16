<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once BASE_PATH . '/app/Core/Config.php';
require_once BASE_PATH . '/app/Core/Helpers.php';
require_once BASE_PATH . '/app/Core/Icons.php';

use App\Core\Config;

Config::load(BASE_PATH . '/.env');

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', Config::bool('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) Config::get('SESSION_NAME', 'possaas_session'));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || str_starts_with((string) Config::get('APP_URL', ''), 'https://');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

set_exception_handler(static function (Throwable $e): void {
    $log = '[' . gmdate('c') . '] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
    @file_put_contents(BASE_PATH . '/storage/logs/app.log', $log, FILE_APPEND);
    if (Config::bool('APP_DEBUG', false)) {
        error_log($log);
    }
    $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'server_error',
            'message' => Config::bool('APP_DEBUG', false) ? $e->getMessage() : 'Terjadi kesalahan pada server.',
        ]);
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    try {
        App\Core\View::render('errors/500', [
            'title' => 'Terjadi kesalahan',
            'detail' => Config::bool('APP_DEBUG', false) ? $e->getMessage() : null,
        ], 'guest');
    } catch (Throwable) {
        echo 'Internal Server Error';
    }
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents(
            BASE_PATH . '/storage/logs/app.log',
            '[' . gmdate('c') . '] FATAL: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line'] . PHP_EOL,
            FILE_APPEND
        );
    }
});
