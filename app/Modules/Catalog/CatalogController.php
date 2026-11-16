<?php
declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\License;
use App\Modules\Audit\AuditLog;
use App\Modules\POS\StockService;

final class CatalogController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('catalog.view');
        $this->requireFeature('catalog');
        $tenantId = $this->tenantId();
        $outletId = $this->outletId();
        $q = trim((string) ($_GET['q'] ?? ''));
        $categoryId = (int) ($_GET['category'] ?? 0);
        $sql = 'SELECT p.*, c.name AS category, COALESCE(sb.qty,0) AS stock
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.outlet_id = ? AND sb.tenant_id = p.tenant_id
                WHERE p.tenant_id = ?';
        $params = [$outletId, $tenantId];
        if ($q !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        if ($categoryId > 0) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY p.name LIMIT 500';
        $products = Database::all($sql, $params);
        $categories = Database::all('SELECT * FROM categories WHERE tenant_id = ? ORDER BY sort_order, name', [$tenantId]);
        $usage = License::usage('limit_products');
        $this->view('catalog/index', [
            'title' => 'Katalog Produk',
            'products' => $products,
            'categories' => $categories,
            'q' => $q,
            'categoryId' => $categoryId,
            'usage' => $usage,
            'limit' => License::limit('limit_products'),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('catalog.manage');
        $tenantId = $this->tenantId();
        $id = $this->intInput('id');
        $name = trim((string) $this->input('name', ''));
        $sku = strtoupper(trim((string) $this->input('sku', '')));
        if ($name === '' || $sku === '') {
            flash('error', 'Nama dan SKU wajib diisi.');
            $this->redirect('/catalog');
        }
        $price = max(0, $this->floatInput('price'));
        $cost = max(0, $this->floatInput('cost'));
        $data = [
            'category_id' => $this->intInput('category_id') ?: null,
            'name' => $name,
            'sku' => $sku,
            'barcode' => trim((string) $this->input('barcode', '')) ?: null,
            'unit' => trim((string) $this->input('unit', 'pcs')) ?: 'pcs',
            'cost' => $cost,
            'price' => $price,
            'tax_rate' => max(0, min(100, $this->floatInput('tax_rate'))),
            'station' => trim((string) $this->input('station', '')) ?: null,
            'track_stock' => $this->input('track_stock') ? 1 : 0,
            'is_sellable' => $this->input('is_sellable') ? 1 : 0,
            'reorder_point' => max(0, $this->floatInput('reorder_point')),
            'reorder_qty' => max(0, $this->floatInput('reorder_qty')),
            'is_active' => $this->input('is_active') ? 1 : 0,
        ];
        try {
            if ($id > 0) {
                $before = Database::one('SELECT * FROM products WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
                if (!$before) {
                    throw new \RuntimeException('Produk tidak ditemukan.');
                }
                Database::update('products', $data, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
                AuditLog::log('product_updated', 'product', (string) $id, $before, $data);
                flash('success', 'Produk ' . $name . ' diperbarui.');
            } else {
                License::assertLimit('limit_products');
                $data['public_id'] = ulid();
                $data['tenant_id'] = $tenantId;
                $data['created_at'] = now_utc();
                $newId = Database::insert('products', $data);
                $initialStock = max(0, $this->floatInput('initial_stock'));
                if ($initialStock > 0 && (int) $data['track_stock'] === 1) {
                    Database::tx(static function () use ($tenantId, $newId, $initialStock) {
                        StockService::adjust(
                            $tenantId,
                            Auth::outletId(),
                            $newId,
                            $initialStock,
                            'opening',
                            $newId,
                            'Stok awal produk',
                            0,
                            Auth::id()
                        );
                    });
                }
                AuditLog::log('product_created', 'product', (string) $newId, null, $data);
                flash('success', 'Produk ' . $name . ' ditambahkan.');
            }
        } catch (\Throwable $e) {
            flash('error', 'Gagal menyimpan produk: ' . $e->getMessage());
        }
        $this->redirect('/catalog');
    }

    public function category(): void
    {
        $this->requirePermission('catalog.manage');
        $name = trim((string) $this->input('name', ''));
        if ($name === '') {
            flash('error', 'Nama kategori wajib diisi.');
            $this->redirect('/catalog');
        }
        Database::insert('categories', [
            'tenant_id' => $this->tenantId(),
            'name' => $name,
            'sort_order' => $this->intInput('sort_order'),
            'is_active' => 1,
            'created_at' => now_utc(),
        ]);
        AuditLog::log('category_created', 'category', null, null, ['name' => $name]);
        flash('success', 'Kategori ditambahkan.');
        $this->redirect('/catalog');
    }
}
