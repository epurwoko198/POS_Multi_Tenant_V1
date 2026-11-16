<?php
declare(strict_types=1);

namespace App\Modules\POS;

use App\Core\Database;
use App\Modules\AI\DatasetService;
use App\Modules\Audit\AuditLog;
use RuntimeException;

final class PaymentService
{
    /**
     * Mark a payment paid and, when the order balance is settled, post stock,
     * queue the receipt, create KDS tickets and loyalty points exactly once.
     */
    public static function settle(int $paymentId, bool $verified, ?string $providerRef = null, array $raw = []): array
    {
        return Database::tx(static function () use ($paymentId, $verified, $providerRef, $raw) {
            $payment = Database::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$payment) {
                throw new RuntimeException('Pembayaran tidak ditemukan.');
            }
            $order = Database::one('SELECT * FROM sales_orders WHERE id = ? FOR UPDATE', [(int) $payment['order_id']]);
            if (!$order) {
                throw new RuntimeException('Order tidak ditemukan.');
            }
            if (in_array($payment['status'], ['paid', 'settled'], true)) {
                return ['payment' => $payment, 'order' => $order, 'already' => true];
            }
            if (in_array($order['status'], ['cancelled', 'refunded'], true)) {
                throw new RuntimeException('Order sudah dibatalkan, pembayaran tidak dapat diproses.');
            }
            $update = [
                'status' => 'paid',
                'paid_at' => now_utc(),
                'signature_verified' => $verified ? 1 : 0,
            ];
            if ($providerRef !== null && $providerRef !== '') {
                $update['provider_ref'] = $providerRef;
            }
            if ($raw !== []) {
                $update['raw_json'] = json_encode($raw, JSON_UNESCAPED_UNICODE);
            }
            Database::update('payments', $update, 'id = ?', [$paymentId]);
            Database::insert('payment_events', [
                'tenant_id' => (int) $payment['tenant_id'],
                'payment_id' => $paymentId,
                'event' => $verified ? 'provider_paid' : 'manual_paid',
                'status' => 'paid',
                'payload_json' => json_encode(['verified' => $verified, 'provider_ref' => $providerRef, 'raw' => $raw], JSON_UNESCAPED_UNICODE),
                'verified' => $verified ? 1 : 0,
                'created_at' => now_utc(),
            ]);
            $paidTotal = (float) Database::value(
                "SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ? AND status IN ('paid','settled')",
                [(int) $order['id']],
                0
            );
            $fullyPaid = $paidTotal + 0.009 >= (float) $order['grand_total'];
            Database::execute(
                'UPDATE sales_orders SET paid_total = ?, status = ?, paid_at = ?, completed_at = ? WHERE id = ?',
                [
                    $paidTotal,
                    $fullyPaid ? 'completed' : 'awaiting_payment',
                    now_utc(),
                    $fullyPaid ? now_utc() : null,
                    (int) $order['id'],
                ]
            );
            $order['paid_total'] = $paidTotal;
            $order['status'] = $fullyPaid ? 'completed' : 'awaiting_payment';
            $order['paid_at'] = now_utc();
            if ($fullyPaid) {
                StockService::postSale($order);
                PrintService::enqueueReceipt((int) $order['id'], (int) $order['tenant_id'], (int) $order['cashier_id'], 'original');
                KitchenService::createTickets($order);
                LoyaltyService::award($order);
                Database::insert('outbox_events', [
                    'tenant_id' => (int) $order['tenant_id'],
                    'type' => 'OrderPaid',
                    'payload_json' => json_encode(['order_id' => (int) $order['id'], 'order_no' => $order['order_no'], 'total' => (float) $order['grand_total']]),
                    'status' => 'done',
                    'attempts' => 0,
                    'available_at' => now_utc(),
                    'processed_at' => now_utc(),
                    'created_at' => now_utc(),
                ]);
                AuditLog::log('order_paid', 'sales_order', (string) $order['id'], null, [
                    'order_no' => $order['order_no'],
                    'paid_total' => $paidTotal,
                    'verified' => $verified,
                ], (int) $order['tenant_id']);
                DatasetService::invalidate((int) $order['tenant_id']);
            }
            $freshPayment = Database::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
            return ['payment' => $freshPayment, 'order' => $order, 'already' => false];
        });
    }

    /** Find a payment from a provider notification. */
    public static function findByProviderRef(string $providerRef, string $orderNo = ''): ?array
    {
        $payment = null;
        if ($providerRef !== '') {
            $payment = Database::one('SELECT * FROM payments WHERE provider_ref = ? LIMIT 1', [$providerRef]);
        }
        if (!$payment && $orderNo !== '') {
            $payment = Database::one(
                'SELECT p.* FROM payments p JOIN sales_orders o ON o.id = p.order_id WHERE o.order_no = ? ORDER BY p.id DESC LIMIT 1',
                [$orderNo]
            );
        }
        return $payment;
    }

    /** Apply a verified provider notification (idempotent by order + amount). */
    public static function applyNotification(string $provider, array $notification): array
    {
        $payment = self::findByProviderRef(
            (string) ($notification['provider_ref'] ?? ''),
            (string) ($notification['order_no'] ?? '')
        );
        if (!$payment) {
            throw new RuntimeException('Pembayaran untuk notifikasi ini tidak ditemukan.');
        }
        if (abs((float) $payment['amount'] - (float) ($notification['amount'] ?? 0)) > 0.01) {
            AuditLog::log('payment_amount_mismatch', 'payment', (string) $payment['id'], ['expected' => $payment['amount']], $notification, (int) $payment['tenant_id']);
            throw new RuntimeException('Nominal notifikasi tidak sesuai dengan payment intent.');
        }
        $status = (string) ($notification['status'] ?? 'pending');
        if ($status === 'paid') {
            $result = self::settle((int) $payment['id'], true, (string) ($notification['provider_ref'] ?? ''), $notification['raw'] ?? []);
            return ['result' => $result, 'handled' => 'paid'];
        }
        if (in_array($status, ['expired', 'failed'], true)) {
            Database::execute(
                "UPDATE payments SET status = ? WHERE id = ? AND status IN ('created','pending')",
                [$status === 'expired' ? 'expired' : 'failed', (int) $payment['id']]
            );
            Database::insert('payment_events', [
                'tenant_id' => (int) $payment['tenant_id'],
                'payment_id' => (int) $payment['id'],
                'event' => 'provider_' . $status,
                'status' => $status,
                'payload_json' => json_encode($notification['raw'] ?? []),
                'verified' => 1,
                'created_at' => now_utc(),
            ]);
            return ['result' => null, 'handled' => $status];
        }
        return ['result' => null, 'handled' => 'ignored'];
    }

    public static function expireStale(int $tenantId): int
    {
        return Database::execute(
            "UPDATE payments SET status = 'expired'
             WHERE tenant_id = ? AND status = 'pending' AND expires_at IS NOT NULL AND expires_at < ?",
            [$tenantId, now_utc()]
        );
    }
}
