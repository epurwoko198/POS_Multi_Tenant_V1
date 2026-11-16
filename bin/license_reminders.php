<?php
declare(strict_types=1);

/**
 * License reminder sender (H-30 / H-7).
 *
 *   php bin/license_reminders.php
 *   php bin/license_reminders.php --dry-run
 *   php bin/license_reminders.php --threshold=7 --tenant=1
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Modules\SaaS\LicenseReminderService;

$opts = getopt('', ['dry-run', 'threshold::', 'tenant::', 'help']);
if (isset($opts['help'])) {
    echo "License reminder sender\n";
    echo "  php bin/license_reminders.php [--dry-run] [--threshold=30|7] [--tenant=ID]\n";
    exit(0);
}

$threshold = isset($opts['threshold']) ? (int) $opts['threshold'] : null;
if ($threshold !== null && !in_array($threshold, LicenseReminderService::THRESHOLDS, true)) {
    fwrite(STDERR, "Ambang harus 30 atau 7 hari.\n");
    exit(2);
}
$tenantId = isset($opts['tenant']) ? max(1, (int) $opts['tenant']) : null;
$dryRun = isset($opts['dry-run']);

try {
    $result = LicenseReminderService::run($dryRun, $threshold, $tenantId);
} catch (Throwable $e) {
    fwrite(STDERR, '[reminder] ' . $e->getMessage() . "\n");
    exit(1);
}

echo '[' . gmdate('c') . '] license reminders' . ($dryRun ? ' (dry-run)' : '') . "\n";
echo '  sent=' . $result['sent'] . ' planned=' . $result['planned']
    . ' skipped=' . $result['skipped'] . ' failed=' . $result['failed'] . "\n";
foreach ($result['results'] as $row) {
    $line = '  - ' . strtoupper((string) $row['status']) . ' · ' . $row['label'];
    if (!empty($row['recipient'])) {
        $line .= ' → ' . $row['recipient'];
    }
    if (!empty($row['error'])) {
        $line .= ' · ' . $row['error'];
    }
    echo $line . "\n";
}
if (!$dryRun && $result['sent'] === 0 && $result['failed'] === 0 && $result['skipped'] === 0 && $result['planned'] === 0) {
    echo "  Tidak ada lisensi pada ambang H-30/H-7.\n";
}
exit($result['failed'] > 0 ? 1 : 0);
