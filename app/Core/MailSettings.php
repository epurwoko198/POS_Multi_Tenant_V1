<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Platform-wide SMTP configuration.
 *
 * Values live in platform_settings (editable by Super Admin) and fall back to
 * SMTP_* environment variables. The password is encrypted with APP_KEY before
 * it is written to the database.
 */
final class MailSettings
{
    private const KEYS = [
        'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_auth',
        'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name',
    ];

    private static ?array $cache = null;

    /** @return array{enabled:bool,host:string,port:int,encryption:string,auth:string,username:string,password:string,from_email:string,from_name:string,timeout:int} */
    public static function all(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) {
            return self::$cache;
        }
        $cfg = [
            'enabled' => Config::bool('SMTP_ENABLED', false),
            'host' => trim((string) Config::get('SMTP_HOST', '')),
            'port' => Config::int('SMTP_PORT', 587),
            'encryption' => strtolower((string) Config::get('SMTP_ENCRYPTION', 'tls')),
            'auth' => strtolower((string) Config::get('SMTP_AUTH', 'login')),
            'username' => (string) Config::get('SMTP_USERNAME', ''),
            'password' => (string) Config::get('SMTP_PASSWORD', ''),
            'from_email' => trim((string) Config::get('SMTP_FROM_EMAIL', '')),
            'from_name' => trim((string) Config::get('SMTP_FROM_NAME', (string) Config::get('APP_NAME', 'Berkah POS'))),
            'timeout' => max(5, min(60, Config::int('SMTP_TIMEOUT', 15))),
        ];

        try {
            $rows = Database::all('SELECT skey, svalue FROM platform_settings WHERE skey LIKE ?', ['smtp_%']);
            foreach ($rows as $row) {
                $key = (string) $row['skey'];
                $value = (string) ($row['svalue'] ?? '');
                switch ($key) {
                    case 'smtp_enabled':
                        $cfg['enabled'] = $value === '1';
                        break;
                    case 'smtp_host':
                        $cfg['host'] = trim($value);
                        break;
                    case 'smtp_port':
                        $cfg['port'] = (int) $value;
                        break;
                    case 'smtp_encryption':
                        $cfg['encryption'] = strtolower($value);
                        break;
                    case 'smtp_auth':
                        $cfg['auth'] = strtolower($value);
                        break;
                    case 'smtp_username':
                        $cfg['username'] = $value;
                        break;
                    case 'smtp_password':
                        $cfg['password'] = self::decryptSecret($value);
                        break;
                    case 'smtp_from_email':
                        $cfg['from_email'] = trim($value);
                        break;
                    case 'smtp_from_name':
                        $cfg['from_name'] = trim($value);
                        break;
                }
            }
        } catch (\Throwable) {
            // Before the migration, the page still works from .env values.
        }

        if (!in_array($cfg['encryption'], ['none', 'tls', 'ssl'], true)) {
            $cfg['encryption'] = 'tls';
        }
        if (!in_array($cfg['auth'], ['login', 'plain'], true)) {
            $cfg['auth'] = 'login';
        }
        if ($cfg['port'] < 1 || $cfg['port'] > 65535) {
            $cfg['port'] = $cfg['encryption'] === 'ssl' ? 465 : 587;
        }
        if ($cfg['from_name'] === '') {
            $cfg['from_name'] = (string) Config::get('APP_NAME', 'Berkah POS');
        }
        return self::$cache = $cfg;
    }

    /** @return string[] Human-readable missing configuration fields. */
    public static function missing(array $cfg): array
    {
        $missing = [];
        if (trim((string) $cfg['host']) === '') {
            $missing[] = 'SMTP host';
        }
        if (!filter_var((string) $cfg['from_email'], FILTER_VALIDATE_EMAIL)) {
            $missing[] = 'email pengirim';
        }
        if ((string) $cfg['username'] !== '' && (string) $cfg['password'] === '') {
            $missing[] = 'password SMTP';
        }
        return $missing;
    }

    public static function isConfigured(array $cfg): bool
    {
        return (bool) $cfg['enabled'] && self::missing($cfg) === [];
    }

    public static function passwordStored(): bool
    {
        try {
            return (string) Database::value(
                "SELECT svalue FROM platform_settings WHERE skey = 'smtp_password'",
                [],
                ''
            ) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function save(array $input, bool $clearPassword = false): void
    {
        $values = [
            'smtp_enabled' => !empty($input['enabled']) ? '1' : '0',
            'smtp_host' => trim((string) ($input['host'] ?? '')),
            'smtp_port' => (string) max(1, min(65535, (int) ($input['port'] ?? 587))),
            'smtp_encryption' => in_array(($input['encryption'] ?? ''), ['none', 'tls', 'ssl'], true) ? (string) $input['encryption'] : 'tls',
            'smtp_auth' => in_array(($input['auth'] ?? ''), ['login', 'plain'], true) ? (string) $input['auth'] : 'login',
            'smtp_username' => (string) ($input['username'] ?? ''),
            'smtp_from_email' => trim((string) ($input['from_email'] ?? '')),
            'smtp_from_name' => trim((string) ($input['from_name'] ?? '')),
        ];
        $password = (string) ($input['password'] ?? '');
        if ($clearPassword) {
            $values['smtp_password'] = '';
        } elseif ($password !== '') {
            $values['smtp_password'] = self::encryptSecret($password);
        }
        foreach ($values as $key => $value) {
            Database::execute(
                'INSERT INTO platform_settings (skey, svalue) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
                [$key, $value]
            );
        }
        self::$cache = null;
    }

    public static function encryptSecret(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $appKey = (string) Config::get('APP_KEY', '');
        if (strlen($appKey) < 16) {
            throw new RuntimeException('APP_KEY minimal 16 karakter diperlukan untuk mengenkripsi password SMTP.');
        }
        $key = hash('sha256', $appKey, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Password SMTP gagal dienkripsi.');
        }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decryptSecret(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, 'enc:v1:')) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', (string) Config::get('APP_KEY', ''), true), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
