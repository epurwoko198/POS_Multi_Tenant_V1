<?php
declare(strict_types=1);

namespace App\Modules\POS;

use App\Core\Database;
use RuntimeException;

/**
 * Every stock change goes through the append-only ledger and updates the
 * materialized balance in the same database transaction.
 */
final class StockService
{
    public static function adjust(
        int $tenantId,
        int $outletId,
        int $productId,
        float $delta,
        string $sourceType,
        ?int $sourceId = null,
        ?string $note = null,
        float $unitCost = 0.0,
        ?int $userId = null
    ): float {
        if (abs($delta) < 0.0001) {
            return (float) (Database::value(
                'SELECT qty FROM stock_balances WHERE tenant_id = ? AND outlet_id = ? AND product_id = ?',
                [$tenantId, $outletId, $productId],
                0
            ) ?: 0);
        }
        $product = Database::one(
            'SELECT id, name, track_stock FROM products WHERE id = ? AND tenant_id = ?',
            [$productId, $tenantId]
        );
        if (!$product) {
            throw new RuntimeException('Produk tidak ditemukan untuk pergerakan stok.');
        }
        if ((int) $product['track_stock'] !== 1) {
            return 0.0;
        }
        $current = (float) (Database::value(
            'SELECT qty FROM stock_balances WHERE tenant_id = ? AND outlet_id = ? AND product_id = ?',
            [$tenantId, $outletId, $productId],
            0
        ) ?: 0);
        $policy = setting($tenantId, 'negative_stock_policy', 'allow');
        if ($policy !== 'allow' && $current + $delta < -0.0001) {
            throw new RuntimeException('Stok ' . $product['name'] . ' tidak mencukupi (tersedia ' . number_id($current, 3) . ').');
        }
        Database::execute(
            'INSERT INTO stock_balances (tenant_id, outlet_id, product_id, qty) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)',
            [$tenantId, $outletId, $productId, $delta]
        );
        $balance = (float) Database::value(
            'SELECT qty FROM stock_balances WHERE tenant_id = ? AND outlet_id = ? AND product_id = ?',
            [$tenantId, $outletId, $productId],
            0
        );
        Database::insert('stock_ledger', [
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'product_id' => $productId,
            'qty_delta' => $delta,
            'unit_cost' => $unitCost,
            'balance_after' => $balance,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $note,
            'created_by' => $userId,
            'created_at' => now_utc(),
        ]);
        return $balance;
    }

    /** Post sale movement once per order (idempotent). */
    public static function postSale(array $order): void
    {
        $tenantId = (int) $order['tenant_id'];
        $outletId = (int) $order['outlet_id'];
        $orderId = (int) $order['id'];
        $already = Database::value(
            "SELECT COUNT(*) FROM stock_ledger WHERE tenant_id = ? AND source_type = 'sale' AND source_id = ?",
            [$tenantId, $orderId],
            0
        );
        if ((int) $already > 0) {
            return;
        }
        $items = Database::all(
            'SELECT soi.product_id, soi.qty, soi.unit_cost, p.track_stock, p.is_sellable, p.name
             FROM sales_order_items soi
             JOIN products p ON p.id = soi.product_id
             WHERE soi.order_id = ? AND soi.product_id IS NOT NULL',
            [$orderId]
        );
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $qty = (float) $item['qty'];
            $recipe = Database::one(
                'SELECT id FROM recipes WHERE tenant_id = ? AND product_id = ?',
                [$tenantId, $productId]
            );
            if ($recipe) {
                $ingredients = Database::all(
                    'SELECT ri.ingredient_id, ri.qty, p.cost, p.name
                     FROM recipe_items ri JOIN products p ON p.id = ri.ingredient_id
                     WHERE ri.recipe_id = ?',
                    [(int) $recipe['id']]
                );
                foreach ($ingredients as $ing) {
                    self::adjust(
                        $tenantId,
                        $outletId,
                        (int) $ing['ingredient_id'],
                        -1 * $qty * (float) $ing['qty'],
                        'sale',
                        $orderId,
                        'Resep untuk order ' . $order['order_no'],
                        (float) $ing['cost'],
                        (int) $order['cashier_id']
                    );
                }
                continue;
            }
            if ((int) $item['track_stock'] === 1) {
                self::adjust(
                    $tenantId,
                    $outletId,
                    $productId,
                    -1 * $qty,
                    'sale',
                    $orderId,
                    'Penjualan order ' . $order['order_no'],
                    (float) $item['unit_cost'],
                    (int) $order['cashier_id']
                );
            }
        }
    }

    public static function receivePurchase(array $po, array $items, int $userId): void
    {
        $tenantId = (int) $po['tenant_id'];
        $outletId = (int) $po['outlet_id'];
        foreach ($items as $item) {
            $qty = (float) ($item['receive_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $line = Database::one(
                'SELECT * FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?',
                [(int) $item['id'], (int) $po['id']]
            );
            if (!$line) {
                continue;
            }
            $remaining = (float) $line['qty'] - (float) $line['received_qty'];
            $qty = min($qty, $remaining);
            if ($qty <= 0) {
                continue;
            }
            Database::execute(
                'UPDATE purchase_order_items SET received_qty = received_qty + ? WHERE id = ?',
                [$qty, (int) $line['id']]
            );
            self::adjust(
                $tenantId,
                $outletId,
                (int) $line['product_id'],
                $qty,
                'purchase',
                (int) $po['id'],
                'Penerimaan PO ' . $po['number'],
                (float) $line['unit_cost'],
                $userId
            );
            // Moving average cost update.
            $product = Database::one('SELECT cost FROM products WHERE id = ?', [(int) $line['product_id']]);
            $balance = (float) (Database::value(
                'SELECT qty FROM stock_balances WHERE tenant_id = ? AND outlet_id = ? AND product_id = ?',
                [$tenantId, $outletId, (int) $line['product_id']],
                0
            ) ?: 0);
            if ($product && $balance > 0) {
                $oldCost = (float) $product['cost'];
                $newCost = (($oldCost * max(0, $balance - $qty)) + ((float) $line['unit_cost'] * $qty)) / max(0.0001, $balance);
                Database::execute('UPDATE products SET cost = ? WHERE id = ?', [round($newCost, 2), (int) $line['product_id']]);
            }
        }
        $pending = (int) Database::value(
            'SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ? AND received_qty < qty',
            [(int) $po['id']],
            0
        );
        Database::execute(
            'UPDATE purchase_orders SET status = ?, received_at = ? WHERE id = ?',
            [$pending > 0 ? 'partial' : 'received', $pending > 0 ? null : now_utc(), (int) $po['id']]
        );
    }

    public static function transfer(array $from, array $to, array $items, string $number, ?string $note, int $userId): int
    {
        $transferId = Database::insert('stock_transfers', [
            'public_id' => ulid(),
            'tenant_id' => (int) $from['tenant_id'],
            'from_outlet_id' => (int) $from['id'],
            'to_outlet_id' => (int) $to['id'],
            'number' => $number,
            'status' => 'completed',
            'note' => $note,
            'created_by' => $userId,
            'created_at' => now_utc(),
        ]);
        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $qty = (float) $item['qty'];
            if ($qty <= 0) {
                continue;
            }
            Database::insert('stock_transfer_items', [
                'transfer_id' => $transferId,
                'product_id' => $pid,
                'qty' => $qty,
            ]);
            self::adjust((int) $from['tenant_id'], (int) $from['id'], $pid, -$qty, 'transfer_out', $transferId, 'Transfer ' . $number, 0, $userId);
            self::adjust((int) $to['tenant_id'], (int) $to['id'], $pid, $qty, 'transfer_in', $transferId, 'Transfer ' . $number, 0, $userId);
        }
        return $transferId;
    }
}
