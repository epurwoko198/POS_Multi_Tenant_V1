<?php
declare(strict_types=1);

/**
 * Apply idempotent database migrations for an existing installation.
 * Fresh installs get the same tables from database/schema.sql.
 *
 *   php bin/migrate.php
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$dir = BASE_PATH . '/database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);
if ($files === []) {
    echo "Tidak ada migration.\n";
    exit(0);
}

$conn = Database::conn();
foreach ($files as $file) {
    echo 'Applying ' . basename($file) . "...\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, 'Tidak dapat membaca ' . $file . "\n");
        exit(1);
    }
    if (!$conn->multi_query($sql)) {
        fwrite(STDERR, 'Migration gagal: ' . $conn->error . "\n");
        exit(1);
    }
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    if ($conn->errno) {
        fwrite(STDERR, 'Migration gagal: ' . $conn->error . "\n");
        exit(1);
    }
}
echo "Migration selesai.\n";
