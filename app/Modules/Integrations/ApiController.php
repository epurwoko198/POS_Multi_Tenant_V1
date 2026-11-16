<?php
declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\License;

final class ApiController extends Controller
{
    public function health(): void
    {
        $dbOk = true;
        try {
            Database::value('SELECT 1', [], 1);
        } catch (\Throwable) {
            $dbOk = false;
        }
        $this->json([
            'ok' => $dbOk,
            'service' => Config::get('APP_NAME', 'Berkah POS'),
            'version' => '1.0.0',
            'time' => now_utc(),
            'database' => $dbOk ? 'up' : 'down',
        ], $dbOk ? 200 : 503);
    }

    public function products(): void
    {
        $tenantId = $this->authenticate();
        $this->requireApiFeature($tenantId, 'api');
        $outletId = (int) ($_GET['outlet_id'] ?? Database::value('SELECT id FROM outlets WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId], 0));
        $rows = Database::all(
            'SELECT p.id, p.sku, p.barcode, p.name, p.price, p.cost, p.unit, p.tax_rate,
                    COALESCE(sb.qty,0) AS stock
             FROM products p
             LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.outlet_id = ? AND sb.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_sellable = 1
             ORDER BY p.name LIMIT 1000',
            [$outletId, $tenantId]
        );
        $this->json(['ok' => true, 'outlet_id' => $outletId, 'data' => $rows]);
    }

    public function sales(): void
    {
        $tenantId = $this->authenticate();
        $this->requireApiFeature($tenantId, 'api');
        $from = (string) ($_GET['from'] ?? gmdate('Y-m-d', time() - 30 * 86400));
        $to = (string) ($_GET['to'] ?? gmdate('Y-m-d'));
        $rows = Database::all(
            "SELECT o.order_no, o.created_at, o.status, o.order_type, o.subtotal, o.discount_total, o.tax_total,
                    o.grand_total, o.paid_total, o.public_id
             FROM sales_orders o
             WHERE o.tenant_id = ? AND o.created_at BETWEEN ? AND ?
             ORDER BY o.id DESC LIMIT 1000",
            [$tenantId, $from . ' 00:00:00', $to . ' 23:59:59']
        );
        $totals = Database::one(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(grand_total),0) AS revenue
             FROM sales_orders WHERE tenant_id = ? AND status IN ('paid','completed','partially_refunded')
               AND created_at BETWEEN ? AND ?",
            [$tenantId, $from . ' 00:00:00', $to . ' 23:59:59']
        );
        $this->json(['ok' => true, 'from' => $from, 'to' => $to, 'totals' => $totals, 'data' => $rows]);
    }

    public function printJobs(): void
    {
        $register = $this->authenticateDevice();
        $tenantId = (int) $register['tenant_id'];
        $this->requireApiFeature($tenantId, 'print');
        $jobs = Database::all(
            "SELECT j.public_id, j.order_id, j.copy_type, j.payload, j.attempts, j.created_at,
                    o.order_no, o.order_type, o.grand_total
             FROM print_jobs j JOIN sales_orders o ON o.id = j.order_id
             WHERE j.tenant_id = ? AND j.status IN ('queued','printing')
             ORDER BY j.id LIMIT 10",
            [$tenantId]
        );
        foreach ($jobs as &$job) {
            $job['payload'] = json_decode((string) $job['payload'], true);
            $job['attempts'] = (int) $job['attempts'];
        }
        unset($job);
        $this->json(['ok' => true, 'printer' => $register['printer'] ?? 'default', 'jobs' => $jobs]);
    }

    public function ackPrint(string $publicId): void
    {
        $register = $this->authenticateDevice();
        $job = Database::one('SELECT * FROM print_jobs WHERE public_id = ? AND tenant_id = ?', [$publicId, (int) $register['tenant_id']]);
        if (!$job) {
            $this->json(['ok' => false, 'message' => 'Job tidak ditemukan.'], 404);
        }
        $status = (string) ($this->payload()['status'] ?? 'printed');
        if (!in_array($status, ['printed', 'failed'], true)) {
            $this->json(['ok' => false, 'message' => 'Status tidak valid.'], 422);
        }
        Database::execute(
            'UPDATE print_jobs SET status = ?, attempts = attempts + 1, printed_at = ? WHERE id = ?',
            [$status, $status === 'printed' ? now_utc() : null, (int) $job['id']]
        );
        $this->json(['ok' => true, 'status' => $status]);
    }

    private function authenticate(): int
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_\-]+)$/i', $header, $m)) {
            $this->json(['ok' => false, 'message' => 'Bearer token diperlukan.'], 401);
        }
        $token = Database::one(
            "SELECT t.*, tn.status AS tenant_status FROM api_tokens t JOIN tenants tn ON tn.id = t.tenant_id
             WHERE t.token_hash = ? LIMIT 1",
            [hash('sha256', $m[1])]
        );
        if (!$token) {
            $this->json(['ok' => false, 'message' => 'Token API tidak valid.'], 401);
        }
        if (!in_array($token['tenant_status'], ['active', 'trial', 'grace', 'past_due'], true)) {
            $this->json(['ok' => false, 'message' => 'Langganan tenant tidak aktif.'], 403);
        }
        Database::execute('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [now_utc(), (int) $token['id']]);
        return (int) $token['tenant_id'];
    }

    private function authenticateDevice(): array
    {
        $key = (string) ($_SERVER['HTTP_X_DEVICE_KEY'] ?? '');
        if ($key === '') {
            $this->json(['ok' => false, 'message' => 'X-Device-Key diperlukan.'], 401);
        }
        $register = Database::one(
            'SELECT r.*, o.name AS outlet_name FROM registers r JOIN outlets o ON o.id = r.outlet_id
             WHERE r.device_key = ? AND r.is_active = 1 LIMIT 1',
            [$key]
        );
        if (!$register) {
            $this->json(['ok' => false, 'message' => 'Device key tidak valid.'], 401);
        }
        return $register;
    }

    private function requireApiFeature(int $tenantId, string $feature): void
    {
        if (!License::enabledFor($tenantId, $feature)) {
            $this->json(['ok' => false, 'message' => 'Modul ' . $feature . ' tidak aktif pada lisensi tenant.'], 403);
        }
        if (!License::canTransactFor($tenantId)) {
            $this->json(['ok' => false, 'message' => 'Langganan tenant tidak aktif.'], 402);
        }
    }
}
