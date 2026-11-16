<?php
declare(strict_types=1);

namespace App\Modules\POS;

use App\Core\Auth;
use App\Core\Database;
use App\Core\License;
use App\Infrastructure\Payments\PaymentGatewayFactory;
use App\Modules\Audit\AuditLog;
use RuntimeException;

final class OrderService
{
    public static function checkout(array $input): array
    {
        $tenantId = Auth::tenantId();
        $userId = Auth::id();
        $outletId = (int) ($input['outlet_id'] ?? Auth::outletId());
        $outlet = Database::one('SELECT * FROM outlets WHERE id = ? AND tenant_id = ? AND is_active = 1', [$outletId, $tenantId]);
        if (!$outlet) {
            throw new RuntimeException('Outlet tidak valid.');
        }
        if (!License::canTransact()) {
            throw new RuntimeException('Langganan tidak aktif. Perpanjang lisensi untuk transaksi baru.');
        }
        $limitError = License::checkLimit('limit_tx_month');
        if ($limitError !== null) {
            throw new RuntimeException($limitError);
        }
        if (!License::enabled('pos')) {
            throw new RuntimeException('Modul POS tidak aktif pada lisensi Anda.');
        }
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        if ($items === []) {
            throw new RuntimeException('Keranjang masih kosong.');
        }
        $method = (string) ($input['payment_method'] ?? 'cash');
        if (!in_array($method, ['cash', 'qris', 'edc', 'transfer', 'ewallet'], true)) {
            throw new RuntimeException('Metode pembayaran tidak dikenal.');
        }
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? '')) ?: null;
        if ($idempotencyKey !== null) {
            $existing = Database::one(
                'SELECT p.*, o.public_id AS order_public, o.order_no FROM payments p JOIN sales_orders o ON o.id = p.order_id
                 WHERE p.tenant_id = ? AND p.idempotency_key = ? LIMIT 1',
                [$tenantId, $idempotencyKey]
            );
            if ($existing) {
                return [
                    'duplicate' => true,
                    'order' => Database::one('SELECT * FROM sales_orders WHERE id = ?', [(int) $existing['order_id']]),
                    'payment' => $existing,
                    'receipt_url' => '/pos/receipt/' . $existing['order_public'],
                ];
            }
        }
        $shift = Database::one(
            "SELECT * FROM cash_shifts WHERE tenant_id = ? AND outlet_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
            [$tenantId, $outletId, $userId]
        );
        if (!$shift) {
            throw new RuntimeException('Buka shift kasir terlebih dahulu sebelum transaksi.');
        }
        $registerId = (int) ($input['register_id'] ?? ($shift['register_id'] ?? 0));
        $register = Database::one('SELECT * FROM registers WHERE id = ? AND tenant_id = ? AND outlet_id = ?', [$registerId, $tenantId, $outletId]);
        if (!$register) {
            $register = Database::one('SELECT * FROM registers WHERE tenant_id = ? AND outlet_id = ? AND is_active = 1 ORDER BY id LIMIT 1', [$tenantId, $outletId]);
            $registerId = $register ? (int) $register['id'] : 0;
        }

        $productIds = [];
        foreach ($items as $item) {
            $productIds[] = (int) ($item['product_id'] ?? 0);
        }
        $productIds = array_values(array_unique(array_filter($productIds)));
        if ($productIds === []) {
            throw new RuntimeException('Item tidak valid.');
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = Database::all(
            "SELECT * FROM products WHERE tenant_id = ? AND id IN ($placeholders) AND is_active = 1",
            array_merge([$tenantId], $productIds)
        );
        $products = [];
        foreach ($rows as $p) {
            $products[(int) $p['id']] = $p;
        }

        $lines = [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            if (!isset($products[$pid])) {
                throw new RuntimeException('Produk pada keranjang tidak ditemukan atau tidak aktif.');
            }
            $product = $products[$pid];
            if ((int) $product['is_sellable'] !== 1) {
                throw new RuntimeException($product['name'] . ' bukan produk yang dapat dijual.');
            }
            $qty = round((float) ($item['qty'] ?? 0), 3);
            if ($qty <= 0 || $qty > 100000) {
                throw new RuntimeException('Qty untuk ' . $product['name'] . ' tidak valid.');
            }
            $price = (float) $product['price'];
            $gross = round($qty * $price, 2);
            $discount = min(max(0, round((float) ($item['discount'] ?? 0), 2)), $gross);
            $net = round($gross - $discount, 2);
            $subtotal = round($subtotal + $net, 2);
            $lines[] = [
                'product' => $product,
                'qty' => $qty,
                'price' => $price,
                'discount' => $discount,
                'net' => $net,
                'note' => substr((string) ($item['note'] ?? ''), 0, 150),
            ];
        }
        $orderDiscount = min(max(0, round((float) ($input['discount_total'] ?? 0), 2)), $subtotal);
        $factor = $subtotal > 0 ? ($subtotal - $orderDiscount) / $subtotal : 0.0;
        $taxTotal = 0.0;
        $itemCost = 0.0;
        foreach ($lines as &$line) {
            $taxable = round($line['net'] * $factor, 2);
            $rate = (float) $line['product']['tax_rate'];
            $line['tax'] = round($taxable * $rate / 100, 2);
            $line['taxable'] = $taxable;
            $taxTotal = round($taxTotal + $line['tax'], 2);
            $itemCost = round($itemCost + ($line['qty'] * (float) $line['product']['cost']), 2);
        }
        unset($line);
        $orderType = (string) ($input['order_type'] ?? 'retail');
        if (!in_array($orderType, ['retail', 'dine_in', 'takeaway', 'delivery'], true)) {
            $orderType = 'retail';
        }
        $serviceRate = $orderType === 'dine_in' ? (float) $outlet['service_charge_rate'] : 0.0;
        $serviceCharge = round(max(0, $subtotal - $orderDiscount) * $serviceRate / 100, 2);
        $grandTotal = round($subtotal - $orderDiscount + $taxTotal + $serviceCharge, 2);
        if ($grandTotal < 0) {
            throw new RuntimeException('Total transaksi tidak valid.');
        }
        $customerId = (int) ($input['customer_id'] ?? 0) ?: null;
        if ($customerId) {
            $customer = Database::one('SELECT id FROM customers WHERE id = ? AND tenant_id = ?', [$customerId, $tenantId]);
            if (!$customer) {
                $customerId = null;
            }
        }
        $tableId = (int) ($input['table_id'] ?? 0) ?: null;
        $tendered = round((float) ($input['tendered'] ?? 0), 2);
        $changeDue = 0.0;
        if (in_array($method, ['cash', 'edc', 'transfer'], true)) {
            if ($tendered < $grandTotal) {
                if ($method === 'cash') {
                    throw new RuntimeException('Uang diterima kurang dari total tagihan.');
                }
                $tendered = $grandTotal;
            }
            $changeDue = round($tendered - $grandTotal, 2);
        }

        $orderNo = self::nextOrderNo($tenantId, $outletId, (string) $outlet['code']);

        $ids = Database::tx(static function () use (
            $tenantId, $outletId, $registerId, $userId, $shift, $customerId, $tableId, $orderType,
            $orderNo, $subtotal, $orderDiscount, $taxTotal, $serviceCharge, $grandTotal,
            $lines, $input, $method, $tendered, $changeDue, $idempotencyKey, $outlet
        ) {
            $orderId = Database::insert('sales_orders', [
                'public_id' => ulid(),
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'register_id' => $registerId ?: null,
                'cashier_id' => $userId,
                'shift_id' => (int) $shift['id'],
                'customer_id' => $customerId,
                'table_id' => $tableId,
                'order_no' => $orderNo,
                'order_type' => $orderType,
                'status' => 'awaiting_payment',
                'currency' => 'IDR',
                'subtotal' => $subtotal,
                'discount_total' => $orderDiscount,
                'tax_total' => $taxTotal,
                'service_charge' => $serviceCharge,
                'grand_total' => $grandTotal,
                'paid_total' => 0,
                'notes' => substr((string) ($input['notes'] ?? ''), 0, 250),
                'created_at' => now_utc(),
            ]);
            foreach ($lines as $line) {
                Database::insert('sales_order_items', [
                    'tenant_id' => $tenantId,
                    'order_id' => $orderId,
                    'product_id' => (int) $line['product']['id'],
                    'name_snapshot' => $line['product']['name'],
                    'sku_snapshot' => $line['product']['sku'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['price'],
                    'unit_cost' => $line['product']['cost'],
                    'discount' => $line['discount'],
                    'tax_rate' => $line['product']['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'line_total' => $line['net'],
                    'station' => $line['product']['station'],
                    'notes' => $line['note'],
                ]);
            }
            $paymentId = Database::insert('payments', [
                'public_id' => ulid(),
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'method' => $method,
                'provider' => in_array($method, ['qris', 'ewallet'], true) ? (string) \App\Core\Config::get('PAYMENT_PROVIDER', 'mock') : 'manual',
                'provider_ref' => null,
                'amount' => $grandTotal,
                'tendered' => $tendered,
                'change_due' => $changeDue,
                'status' => 'created',
                'expires_at' => in_array($method, ['qris', 'ewallet'], true) ? gmdate('Y-m-d H:i:s', time() + 900) : null,
                'signature_verified' => 0,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now_utc(),
            ]);
            AuditLog::log('order_created', 'sales_order', (string) $orderId, null, [
                'order_no' => $orderNo,
                'grand_total' => $grandTotal,
                'payment_method' => $method,
            ], $tenantId);
            return ['order_id' => $orderId, 'payment_id' => $paymentId];
        });

        $order = Database::one('SELECT * FROM sales_orders WHERE id = ?', [$ids['order_id']]);
        $payment = Database::one('SELECT * FROM payments WHERE id = ?', [$ids['payment_id']]);

        if (in_array($method, ['qris', 'ewallet'], true)) {
            try {
                $gateway = PaymentGatewayFactory::make();
                $charge = $gateway->createQris([
                    'order_no' => $orderNo,
                    'amount' => $grandTotal,
                    'order_public_id' => $order['public_id'],
                    'expires_minutes' => 15,
                    'tenant_id' => $tenantId,
                ]);
                Database::update('payments', [
                    'provider' => $charge['provider'],
                    'provider_ref' => $charge['provider_ref'],
                    'qr_string' => $charge['qr_string'],
                    'status' => 'pending',
                    'expires_at' => $charge['expires_at'],
                    'raw_json' => json_encode($charge['raw'], JSON_UNESCAPED_UNICODE),
                ], 'id = ?', [$ids['payment_id']]);
                $payment = Database::one('SELECT * FROM payments WHERE id = ?', [$ids['payment_id']]);
            } catch (\Throwable $e) {
                Database::execute("UPDATE payments SET status = 'failed' WHERE id = ?", [$ids['payment_id']]);
                AuditLog::log('payment_gateway_error', 'payment', (string) $ids['payment_id'], null, ['error' => $e->getMessage()], $tenantId);
                throw new RuntimeException('Gagal membuat pembayaran QRIS: ' . $e->getMessage());
            }
        } else {
            $providerRef = trim((string) ($input['provider_ref'] ?? ''));
            if (in_array($method, ['edc', 'transfer'], true) && $providerRef === '') {
                $providerRef = strtoupper($method) . '-' . ulid();
            }
            $settled = PaymentService::settle($ids['payment_id'], $method === 'cash', $providerRef);
            $order = $settled['order'];
            $payment = $settled['payment'];
        }

        return [
            'duplicate' => false,
            'order' => $order,
            'payment' => $payment,
            'receipt_url' => '/pos/receipt/' . $order['public_id'],
            'print_url' => '/pos/print/' . $order['id'],
            'grand_total' => $grandTotal,
            'change_due' => $changeDue,
        ];
    }

    private static function nextOrderNo(int $tenantId, int $outletId, string $outletCode): string
    {
        $prefix = strtoupper($outletCode) . '-' . gmdate('Ymd') . '-';
        $last = Database::one(
            'SELECT order_no FROM sales_orders WHERE tenant_id = ? AND outlet_id = ? AND order_no LIKE ? ORDER BY id DESC LIMIT 1',
            [$tenantId, $outletId, $prefix . '%']
        );
        $seq = 1;
        if ($last) {
            $parts = explode('-', (string) $last['order_no']);
            $seq = (int) end($parts) + 1;
        }
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public static function cancel(int $orderId, int $tenantId, int $userId, string $reason): void
    {
        Database::tx(static function () use ($orderId, $tenantId, $userId, $reason) {
            $order = Database::one('SELECT * FROM sales_orders WHERE id = ? AND tenant_id = ? FOR UPDATE', [$orderId, $tenantId]);
            if (!$order) {
                throw new RuntimeException('Order tidak ditemukan.');
            }
            if (in_array($order['status'], ['paid', 'completed'], true)) {
                throw new RuntimeException('Order yang sudah lunas tidak dapat dibatalkan. Gunakan refund.');
            }
            Database::execute("UPDATE sales_orders SET status = 'cancelled', notes = ? WHERE id = ?", [substr($reason, 0, 250), $orderId]);
            Database::execute("UPDATE payments SET status = 'cancelled' WHERE order_id = ? AND status IN ('created','pending')", [$orderId]);
            AuditLog::log('order_cancelled', 'sales_order', (string) $orderId, null, ['reason' => $reason], $tenantId, $userId);
        });
    }
}
