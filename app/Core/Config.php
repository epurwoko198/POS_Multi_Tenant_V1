<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $data = [];

    public static function load(string $envFile): void
    {
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }
                self::$data[$key] = $value;
            }
        }
        foreach ($_ENV as $k => $v) {
            if (!array_key_exists($k, self::$data) && is_string($v)) {
                self::$data[$k] = $v;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$data) && self::$data[$key] !== '') {
            return self::$data[$key];
        }
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function set(string $key, string $value): void
    {
        self::$data[$key] = $value;
    }
}
