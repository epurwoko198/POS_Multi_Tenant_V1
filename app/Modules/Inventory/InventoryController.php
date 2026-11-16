<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\License;
use App\Modules\Audit\AuditLog;
use App\Modules\POS\StockService;

final class InventoryController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('inventory.view');
        $this->requireFeature('inventory');
        $tenantId = $this->tenantId();
        $outletId = $this->outletId();
        $balances = Database::all(
            'SELECT p.id, p.name, p.sku, p.unit, p.reorder_point, p.cost,
                    COALESCE(sb.qty,0) AS qty,
                    (COALESCE(sb.qty,0) * p.cost) AS value
             FROM products p
             LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.outlet_id = ? AND sb.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.track_stock = 1 AND p.is_active = 1
             ORDER BY (COALESCE(sb.qty,0) <= p.reorder_point) DESC, p.name',
            [$outletId, $tenantId]
        );
        $ledger = Database::all(
            'SELECT l.*, p.name, p.unit, u.name AS user_name
             FROM stock_ledger l JOIN products p ON p.id = l.product_id
             LEFT JOIN users u ON u.id = l.created_by
             WHERE l.tenant_id = ? ORDER BY l.id DESC LIMIT 60',
            [$tenantId]
        );
        $outlets = Database::all('SELECT id, code, name FROM outlets WHERE tenant_id = ? ORDER BY name', [$tenantId]);
        $products = Database::all(
            'SELECT id, name, sku, unit FROM products WHERE tenant_id = ? AND track_stock = 1 AND is_active = 1 ORDER BY name',
            [$tenantId]
        );
        $transfers = Database::all(
            'SELECT t.*, f.name AS from_name, d.name AS to_name, u.name AS user_name
             FROM stock_transfers t JOIN outlets f ON f.id = t.from_outlet_id JOIN outlets d ON d.id = t.to_outlet_id
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.tenant_id = ? ORDER BY t.id DESC LIMIT 15',
            [$tenantId]
        );
        $totalValue = 0.0;
        foreach ($balances as $b) {
            $totalValue += (float) $b['value'];
        }
        $this->view('inventory/index', [
            'title' => 'Inventori',
            'balances' => $balances,
            'ledger' => $ledger,
            'outlets' => $outlets,
            'products' => $products,
            'transfers' => $transfers,
            'totalValue' => $totalValue,
            'outletId' => $outletId,
            'multiOutlet' => License::enabled('multi_outlet'),
        ]);
    }

    public function adjust(): void
    {
        $this->requirePermission('inventory.manage');
        $tenantId = $this->tenantId();
        $productId = $this->intInput('product_id');
        $outletId = $this->intInput('outlet_id', $this->outletId());
        $delta = $this->floatInput('qty_delta');
        $note = trim((string) $this->input('note', ''));
        $outlet = Database::one('SELECT * FROM outlets WHERE id = ? AND tenant_id = ?', [$outletId, $tenantId]);
        if (!$outlet || $delta === 0.0) {
            flash('error', 'Data penyesuaian stok tidak valid.');
            $this->redirect('/inventory');
        }
        try {
            Database::tx(static function () use ($tenantId, $outletId, $productId, $delta, $note) {
                StockService::adjust($tenantId, $outletId, $productId, $delta, 'adjustment', null, $note ?: 'Penyesuaian manual', 0, Auth::id());
                AuditLog::log('stock_adjusted', 'product', (string) $productId, null, ['qty_delta' => $delta, 'outlet_id' => $outletId, 'note' => $note]);
            });
            flash('success', 'Stok disesuaikan ' . ($delta > 0 ? '+' : '') . number_id($delta, 3) . '.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $this->redirect('/inventory');
    }

    public function transfer(): void
    {
        $this->requirePermission('inventory.manage');
        if (!License::enabled('multi_outlet')) {
            flash('error', 'Transfer gudang membutuhkan modul Multi Outlet.');
            $this->redirect('/inventory');
        }
        $tenantId = $this->tenantId();
        $fromId = $this->intInput('from_outlet_id');
        $toId = $this->intInput('to_outlet_id');
        $from = Database::one('SELECT * FROM outlets WHERE id = ? AND tenant_id = ?', [$fromId, $tenantId]);
        $to = Database::one('SELECT * FROM outlets WHERE id = ? AND tenant_id = ?', [$toId, $tenantId]);
        $items = json_decode((string) $this->input('items_json', '[]'), true);
        if (!$from || !$to || $fromId === $toId || !is_array($items) || $items === []) {
            flash('error', 'Data transfer tidak valid.');
            $this->redirect('/inventory');
        }
        try {
            $number = 'TRF-' . gmdate('Ymd') . '-' . strtoupper(substr(ulid(), -3));
            Database::tx(static function () use ($from, $to, $items, $number) {
                StockService::transfer($from, $to, $items, $number, null, Auth::id());
                AuditLog::log('stock_transferred', 'stock_transfer', $number, null, ['items' => $items, 'from' => $from['id'], 'to' => $to['id']]);
            });
            flash('success', 'Transfer stok ' . $number . ' selesai.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $this->redirect('/inventory');
    }
}
