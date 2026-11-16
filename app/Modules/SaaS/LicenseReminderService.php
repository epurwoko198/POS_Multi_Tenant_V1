<?php
declare(strict_types=1);

namespace App\Modules\SaaS;

use App\Core\Database;
use App\Core\Mailer;
use App\Core\MailSettings;
use App\Modules\Audit\AuditLog;
use RuntimeException;

/**
 * Sends H-30/H-7 license reminders and records delivery so a cron run cannot
 * send the same threshold twice. Failed deliveries are retried next run.
 */
final class LicenseReminderService
{
    public const THRESHOLDS = [30, 7];

    public static function run(bool $dryRun = false, ?int $threshold = null, ?int $tenantId = null): array
    {
        $thresholds = $threshold !== null ? [$threshold] : self::THRESHOLDS;
        $cfg = MailSettings::all(true);
        if (!$dryRun && !$cfg['enabled']) {
            throw new RuntimeException('SMTP belum diaktifkan. Atur di /admin/mail.');
        }
        if (!$dryRun) {
            $missing = MailSettings::missing($cfg);
            if ($missing !== []) {
                throw new RuntimeException('Konfigurasi SMTP belum lengkap: ' . implode(', ', $missing) . '.');
            }
        }
        $mailer = new Mailer($cfg);
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'planned' => 0, 'results' => []];

        foreach ($thresholds as $days) {
            foreach (self::eligible((int) $days, $tenantId) as $license) {
                $label = 'H-' . (int) $days . ' · ' . $license['tenant_name'] . ' · ' . $license['key_id'];
                if ($dryRun) {
                    $result['planned']++;
                    $result['results'][] = ['status' => 'planned', 'label' => $label, 'recipient' => $license['recipient']];
                    continue;
                }
                $recipient = trim((string) ($license['recipient'] ?? ''));
                $claim = self::claim($license, (int) $days, $recipient);
                if ($claim === null) {
                    $result['skipped']++;
                    $result['results'][] = ['status' => 'skipped', 'label' => $label, 'recipient' => $recipient];
                    continue;
                }
                try {
                    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Email tenant/owner belum valid.');
                    }
                    [$subject, $html, $text] = self::compose($license, (int) $days);
                    $mailer->send($recipient, $subject, $html, $text);
                    self::markSent((int) $claim['id']);
                    AuditLog::log('license_reminder_sent', 'license', (string) $license['id'], null, [
                        'threshold_days' => (int) $days,
                        'recipient' => $recipient,
                        'expires_at' => $license['expires_at'],
                    ], (int) $license['tenant_id']);
                    $result['sent']++;
                    $result['results'][] = ['status' => 'sent', 'label' => $label, 'recipient' => $recipient];
                } catch (\Throwable $e) {
                    self::markFailed((int) $claim['id'], $e->getMessage());
                    AuditLog::log('license_reminder_failed', 'license', (string) $license['id'], null, [
                        'threshold_days' => (int) $days,
                        'recipient' => $recipient,
                        'error' => substr($e->getMessage(), 0, 240),
                    ], (int) $license['tenant_id']);
                    $result['failed']++;
                    $result['results'][] = ['status' => 'failed', 'label' => $label, 'recipient' => $recipient, 'error' => $e->getMessage()];
                }
            }
        }
        return $result;
    }

    public static function eligible(int $threshold, ?int $tenantId = null): array
    {
        $index = array_search($threshold, self::THRESHOLDS, true);
        if ($index === false) {
            throw new RuntimeException('Ambang pengingat harus 30 atau 7 hari.');
        }
        $lower = self::THRESHOLDS[$index + 1] ?? -1;
        $sql = "SELECT l.*, t.name AS tenant_name, t.email AS tenant_email, t.timezone, t.status AS tenant_status,
                       p.name AS plan_name,
                       COALESCE(NULLIF(t.email, ''), (
                           SELECT u.email FROM users u JOIN roles r ON r.id = u.role_id
                           WHERE u.tenant_id = t.id AND r.code = 'owner' AND u.status = 'active'
                           ORDER BY u.id LIMIT 1
                       )) AS recipient
                FROM licenses l
                JOIN tenants t ON t.id = l.tenant_id
                LEFT JOIN plans p ON p.code = l.plan_code
                WHERE l.status = 'active'
                  AND l.expires_at >= UTC_TIMESTAMP(3)
                  AND l.expires_at <= DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 31 DAY)
                  AND t.status IN ('trial','active','past_due','grace')";
        $params = [];
        if ($tenantId !== null) {
            $sql .= ' AND l.tenant_id = ?';
            $params[] = $tenantId;
        }
        $sql .= ' ORDER BY l.expires_at ASC';

        $rows = [];
        foreach (Database::all($sql, $params) as $row) {
            $days = (int) ceil((strtotime($row['expires_at'] . ' UTC') - time()) / 86400);
            if ($days > $threshold || $days <= $lower) {
                continue;
            }
            $row['days_left'] = $days;
            $rows[] = $row;
        }
        return $rows;
    }

    public static function recent(int $limit = 30): array
    {
        try {
            return Database::all(
                'SELECT r.*, t.name AS tenant_name, l.key_id
                 FROM license_reminders r
                 JOIN tenants t ON t.id = r.tenant_id
                 LEFT JOIN licenses l ON l.id = r.license_id
                 ORDER BY r.id DESC LIMIT ' . max(1, min(100, $limit))
            );
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1146) {
                return [];
            }
            throw $e;
        }
    }

    private static function claim(array $license, int $threshold, string $recipient): ?array
    {
        $existing = Database::one(
            'SELECT * FROM license_reminders WHERE license_id = ? AND threshold_days = ?',
            [(int) $license['id'], $threshold]
        );
        if ($existing) {
            if ($existing['status'] === 'sent') {
                return null;
            }
            $affected = Database::execute(
                "UPDATE license_reminders
                 SET status = 'sending', recipient = ?, attempts = attempts + 1, last_error = NULL, updated_at = ?
                 WHERE id = ? AND (
                     status = 'failed'
                     OR (status = 'sending' AND updated_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 10 MINUTE))
                 )",
                [$recipient, now_utc(), (int) $existing['id']]
            );
            return $affected > 0 ? ['id' => (int) $existing['id']] : null;
        }
        try {
            $id = Database::insert('license_reminders', [
                'tenant_id' => (int) $license['tenant_id'],
                'license_id' => (int) $license['id'],
                'threshold_days' => $threshold,
                'recipient' => $recipient,
                'status' => 'sending',
                'attempts' => 1,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
            return ['id' => $id];
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                // A concurrent cron process already claimed this reminder.
                return null;
            }
            throw $e;
        }
    }

    private static function markSent(int $id): void
    {
        Database::execute(
            "UPDATE license_reminders SET status = 'sent', sent_at = ?, last_error = NULL, updated_at = ? WHERE id = ?",
            [now_utc(), now_utc(), $id]
        );
    }

    private static function markFailed(int $id, string $error): void
    {
        Database::execute(
            "UPDATE license_reminders SET status = 'failed', last_error = ?, updated_at = ? WHERE id = ?",
            [substr($error, 0, 500), now_utc(), $id]
        );
    }

    /** @return array{0:string,1:string,2:string} subject, html, text */
    private static function compose(array $license, int $threshold): array
    {
        $appName = (string) \App\Core\Config::get('APP_NAME', 'Berkah POS');
        $tenant = (string) $license['tenant_name'];
        $plan = (string) ($license['plan_name'] ?: $license['plan_code']);
        $expires = dt_local((string) $license['expires_at'], (string) $license['timezone'], 'd M Y H:i');
        $days = (int) $license['days_left'];
        $url = rtrim((string) \App\Core\Config::get('APP_URL', 'http://localhost'), '/') . '/license';
        $subject = $threshold === 7
            ? 'Lisensi ' . $appName . ' berakhir dalam ' . $days . ' hari'
            : 'Pengingat lisensi ' . $appName . ': ' . $days . ' hari lagi';
        $intro = $threshold === 7
            ? 'Lisensi Anda tinggal ' . $days . ' hari lagi. Segera perpanjang agar transaksi tidak terhenti.'
            : 'Lisensi Anda akan berakhir dalam ' . $days . ' hari. Silakan perpanjang sebelum tanggal berlaku berakhir.';
        $text = "Halo " . $tenant . ",\n\n"
            . $intro . "\n\n"
            . 'Paket      : ' . $plan . "\n"
            . 'Key lisensi: ' . $license['key_id'] . "\n"
            . 'Berlaku s/d: ' . $expires . "\n"
            . 'Sisa waktu : ' . $days . " hari\n\n"
            . 'Kelola lisensi: ' . $url . "\n\n"
            . 'Jika sudah diperpanjang, abaikan email ini.' . "\n\n"
            . $appName;

        $safeTenant = e($tenant);
        $safePlan = e($plan);
        $safeKey = e((string) $license['key_id']);
        $safeExpires = e($expires);
        $safeUrl = e($url);
        $safeApp = e($appName);
        $safeIntro = e($intro);
        $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a">'
            . '<div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e6eaf2;border-radius:14px;overflow:hidden">'
            . '<div style="background:linear-gradient(135deg,#4f46e5,#06b6d4);padding:22px 26px;color:#fff">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.8">Pengingat lisensi</div>'
            . '<h1 style="margin:6px 0 0;font-size:22px">' . $safeApp . '</h1></div>'
            . '<div style="padding:26px">'
            . '<p style="margin:0 0 12px">Halo <strong>' . $safeTenant . '</strong>,</p>'
            . '<p style="margin:0 0 18px;line-height:1.65">' . $safeIntro . '</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
            . '<tr><td style="padding:8px 0;color:#64748b">Paket</td><td style="padding:8px 0;text-align:right;font-weight:700">' . $safePlan . '</td></tr>'
            . '<tr><td style="padding:8px 0;color:#64748b">Key lisensi</td><td style="padding:8px 0;text-align:right;font-family:monospace">' . $safeKey . '</td></tr>'
            . '<tr><td style="padding:8px 0;color:#64748b">Berlaku s/d</td><td style="padding:8px 0;text-align:right;font-weight:700">' . $safeExpires . '</td></tr>'
            . '<tr><td style="padding:8px 0;color:#64748b">Sisa waktu</td><td style="padding:8px 0;text-align:right;font-weight:700;color:' . ($threshold === 7 ? '#be123c' : '#b45309') . '">' . $days . ' hari</td></tr>'
            . '</table>'
            . '<p style="margin:22px 0 0"><a href="' . $safeUrl . '" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:11px 18px;border-radius:9px;font-weight:700">Kelola lisensi</a></p>'
            . '<p style="margin:18px 0 0;font-size:12px;color:#64748b">Jika sudah diperpanjang, abaikan email ini.</p>'
            . '</div></div></body></html>';
        return [$subject, $html, $text];
    }
}
