<?php
declare(strict_types=1);

namespace App\Modules\POS;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\License;
use App\Modules\Audit\AuditLog;
use App\Modules\Reports\ReportService;

final class PosController extends Controller
{
    public function dashboard(): void
    {
        $this->requireFeature('pos');
        $tenantId = $this->tenantId();
        $outletId = $this->outletId();
        $outlet = Database::one('SELECT * FROM outlets WHERE id = ? AND tenant_id = ?', [$outletId, $tenantId]);
        if (!$outlet) {
            $this->redirect('/dashboard');
        }
        $timezone = (string) ($outlet['timezone'] ?? 'Asia/Jakarta');
        $today = dt_local(now_utc(), $timezone, 'Y-m-d');
        $kpi = ReportService::kpi($tenantId, $outletId, $today, $today, $timezone);
        $shift = Database::one(
            "SELECT s.*, r.name AS register_name FROM cash_shifts s
             LEFT JOIN registers r ON r.id = s.register_id
             WHERE s.tenant_id = ? AND s.user_id = ? AND s.status = 'open' ORDER BY s.id DESC LIMIT 1",
            [$tenantId, Auth::id()]
        );
        $cashSales = 0.0;
        $nonCash = 0.0;
        $cashIn = 0.0;
        $cashOut = 0.0;
        $expectedCash = 0.0;
        if ($shift) {
            $cashSales = (float) Database::value(
                "SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales_orders o ON o.id = p.order_id
                 WHERE o.shift_id = ? AND p.method = 'cash' AND p.status IN ('paid','settled')",
                [(int) $shift['id']],
                0
            );
            $nonCash = (float) Database::value(
                "SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales_orders o ON o.id = p.order_id
                 WHERE o.shift_id = ? AND p.method <> 'cash' AND p.status IN ('paid','settled')",
                [(int) $shift['id']],
                0
            );
            $cashIn = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE shift_id = ? AND type = 'in'", [(int) $shift['id']], 0);
            $cashOut = (float) Database::value("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE shift_id = ? AND type IN ('out','safe_drop')", [(int) $shift['id']], 0);
            $expectedCash = round((float) $shift['opening_float'] + $cashSales + $cashIn - $cashOut, 2);
        }
        $recent = Database::all(
            'SELECT o.public_id, o.order_no, o.grand_total, o.status, o.order_type, o.created_at, u.name AS cashier
             FROM sales_orders o JOIN users u ON u.id = o.cashier_id
             WHERE o.tenant_id = ? AND o.outlet_id = ? ORDER BY o.id DESC LIMIT 8',
            [$tenantId, $outletId]
        );
        $lowStock = ReportService::lowStock($tenantId, $outletId, 5);
        $kds = License::enabled('kds')
            ? Database::one(
                "SELECT COUNT(*) AS c FROM kitchen_tickets kt JOIN sales_orders o ON o.id = kt.order_id
                 WHERE kt.tenant_id = ? AND o.outlet_id = ? AND kt.status IN ('queued','preparing')",
                [$tenantId, $outletId]
            )
            : ['c' => 0];
        $this->view('pos/dashboard', [
            'title' => 'Dashboard POS',
            'outlet' => $outlet,
            'today' => $today,
            'kpi' => $kpi,
            'shift' => $shift,
            'cashSales' => $cashSales,
            'nonCash' => $nonCash,
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            'expectedCash' => $expectedCash,
            'recent' => $recent,
            'lowStock' => $lowStock,
            'pendingKds' => (int) ($kds['c'] ?? 0),
            'logoUrl' => tenant_logo_url($tenantId),
            'licenseNotice' => License::expiryNotice(),
        ]);
    }

    public function index(): void
    {
        $this->requirePermission('pos.sell');
        $this->requireFeature('pos');
        $tenantId = $this->tenantId();
        $outletId = $this->outletId();
        $outlet = Database::one('SELECT * FROM outlets WHERE id = ? AND tenant_id = ?', [$outletId, $tenantId]);
        if (!$outlet) {
            $this->redirect('/dashboard');
        }
        $products = Database::all(
            'SELECT p.id, p.sku, p.barcode, p.name, p.price, p.cost, p.unit, p.tax_rate, p.station,
                    p.track_stock, COALESCE(sb.qty, 0) AS stock, c.name AS category
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.outlet_id = ? AND sb.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.is_active = 1 AND p.is_sellable = 1
             ORDER BY c.sort_order, p.name',
            [$outletId, $tenantId]
        );
        $customers = Database::all(
            'SELECT id, name, phone, points, tier FROM customers WHERE tenant_id = ? ORDER BY name LIMIT 300',
            [$tenantId]
        );
        $tables = License::enabled('restaurant')
            ? Database::all('SELECT * FROM dining_tables WHERE tenant_id = ? AND outlet_id = ? ORDER BY area, name', [$tenantId, $outletId])
            : [];
        $shift = Database::one(
            "SELECT * FROM cash_shifts WHERE tenant_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
            [$tenantId, \App\Core\Auth::id()]
        );
        $registers = Database::all(
            'SELECT id, code, name FROM registers WHERE tenant_id = ? AND outlet_id = ? AND is_active = 1',
            [$tenantId, $outletId]
        );
        $this->view('pos/index', [
            'title' => 'POS Kasir',
            'outlet' => $outlet,
            'products' => $products,
            'customers' => $customers,
            'tables' => $tables,
            'shift' => $shift,
            'registers' => $registers,
            'restaurantEnabled' => License::enabled('restaurant'),
            'paymentProvider' => (string) Config::get('PAYMENT_PROVIDER', 'mock'),
            'isLocal' => Config::get('APP_ENV', 'production') !== 'production',
        ]);
    }

    public function checkout(): void
    {
        $this->requirePermission('pos.sell');
        set_time_limit(45);
        try {
            $result = OrderService::checkout($this->payload());
            $order = $result['order'];
            $this->json([
                'ok' => true,
                'duplicate' => $result['duplicate'] ?? false,
                'order' => [
                    'public_id' => $order['public_id'],
                    'order_no' => $order['order_no'],
                    'grand_total' => (float) $order['grand_total'],
                    'status' => $order['status'],
                ],
                'payment' => [
                    'status' => $result['payment']['status'] ?? 'pending',
                    'method' => $result['payment']['method'] ?? null,
                    'qr_string' => $result['payment']['qr_string'] ?? null,
                    'provider_ref' => $result['payment']['provider_ref'] ?? null,
                    'expires_at' => $result['payment']['expires_at'] ?? null,
                    'public_id' => $result['payment']['public_id'] ?? null,
                ],
                'receipt_url' => $result['receipt_url'],
                'change_due' => $result['change_due'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            AuditLog::log('checkout_failed', 'sales_order', null, null, ['error' => $e->getMessage()]);
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function receipt(string $publicId): void
    {
        $tenantId = $this->tenantId();
        $order = Database::one('SELECT * FROM sales_orders WHERE public_id = ? AND tenant_id = ?', [$publicId, $tenantId]);
        if (!$order) {
            http_response_code(404);
            $this->view('errors/404', ['title' => 'Nota tidak ditemukan'], 'guest');
            return;
        }
        if (!in_array($order['status'], ['paid', 'completed', 'partially_refunded'], true)) {
            http_response_code(409);
            $this->view('errors/409', ['title' => 'Nota belum tersedia', 'message' => 'Nota final hanya dicetak setelah pembayaran terverifikasi lunas.'], 'guest');
            return;
        }
        $data = PrintService::receiptData((int) $order['id'], $tenantId);
        $this->view('pos/receipt', array_merge($data, ['title' => 'Nota ' . $order['order_no']]), 'print');
    }

    public function printReceipt(string $orderId): void
    {
        $this->requirePermission('pos.sell');
        $tenantId = $this->tenantId();
        $order = Database::one('SELECT * FROM sales_orders WHERE id = ? AND tenant_id = ?', [(int) $orderId, $tenantId]);
        if (!$order) {
            $this->json(['ok' => false, 'message' => 'Order tidak ditemukan.'], 404);
        }
        try {
            $jobId = PrintService::enqueueReceipt((int) $order['id'], $tenantId, \App\Core\Auth::id(), 'reprint');
            AuditLog::log('receipt_reprint', 'print_job', (string) $jobId, null, ['order' => $order['order_no'], 'reason' => $this->input('reason', '')]);
            $this->json(['ok' => true, 'job_id' => $jobId, 'message' => 'Permintaan cetak ulang masuk antrean.']);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function paymentStatus(string $publicId): void
    {
        $tenantId = $this->tenantId();
        PaymentService::expireStale($tenantId);
        $payment = Database::one(
            'SELECT p.*, o.public_id AS order_public, o.order_no, o.status AS order_status
             FROM payments p JOIN sales_orders o ON o.id = p.order_id
             WHERE p.public_id = ? AND p.tenant_id = ?',
            [$publicId, $tenantId]
        );
        if (!$payment) {
            $this->json(['ok' => false, 'message' => 'Pembayaran tidak ditemukan.'], 404);
        }
        $this->json([
            'ok' => true,
            'status' => $payment['status'],
            'order_status' => $payment['order_status'],
            'qr_string' => $payment['qr_string'],
            'provider_ref' => $payment['provider_ref'],
            'expires_at' => $payment['expires_at'],
            'receipt_url' => '/pos/receipt/' . $payment['order_public'],
        ]);
    }

    public function simulatePaid(string $publicId): void
    {
        $this->requirePermission('pos.sell');
        if (Config::get('APP_ENV', 'production') === 'production') {
            $this->json(['ok' => false, 'message' => 'Simulasi hanya tersedia di lingkungan development.'], 403);
        }
        $payment = Database::one(
            'SELECT * FROM payments WHERE public_id = ? AND tenant_id = ?',
            [$publicId, $this->tenantId()]
        );
        if (!$payment) {
            $this->json(['ok' => false, 'message' => 'Pembayaran tidak ditemukan.'], 404);
        }
        if (!in_array($payment['status'], ['pending', 'created'], true)) {
            $this->json(['ok' => false, 'message' => 'Pembayaran tidak dalam status menunggu.'], 422);
        }
        $result = PaymentService::settle((int) $payment['id'], true, (string) $payment['provider_ref'], ['simulated' => true]);
        $this->json([
            'ok' => true,
            'status' => $result['payment']['status'],
            'order_status' => $result['order']['status'],
            'receipt_url' => '/pos/receipt/' . $result['order']['public_id'],
        ]);
    }

    public function cancel(string $publicId): void
    {
        $this->requirePermission('pos.sell');
        $order = Database::one('SELECT * FROM sales_orders WHERE public_id = ? AND tenant_id = ?', [$publicId, $this->tenantId()]);
        if (!$order) {
            $this->json(['ok' => false, 'message' => 'Order tidak ditemukan.'], 404);
        }
        try {
            OrderService::cancel((int) $order['id'], $this->tenantId(), \App\Core\Auth::id(), (string) $this->input('reason', 'Dibatalkan kasir'));
            $this->json(['ok' => true]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
