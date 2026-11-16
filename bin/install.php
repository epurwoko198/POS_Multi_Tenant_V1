<?php
declare(strict_types=1);

/**
 * Berkah POS installer (CLI)
 *
 *   php bin/install.php --host=127.0.0.1 --port=3306 --user=root --pass=secret --db=pos_saas
 *
 * Options:
 *   --host --port --user --pass --db --app-url
 *   --force        drop existing database first (DESTRUCTIVE, local/dev only)
 *   --no-history   skip demo sales history generation
 *   --help
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\License;

require_once __DIR__ . '/seed_history.php';

$opts = getopt('', ['host::', 'port::', 'user::', 'pass::', 'db::', 'app-url::', 'force', 'no-history', 'help']);
if (isset($opts['help'])) {
    echo "Berkah POS installer\n";
    echo "  php bin/install.php --host=127.0.0.1 --port=3306 --user=root --pass=secret --db=pos_saas\n";
    echo "  --force        drop the target database first (destructive)\n";
    echo "  --no-history   do not generate demo sales history\n";
    exit(0);
}

$envPath = BASE_PATH . '/.env';
if (!is_file($envPath)) {
    $appKey = bin2hex(random_bytes(32));
    $aiToken = bin2hex(random_bytes(24));
    $shareToken = bin2hex(random_bytes(24));
    $env = <<<ENV
APP_NAME="Berkah POS"
APP_ENV=production
APP_DEBUG=0
APP_URL=http://127.0.0.1:8080
APP_TIMEZONE=Asia/Jakarta
APP_KEY={$appKey}
LICENSE_KEY_ID=LIC-2026-01
LICENSE_SIGNING_KEY=

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pos_saas
DB_USER=root
DB_PASS=

AI_SERVICE_URL=http://127.0.0.1:8765
AI_SERVICE_TOKEN={$aiToken}
AI_SHARE_TOKEN={$shareToken}

PAYMENT_PROVIDER=mock
PAYMENT_MODE=sandbox
SESSION_NAME=possaas_session
ENV;
    file_put_contents($envPath, $env);
    echo "Created .env with a fresh APP_KEY.\n";
    Config::load($envPath);
}

Config::set('DB_HOST', (string) ($opts['host'] ?? '127.0.0.1'));
Config::set('DB_PORT', (string) ($opts['port'] ?? '3306'));
Config::set('DB_USER', (string) ($opts['user'] ?? 'root'));
Config::set('DB_PASS', (string) ($opts['pass'] ?? ''));
Config::set('DB_NAME', (string) ($opts['db'] ?? 'pos_saas'));
Config::set('APP_URL', (string) ($opts['app-url'] ?? 'http://127.0.0.1:8080'));

$dbName = (string) Config::get('DB_NAME');
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "Invalid database name.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $root = new mysqli(
        (string) Config::get('DB_HOST'),
        (string) Config::get('DB_USER'),
        (string) Config::get('DB_PASS'),
        '',
        Config::int('DB_PORT', 3306)
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect to MySQL: " . $e->getMessage() . "\n");
    exit(1);
}
$root->set_charset('utf8mb4');

if (isset($opts['force'])) {
    echo "Dropping database {$dbName} (--force)...\n";
    $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
}
$root->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$root->select_db($dbName);
$root->query("SET time_zone = '+00:00'");

$already = $root->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_name = 'tenants'")->fetch_assoc();
if ((int) ($already['c'] ?? 0) > 0 && !isset($opts['force'])) {
    fwrite(STDERR, "Database already contains Berkah POS tables. Use --force to reinstall (destructive).\n");
    exit(1);
}

function run_sql_file(mysqli $conn, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    if (!$conn->multi_query($sql)) {
        throw new RuntimeException($conn->error);
    }
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    if ($conn->errno) {
        throw new RuntimeException($conn->error);
    }
}

echo "Importing schema...\n";
run_sql_file($root, BASE_PATH . '/database/schema.sql');
echo "Importing seed data...\n";
run_sql_file($root, BASE_PATH . '/database/seed.sql');
$root->close();

// Use the application DB layer from here, with the credentials above.
Database::conn();

echo "Securing demo passwords...\n";
foreach ([
    'admin@possaas.id' => 'Admin#12345',
    'owner@demo.id' => 'Demo#12345',
    'manager@demo.id' => 'Demo#12345',
    'kasir@demo.id' => 'Demo#12345',
    'dapur@demo.id' => 'Demo#12345',
] as $email => $password) {
    Database::execute('UPDATE users SET password_hash = ? WHERE email = ?', [password_hash($password, PASSWORD_ARGON2ID), $email]);
}

echo "Issuing demo enterprise license...\n";
License::issue(1, 4, 365, null, 1);
Database::execute(
    "INSERT INTO subscription_invoices (public_id, tenant_id, subscription_id, number, amount, status, period_start, period_end, due_date, paid_at, created_at)
     VALUES (?, 1, 1, ?, 999000, 'paid', UTC_TIMESTAMP(3), DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 MONTH), CURDATE(), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))",
    [ulid(), 'INV-SAAS-DEMO-0001']
);

if (!isset($opts['no-history'])) {
    echo "Generating demo sales history (120 days)...\n";
    $result = seed_history(1, 120);
    echo '  ' . json_encode($result) . "\n";
}

$tenant = Database::one('SELECT COUNT(*) AS c FROM tenants');
$orders = Database::one('SELECT COUNT(*) AS c FROM sales_orders');

echo "\nInstallation complete.\n";
echo "  URL              : " . Config::get('APP_URL') . "\n";
echo "  Database         : {$dbName} @ " . Config::get('DB_HOST') . ':' . Config::get('DB_PORT') . "\n";
echo "  Tenants          : " . (int) ($tenant['c'] ?? 0) . "\n";
echo "  Transactions     : " . (int) ($orders['c'] ?? 0) . "\n";
echo "  Super admin      : admin@possaas.id / Admin#12345\n";
echo "  Demo owner       : owner@demo.id / Demo#12345\n";
echo "  Demo cashier     : kasir@demo.id / Demo#12345\n";
echo "\nNext: php -S 127.0.0.1:8080 -t public  (or configure Nginx/Apache)\n";
echo "      python python/ai_service.py  (AI analytics, port 8765)\n";
