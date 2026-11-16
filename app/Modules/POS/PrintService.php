<?php
declare(strict_types=1);

namespace App\Modules\POS;

use App\Core\Database;
use RuntimeException;

final class PrintService
{
    public static function receiptData(int $orderId, int $tenantId): array
    {
        $order = Database::one(
            'SELECT o.*, o2.name AS outlet_name, o2.address AS outlet_address, o2.phone AS outlet_phone,
                    o2.receipt_footer, u.name AS cashier_name, c.name AS customer_name
             FROM sales_orders o
             JOIN outlets o2 ON o2.id = o.outlet_id
             JOIN users u ON u.id = o.cashier_id
             LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ? AND o.tenant_id = ?',
            [$orderId, $tenantId]
        );
        if (!$order) {
            throw new RuntimeException('Order tidak ditemukan.');
        }
        $items = Database::all(
            'SELECT name_snapshot, qty, unit_price, discount, tax_amount, line_total, notes
             FROM sales_order_items WHERE order_id = ? ORDER BY id',
            [$orderId]
        );
        $payments = Database::all(
            'SELECT method, provider, amount, tendered, change_due, status, provider_ref
             FROM payments WHERE order_id = ? ORDER BY id',
            [$orderId]
        );
        $tenant = Database::one('SELECT name, address, phone FROM tenants WHERE id = ?', [$tenantId]);
        return [
            'tenant' => $tenant,
            'logo_url' => tenant_logo_url($tenantId),
            'order' => $order,
            'items' => $items,
            'payments' => $payments,
            'printed_at' => now_utc(),
        ];
    }

    public static function enqueueReceipt(int $orderId, int $tenantId, int $userId, string $copyType = 'original'): int
    {
        $order = Database::one('SELECT * FROM sales_orders WHERE id = ? AND tenant_id = ?', [$orderId, $tenantId]);
        if (!$order) {
            throw new RuntimeException('Order tidak ditemukan.');
        }
        if (!in_array($order['status'], ['paid', 'completed', 'partially_refunded'], true)) {
            throw new RuntimeException('Nota hanya dapat dicetak setelah pembayaran lunas.');
        }
        $idem = $copyType === 'original'
            ? 'receipt-' . $orderId . '-original'
            : 'receipt-' . $orderId . '-reprint-' . ulid();
        $existing = Database::one('SELECT id FROM print_jobs WHERE idempotency_key = ?', [$idem]);
        if ($existing) {
            return (int) $existing['id'];
        }
        return Database::insert('print_jobs', [
            'public_id' => ulid(),
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'printer' => (string) setting($tenantId, 'default_printer', 'default'),
            'copy_type' => $copyType,
            'status' => 'queued',
            'payload' => json_encode(self::receiptData($orderId, $tenantId), JSON_UNESCAPED_UNICODE),
            'attempts' => 0,
            'idempotency_key' => $idem,
            'requested_by' => $userId,
            'created_at' => now_utc(),
        ]);
    }

    /** Plain-text 80mm receipt for browser printing / ESC-POS agents. */
    public static function plainText(array $data): string
    {
        $o = $data['order'];
        $lines = [];
        $lines[] = strtoupper((string) $data['tenant']['name']);
        $lines[] = (string) $o['outlet_name'];
        $lines[] = (string) $o['outlet_address'];
        $lines[] = 'Tel: ' . $o['outlet_phone'];
        $lines[] = str_repeat('-', 42);
        $lines[] = 'No   : ' . $o['order_no'];
        $lines[] = 'Tgl  : ' . dt_local($o['created_at'], null, 'd/m/Y H:i');
        $lines[] = 'Kasir: ' . $o['cashier_name'];
        if (!empty($o['customer_name'])) {
            $lines[] = 'Plgn : ' . $o['customer_name'];
        }
        $lines[] = str_repeat('-', 42);
        foreach ($data['items'] as $item) {
            $lines[] = substr((string) $item['name_snapshot'], 0, 30);
            $lines[] = '  ' . number_id($item['qty'], 2) . ' x ' . number_id($item['unit_price']) . str_pad(number_id($item['line_total']), 16, ' ', STR_PAD_LEFT);
        }
        $lines[] = str_repeat('-', 42);
        $lines[] = str_pad('Subtotal', 26) . str_pad(number_id($o['subtotal']), 16, ' ', STR_PAD_LEFT);
        if ((float) $o['discount_total'] > 0) {
            $lines[] = str_pad('Diskon', 26) . str_pad('-' . number_id($o['discount_total']), 16, ' ', STR_PAD_LEFT);
        }
        if ((float) $o['service_charge'] > 0) {
            $lines[] = str_pad('Service', 26) . str_pad(number_id($o['service_charge']), 16, ' ', STR_PAD_LEFT);
        }
        $lines[] = str_pad('Pajak', 26) . str_pad(number_id($o['tax_total']), 16, ' ', STR_PAD_LEFT);
        $lines[] = str_pad('TOTAL', 26) . str_pad(number_id($o['grand_total']), 16, ' ', STR_PAD_LEFT);
        foreach ($data['payments'] as $p) {
            $lines[] = str_pad(strtoupper((string) $p['method']), 26) . str_pad(number_id($p['amount']), 16, ' ', STR_PAD_LEFT);
        }
        $lines[] = str_repeat('-', 42);
        $lines[] = (string) $o['receipt_footer'];
        $lines[] = 'Terima kasih';
        return implode("\n", $lines);
    }
}
