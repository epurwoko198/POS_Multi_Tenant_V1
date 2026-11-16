<?php
declare(strict_types=1);

namespace App\Modules\Purchasing;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Audit\AuditLog;
use App\Modules\POS\StockService;

final class PurchasingController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('purchasing.manage');
        $this->requireFeature('purchasing');
        $tenantId = $this->tenantId();
        $suppliers = Database::all('SELECT * FROM suppliers WHERE tenant_id = ? ORDER BY name', [$tenantId]);
        $orders = Database::all(
            'SELECT po.*, s.name AS supplier_name, o.name AS outlet_name, u.name AS user_name
             FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id
             JOIN outlets o ON o.id = po.outlet_id
             LEFT JOIN users u ON u.id = po.created_by
             WHERE po.tenant_id = ? ORDER BY po.id DESC LIMIT 40',
            [$tenantId]
        );
        $items = Database::all(
            'SELECT poi.*, p.name, p.unit FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id
             WHERE poi.purchase_order_id IN (SELECT id FROM purchase_orders WHERE tenant_id = ?)
             ORDER BY poi.id',
            [$tenantId]
        );
        $itemsByPo = [];
        foreach ($items as $item) {
            $itemsByPo[(int) $item['purchase_order_id']][] = $item;
        }
        $products = Database::all(
            'SELECT id, name, sku, unit, cost FROM products WHERE tenant_id = ? AND is_active = 1 ORDER BY name',
            [$tenantId]
        );
        $this->view('purchasing/index', [
            'title' => 'Pembelian & Supplier',
            'suppliers' => $suppliers,
            'orders' => $orders,
            'itemsByPo' => $itemsByPo,
            'products' => $products,
        ]);
    }

    public function supplier(): void
    {
        $this->requirePermission('purchasing.manage');
        $name = trim((string) $this->input('name', ''));
        if ($name === '') {
            flash('error', 'Nama supplier wajib diisi.');
            $this->redirect('/purchasing');
        }
        Database::insert('suppliers', [
            'tenant_id' => $this->tenantId(),
            'name' => $name,
            'contact' => trim((string) $this->input('contact', '')),
            'phone' => trim((string) $this->input('phone', '')),
            'email' => trim((string) $this->input('email', '')),
            'address' => trim((string) $this->input('address', '')),
            'terms_days' => max(0, $this->intInput('terms_days')),
            'is_active' => 1,
            'created_at' => now_utc(),
        ]);
        AuditLog::log('supplier_created', 'supplier', null, null, ['name' => $name]);
        flash('success', 'Supplier ditambahkan.');
        $this->redirect('/purchasing');
    }

    public function storeOrder(): void
    {
        $this->requirePermission('purchasing.manage');
        $tenantId = $this->tenantId();
        $supplierId = $this->intInput('supplier_id');
        $supplier = Database::one('SELECT * FROM suppliers WHERE id = ? AND tenant_id = ?', [$supplierId, $tenantId]);
        $items = json_decode((string) $this->input('items_json', '[]'), true);
        if (!$supplier || !is_array($items) || $items === []) {
            flash('error', 'Supplier dan minimal satu item wajib diisi.');
            $this->redirect('/purchasing');
        }
        try {
            $poId = Database::tx(static function () use ($tenantId, $supplierId, $items) {
                $subtotal = 0.0;
                $lines = [];
                foreach ($items as $item) {
                    $pid = (int) ($item['product_id'] ?? 0);
                    $qty = (float) ($item['qty'] ?? 0);
                    $cost = (float) ($item['unit_cost'] ?? 0);
                    $product = Database::one('SELECT * FROM products WHERE id = ? AND tenant_id = ?', [$pid, $tenantId]);
                    if (!$product || $qty <= 0) {
                        continue;
                    }
                    $lineTotal = round($qty * $cost, 2);
                    $subtotal += $lineTotal;
                    $lines[] = compact('pid', 'qty', 'cost', 'lineTotal');
                }
                if ($lines === []) {
                    throw new \RuntimeException('Tidak ada item pembelian yang valid.');
                }
                $number = 'PO-' . gmdate('Ymd') . '-' . strtoupper(substr(ulid(), -4));
                $poId = Database::insert('purchase_orders', [
                    'public_id' => ulid(),
                    'tenant_id' => $tenantId,
                    'outlet_id' => Auth::outletId(),
                    'supplier_id' => $supplierId,
                    'number' => $number,
                    'status' => 'ordered',
                    'subtotal' => $subtotal,
                    'tax_total' => 0,
                    'total' => $subtotal,
                    'notes' => substr((string) ($_POST['notes'] ?? ''), 0, 250),
                    'created_by' => Auth::id(),
                    'created_at' => now_utc(),
                ]);
                foreach ($lines as $line) {
                    Database::insert('purchase_order_items', [
                        'purchase_order_id' => $poId,
                        'product_id' => $line['pid'],
                        'qty' => $line['qty'],
                        'received_qty' => 0,
                        'unit_cost' => $line['cost'],
                        'line_total' => $line['lineTotal'],
                    ]);
                }
                AuditLog::log('purchase_order_created', 'purchase_order', (string) $poId, null, ['number' => $number, 'total' => $subtotal]);
                return $poId;
            });
            flash('success', 'Purchase order dibuat. Silakan terima barang saat tiba.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $this->redirect('/purchasing');
    }

    public function receive(string $id): void
    {
        $this->requirePermission('purchasing.manage');
        $tenantId = $this->tenantId();
        $po = Database::one('SELECT * FROM purchase_orders WHERE id = ? AND tenant_id = ?', [(int) $id, $tenantId]);
        if (!$po) {
            flash('error', 'Purchase order tidak ditemukan.');
            $this->redirect('/purchasing');
        }
        $items = json_decode((string) $this->input('items_json', '[]'), true);
        if (!is_array($items) || $items === []) {
            flash('error', 'Tidak ada item yang diterima.');
            $this->redirect('/purchasing');
        }
        try {
            Database::tx(static function () use ($po, $items) {
                StockService::receivePurchase($po, $items, Auth::id());
                AuditLog::log('purchase_received', 'purchase_order', (string) $po['id'], null, ['items' => $items]);
            });
            flash('success', 'Penerimaan barang dicatat dan stok diperbarui.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        $this->redirect('/purchasing');
    }
}
