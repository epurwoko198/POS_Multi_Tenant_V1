<?php
declare(strict_types=1);

namespace App\Modules\POS;

use App\Core\Database;
use App\Core\License;

final class LoyaltyService
{
    public static function award(array $order): void
    {
        if (!License::enabled('loyalty') || empty($order['customer_id'])) {
            return;
        }
        $exists = Database::value(
            "SELECT COUNT(*) FROM loyalty_transactions WHERE order_id = ? AND reason = 'purchase'",
            [(int) $order['id']],
            0
        );
        if ((int) $exists > 0) {
            return;
        }
        $perThousand = (int) (setting((int) $order['tenant_id'], 'loyalty_points_per_1000', '1') ?? 1);
        $points = (int) floor(((float) $order['grand_total']) / 1000) * max(1, $perThousand);
        if ($points <= 0) {
            return;
        }
        Database::insert('loyalty_transactions', [
            'tenant_id' => (int) $order['tenant_id'],
            'customer_id' => (int) $order['customer_id'],
            'order_id' => (int) $order['id'],
            'points_delta' => $points,
            'reason' => 'purchase',
            'created_at' => now_utc(),
        ]);
        Database::execute(
            'UPDATE customers SET points = points + ?, total_spent = total_spent + ? WHERE id = ? AND tenant_id = ?',
            [$points, (float) $order['grand_total'], (int) $order['customer_id'], (int) $order['tenant_id']]
        );
        $customer = Database::one('SELECT points FROM customers WHERE id = ?', [(int) $order['customer_id']]);
        if ($customer) {
            $tier = (int) $customer['points'] >= 300 ? 'gold' : ((int) $customer['points'] >= 100 ? 'silver' : 'basic');
            Database::execute('UPDATE customers SET tier = ? WHERE id = ?', [$tier, (int) $order['customer_id']]);
        }
    }
}
