<?php
declare(strict_types=1);

namespace App\Modules\AI;

use App\Core\Database;

/**
 * Builds the analytics dataset for one tenant. The result is cached for a
 * short window on disk so the AI page can request several analyses without
 * re-reading the same transaction rows.
 */
final class DatasetService
{
    public static function payload(int $tenantId, int $cacheSeconds = 60): array
    {
        $file = BASE_PATH . '/storage/cache/ai-dataset-' . $tenantId . '.json';
        if (is_file($file) && (time() - (int) filemtime($file)) < $cacheSeconds) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && ($cached['tenant_id'] ?? 0) === $tenantId) {
                return $cached;
            }
        }
        $payload = self::build($tenantId);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
        return $payload;
    }

    public static function build(int $tenantId): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 365 * 86400);
        $orders = Database::all(
            "SELECT o.id, o.customer_id, o.created_at, o.grand_total, o.discount_total, o.tax_total, o.order_type
             FROM sales_orders o
             WHERE o.tenant_id = ? AND o.status IN ('paid','completed','partially_refunded') AND o.created_at >= ?
             ORDER BY o.id",
            [$tenantId, $since]
        );
        $items = Database::all(
            "SELECT soi.order_id, soi.product_id, soi.name_snapshot AS name, soi.qty, soi.unit_price,
                    soi.unit_cost, soi.line_total, o.created_at, o.outlet_id
             FROM sales_order_items soi
             JOIN sales_orders o ON o.id = soi.order_id
             WHERE o.tenant_id = ? AND o.status IN ('paid','completed','partially_refunded') AND o.created_at >= ?",
            [$tenantId, $since]
        );
        $products = Database::all(
            'SELECT id, name, sku, price, cost, reorder_point, reorder_qty, track_stock, is_sellable
             FROM products WHERE tenant_id = ?',
            [$tenantId]
        );
        $stock = Database::all(
            'SELECT sb.product_id, sb.outlet_id, sb.qty FROM stock_balances sb WHERE sb.tenant_id = ?',
            [$tenantId]
        );
        $customers = Database::all(
            'SELECT id, name, points, total_spent, tier, created_at FROM customers WHERE tenant_id = ?',
            [$tenantId]
        );
        return [
            'ok' => true,
            'tenant_id' => $tenantId,
            'generated_at' => now_utc(),
            'orders' => $orders,
            'items' => $items,
            'products' => $products,
            'stock' => $stock,
            'customers' => $customers,
        ];
    }

    public static function invalidate(int $tenantId): void
    {
        $file = BASE_PATH . '/storage/cache/ai-dataset-' . $tenantId . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
