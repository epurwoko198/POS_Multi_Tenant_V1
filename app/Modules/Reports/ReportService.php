<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Database;

final class ReportService
{
    public static function tzOffset(?string $timezone = null): string
    {
        $tzName = $timezone;
        if ($tzName === null) {
            $tenantId = \App\Core\Auth::tenantId();
            $tzName = $tenantId > 0
                ? (string) (Database::value('SELECT timezone FROM tenants WHERE id = ?', [$tenantId], 'Asia/Jakarta') ?: 'Asia/Jakarta')
                : 'Asia/Jakarta';
        }
        try {
            $tz = new \DateTimeZone($tzName);
            $offset = $tz->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
        } catch (\Throwable) {
            $offset = 7 * 3600;
        }
        $sign = $offset >= 0 ? '+' : '-';
        $offset = abs($offset);
        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }

    private static function bounds(string $from, string $to, ?string $timezone = null): array
    {
        $offset = self::tzOffset($timezone);
        $start = new \DateTime($from . ' 00:00:00', new \DateTimeZone($offset));
        $end = new \DateTime($to . ' 23:59:59', new \DateTimeZone($offset));
        $start->setTimezone(new \DateTimeZone('UTC'));
        $end->setTimezone(new \DateTimeZone('UTC'));
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $offset];
    }

    public static function kpi(int $tenantId, ?int $outletId, string $from, string $to, ?string $timezone = null): array
    {
        [$start, $end] = self::bounds($from, $to, $timezone);
        $outletFilter = $outletId ? ' AND o.outlet_id = ?' : '';
        $params = [$tenantId];
        if ($outletId) {
            $params[] = $outletId;
        }
        $params[] = $start;
        $params[] = $end;
        $row = Database::one(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(o.grand_total),0) AS revenue,
                    COALESCE(SUM(o.discount_total),0) AS discounts, COALESCE(SUM(o.tax_total),0) AS taxes,
                    COALESCE(SUM(o.service_charge),0) AS service_charge, COALESCE(AVG(o.grand_total),0) AS avg_basket
             FROM sales_orders o
             WHERE o.tenant_id = ? $outletFilter
               AND o.status IN ('paid','completed','partially_refunded')
               AND o.created_at BETWEEN ? AND ?",
            $params
        ) ?? [];
        $profitParams = $params;
        $profit = Database::one(
            "SELECT COALESCE(SUM(soi.line_total - (soi.qty * soi.unit_cost)),0) AS gross_profit
             FROM sales_order_items soi
             JOIN sales_orders o ON o.id = soi.order_id
             WHERE o.tenant_id = ? $outletFilter
               AND o.status IN ('paid','completed','partially_refunded')
               AND o.created_at BETWEEN ? AND ?",
            $profitParams
        );
        $refund = Database::one(
            "SELECT COALESCE(SUM(r.amount),0) AS refunded FROM refunds r
             WHERE r.tenant_id = ? AND r.status = 'processed' AND r.created_at BETWEEN ? AND ?",
            [$tenantId, $start, $end]
        );
        $pending = Database::one(
            "SELECT COUNT(*) AS c, COALESCE(SUM(p.amount),0) AS amount FROM payments p
             WHERE p.tenant_id = ? AND p.status = 'pending'" . ($outletId ? ' AND p.order_id IN (SELECT id FROM sales_orders WHERE outlet_id = ?)' : ''),
            $outletId ? [$tenantId, $outletId] : [$tenantId]
        );
        return [
            'orders' => (int) ($row['orders'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0),
            'discounts' => (float) ($row['discounts'] ?? 0),
            'taxes' => (float) ($row['taxes'] ?? 0),
            'service_charge' => (float) ($row['service_charge'] ?? 0),
            'avg_basket' => (float) ($row['avg_basket'] ?? 0),
            'gross_profit' => (float) ($profit['gross_profit'] ?? 0),
            'refunded' => (float) ($refund['refunded'] ?? 0),
            'pending_count' => (int) ($pending['c'] ?? 0),
            'pending_amount' => (float) ($pending['amount'] ?? 0),
        ];
    }

    public static function series(int $tenantId, ?int $outletId, int $days = 14, ?string $timezone = null): array
    {
        $offset = self::tzOffset($timezone);
        $to = new \DateTime('today', new \DateTimeZone($offset));
        $from = (clone $to)->modify('-' . ($days - 1) . ' days');
        [$start, $end] = self::bounds($from->format('Y-m-d'), $to->format('Y-m-d'), $timezone);
        $outletFilter = $outletId ? ' AND outlet_id = ?' : '';
        $params = [$tenantId];
        if ($outletId) {
            $params[] = $outletId;
        }
        $params[] = $start;
        $params[] = $end;
        $rows = Database::all(
            "SELECT DATE(CONVERT_TZ(created_at, '+00:00', ?)) AS d, COUNT(*) AS orders, SUM(grand_total) AS revenue
             FROM sales_orders
             WHERE tenant_id = ? $outletFilter AND status IN ('paid','completed','partially_refunded')
               AND created_at BETWEEN ? AND ?
             GROUP BY d ORDER BY d",
            array_merge([$offset], $params)
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = $r;
        }
        $labels = [];
        $revenue = [];
        $orders = [];
        for ($i = 0; $i < $days; $i++) {
            $date = (clone $from)->modify('+' . $i . ' days')->format('Y-m-d');
            $labels[] = (clone $from)->modify('+' . $i . ' days')->format('d M');
            $revenue[] = (float) ($map[$date]['revenue'] ?? 0);
            $orders[] = (int) ($map[$date]['orders'] ?? 0);
        }
        return ['labels' => $labels, 'revenue' => $revenue, 'orders' => $orders];
    }

    public static function topProducts(int $tenantId, ?int $outletId, int $days = 30, int $limit = 8, ?string $timezone = null): array
    {
        $offset = self::tzOffset($timezone);
        $outletFilter = $outletId ? ' AND o.outlet_id = ?' : '';
        $params = [$offset, $tenantId];
        if ($outletId) {
            $params[] = $outletId;
        }
        $params[] = $offset;
        return Database::all(
            "SELECT soi.name_snapshot AS name, SUM(soi.qty) AS qty, SUM(soi.line_total) AS revenue
             FROM sales_order_items soi
             JOIN sales_orders o ON o.id = soi.order_id
             WHERE o.tenant_id = ? $outletFilter
               AND o.status IN ('paid','completed','partially_refunded')
               AND o.created_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL $days DAY)
             GROUP BY soi.name_snapshot
             ORDER BY revenue DESC LIMIT " . max(1, min(50, $limit)),
            array_merge([$tenantId], $outletId ? [$outletId] : [])
        );
    }

    public static function paymentMix(int $tenantId, ?int $outletId, int $days = 30): array
    {
        $outletFilter = $outletId ? ' AND o.outlet_id = ?' : '';
        $params = [$tenantId];
        if ($outletId) {
            $params[] = $outletId;
        }
        return Database::all(
            "SELECT p.method, COUNT(*) AS total, SUM(p.amount) AS amount
             FROM payments p JOIN sales_orders o ON o.id = p.order_id
             WHERE p.tenant_id = ? $outletFilter AND p.status IN ('paid','settled')
               AND o.created_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL $days DAY)
             GROUP BY p.method ORDER BY amount DESC",
            $params
        );
    }

    public static function hourly(int $tenantId, ?int $outletId, ?string $timezone = null): array
    {
        $offset = self::tzOffset($timezone);
        $outletFilter = $outletId ? ' AND outlet_id = ?' : '';
        $params = [$offset, $tenantId];
        if ($outletId) {
            $params[] = $outletId;
        }
        $rows = Database::all(
            "SELECT HOUR(CONVERT_TZ(created_at, '+00:00', ?)) AS h, COUNT(*) AS orders, SUM(grand_total) AS revenue
             FROM sales_orders
             WHERE tenant_id = ? $outletFilter AND status IN ('paid','completed','partially_refunded')
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)
             GROUP BY h ORDER BY h",
            $params
        );
        $labels = [];
        $values = [];
        for ($h = 0; $h < 24; $h++) {
            $labels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
            $values[] = 0.0;
            foreach ($rows as $r) {
                if ((int) $r['h'] === $h) {
                    $values[$h] = (float) $r['revenue'];
                }
            }
        }
        return ['labels' => $labels, 'revenue' => $values];
    }

    public static function salesByOutlet(int $tenantId, string $from, string $to, ?string $timezone = null): array
    {
        [$start, $end] = self::bounds($from, $to, $timezone);
        return Database::all(
            "SELECT o2.name, COUNT(*) AS orders, COALESCE(SUM(o.grand_total),0) AS revenue
             FROM sales_orders o JOIN outlets o2 ON o2.id = o.outlet_id
             WHERE o.tenant_id = ? AND o.status IN ('paid','completed','partially_refunded')
               AND o.created_at BETWEEN ? AND ?
             GROUP BY o2.id, o2.name ORDER BY revenue DESC",
            [$tenantId, $start, $end]
        );
    }

    public static function dailyRows(int $tenantId, ?int $outletId, string $from, string $to, ?string $timezone = null): array
    {
        [$start, $end, $offset] = self::bounds($from, $to, $timezone);
        $outletFilter = $outletId ? ' AND o.outlet_id = ?' : '';
        $params = [$offset, $tenantId];
        if ($outletId) {
            $params[] = $outletId;
        }
        $params[] = $start;
        $params[] = $end;
        return Database::all(
            "SELECT DATE(CONVERT_TZ(o.created_at, '+00:00', ?)) AS sales_date, COUNT(*) AS orders,
                    SUM(o.subtotal) AS subtotal, SUM(o.discount_total) AS discounts, SUM(o.tax_total) AS taxes,
                    SUM(o.grand_total) AS revenue
             FROM sales_orders o
             WHERE o.tenant_id = ? $outletFilter AND o.status IN ('paid','completed','partially_refunded')
               AND o.created_at BETWEEN ? AND ?
             GROUP BY sales_date ORDER BY sales_date DESC",
            $params
        );
    }

    public static function lowStock(int $tenantId, int $outletId, int $limit = 10): array
    {
        return Database::all(
            'SELECT p.id, p.name, p.sku, p.unit, p.reorder_point, sb.qty
             FROM products p
             LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.outlet_id = ? AND sb.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.is_active = 1 AND p.track_stock = 1
               AND (sb.qty IS NULL OR sb.qty <= p.reorder_point)
             ORDER BY (COALESCE(sb.qty,0) - p.reorder_point) ASC LIMIT ' . max(1, min(100, $limit)),
            [$outletId, $tenantId]
        );
    }
}

