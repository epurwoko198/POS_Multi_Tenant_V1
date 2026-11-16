<?php
declare(strict_types=1);

/**
 * Generates 120 days of realistic, transactional history for the demo tenant.
 * The data is deterministic (fixed seed) and writes the same tables the POS
 * uses: orders, items, payments, stock ledger, shifts, loyalty, KDS tickets.
 *
 * Usage: php bin/seed_history.php [tenant_id] [days]
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function seed_history(int $tenantId = 1, int $days = 120): array
{
    $existing = (int) Database::value('SELECT COUNT(*) FROM sales_orders WHERE tenant_id = ?', [$tenantId], 0);
    if ($existing > 0) {
        return ['skipped' => true, 'orders' => $existing];
    }
    mt_srand(20261115);
    $outlet = Database::one('SELECT * FROM outlets WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId]);
    if (!$outlet) {
        throw new RuntimeException('Outlet demo tidak ditemukan.');
    }
    $outletId = (int) $outlet['id'];
    $products = Database::all(
        'SELECT * FROM products WHERE tenant_id = ? AND is_active = 1 AND is_sellable = 1 ORDER BY id',
        [$tenantId]
    );
    if ($products === []) {
        throw new RuntimeException('Produk demo tidak ditemukan.');
    }
    $customers = Database::all('SELECT id, points, total_spent FROM customers WHERE tenant_id = ?', [$tenantId]);
    $cashier = Database::one("SELECT id FROM users WHERE tenant_id = ? AND email = 'kasir@demo.id'", [$tenantId]);
    $cashierId = (int) ($cashier['id'] ?? 2);
    $register = Database::one('SELECT id FROM registers WHERE tenant_id = ? ORDER BY id LIMIT 1', [$tenantId]);
    $registerId = (int) ($register['id'] ?? 1);

    $recipes = [];
    foreach (Database::all('SELECT * FROM recipes WHERE tenant_id = ?', [$tenantId]) as $recipe) {
        $recipes[(int) $recipe['product_id']] = Database::all(
            'SELECT ingredient_id, qty FROM recipe_items WHERE recipe_id = ?',
            [(int) $recipe['id']]
        );
    }
    $balances = [];
    foreach (Database::all('SELECT product_id, qty FROM stock_balances WHERE tenant_id = ? AND outlet_id = ?', [$tenantId, $outletId]) as $row) {
        $balances[(int) $row['product_id']] = (float) $row['qty'];
    }
    $costs = [];
    foreach (Database::all('SELECT id, cost FROM products WHERE tenant_id = ?', [$tenantId]) as $row) {
        $costs[(int) $row['id']] = (float) $row['cost'];
    }
    $customerMap = [];
    foreach ($customers as $c) {
        $customerMap[(int) $c['id']] = ['points' => (int) $c['points'], 'spent' => (float) $c['total_spent']];
    }

    $methods = ['cash', 'cash', 'cash', 'cash', 'qris', 'qris', 'qris', 'edc', 'transfer'];
    $orderCount = 0;
    $shiftCount = 0;
    $start = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
    $start = $start->modify('-' . ($days - 1) . ' days');

    for ($d = 0; $d < $days; $d++) {
        $day = $start->modify('+' . $d . ' days');
        $weekday = (int) $day->format('N');
        $isSunday = $weekday === 7;
        $nOrders = $isSunday ? random_int(4, 8) : random_int(8, 15);

        $openLocal = $day->setTime(8, 0);
        $closeLocal = $day->setTime(22, 0);
        $shiftId = Database::insert('cash_shifts', [
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'register_id' => $registerId,
            'user_id' => $cashierId,
            'opening_float' => 200000,
            'status' => 'closed',
            'opened_at' => $openLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'closed_at' => $closeLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'notes' => 'Shift demo',
        ]);
        $shiftCount++;
        $cashTotal = 0.0;
        $seq = 0;

        for ($i = 0; $i < $nOrders; $i++) {
            $seq++;
            $hour = random_int(9, 21);
            $minute = random_int(0, 59);
            $localTime = $day->setTime($hour, $minute);
            $createdUtc = $localTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $orderType = $isSunday ? 'retail' : (random_int(1, 100) <= 30 ? 'dine_in' : (random_int(1, 100) <= 20 ? 'takeaway' : 'retail'));
            if (!in_array($orderType, ['retail', 'dine_in', 'takeaway'], true)) {
                $orderType = 'retail';
            }

            // 1-4 distinct products, weighted toward drinks/food.
            $chosen = [];
            $pickCount = random_int(1, 4);
            for ($k = 0; $k < $pickCount; $k++) {
                $p = $products[random_int(0, count($products) - 1)];
                $chosen[(int) $p['id']] = $p;
            }
            $lines = [];
            $subtotal = 0.0;
            $taxTotal = 0.0;
            foreach ($chosen as $p) {
                $qty = in_array((int) $p['id'], [1, 2, 3, 4], true) ? random_int(1, 2) : random_int(1, 3);
                $gross = round($qty * (float) $p['price'], 2);
                $tax = round($gross * (float) $p['tax_rate'] / 100, 2);
                $subtotal = round($subtotal + $gross, 2);
                $taxTotal = round($taxTotal + $tax, 2);
                $lines[] = ['product' => $p, 'qty' => $qty, 'gross' => $gross, 'tax' => $tax];
            }
            $discount = 0.0;
            if (random_int(1, 100) <= 18 && $subtotal > 5000) {
                $discount = random_int(1, 3) * 2000;
                $discount = min($discount, $subtotal - 1000);
            }
            $service = $orderType === 'dine_in' ? round(($subtotal - $discount) * ((float) $outlet['service_charge_rate'] / 100), 2) : 0.0;
            $grand = round($subtotal - $discount + $taxTotal + $service, 2);
            $method = $methods[random_int(0, count($methods) - 1)];
            $customerId = null;
            if ($customerMap !== [] && random_int(1, 100) <= 45) {
                $customerId = array_rand($customerMap);
            }
            $orderNo = sprintf('%s-%s-%04d', $outlet['code'], $day->format('Ymd'), $seq);
            $orderId = Database::insert('sales_orders', [
                'public_id' => ulid(),
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'register_id' => $registerId,
                'cashier_id' => $cashierId,
                'shift_id' => $shiftId,
                'customer_id' => $customerId,
                'table_id' => null,
                'order_no' => $orderNo,
                'order_type' => $orderType,
                'status' => 'completed',
                'currency' => 'IDR',
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'tax_total' => $taxTotal,
                'service_charge' => $service,
                'grand_total' => $grand,
                'paid_total' => $grand,
                'created_at' => $createdUtc,
                'paid_at' => $createdUtc,
                'completed_at' => $createdUtc,
            ]);
            foreach ($lines as $line) {
                $p = $line['product'];
                Database::insert('sales_order_items', [
                    'tenant_id' => $tenantId,
                    'order_id' => $orderId,
                    'product_id' => (int) $p['id'],
                    'name_snapshot' => $p['name'],
                    'sku_snapshot' => $p['sku'],
                    'qty' => $line['qty'],
                    'unit_price' => $p['price'],
                    'unit_cost' => $p['cost'],
                    'discount' => 0,
                    'tax_rate' => $p['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'line_total' => $line['gross'],
                    'station' => $p['station'],
                    'notes' => null,
                ]);
                // Stock movement (recipe ingredients or tracked product).
                if (isset($recipes[(int) $p['id']])) {
                    foreach ($recipes[(int) $p['id']] as $ing) {
                        $iid = (int) $ing['ingredient_id'];
                        $delta = -1 * $line['qty'] * (float) $ing['qty'];
                        $balances[$iid] = ($balances[$iid] ?? 0) + $delta;
                        Database::insert('stock_ledger', [
                            'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_id' => $iid,
                            'qty_delta' => $delta, 'unit_cost' => $costs[$iid] ?? 0,
                            'balance_after' => $balances[$iid], 'source_type' => 'sale',
                            'source_id' => $orderId, 'note' => 'Resep ' . $orderNo,
                            'created_by' => $cashierId, 'created_at' => $createdUtc,
                        ]);
                    }
                } elseif ((int) $p['track_stock'] === 1) {
                    $pid = (int) $p['id'];
                    $delta = -1 * $line['qty'];
                    $balances[$pid] = ($balances[$pid] ?? 0) + $delta;
                    Database::insert('stock_ledger', [
                        'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_id' => $pid,
                        'qty_delta' => $delta, 'unit_cost' => $p['cost'],
                        'balance_after' => $balances[$pid], 'source_type' => 'sale',
                        'source_id' => $orderId, 'note' => 'Penjualan ' . $orderNo,
                        'created_by' => $cashierId, 'created_at' => $createdUtc,
                    ]);
                }
            }
            Database::insert('payments', [
                'public_id' => ulid(),
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'method' => $method,
                'provider' => $method === 'qris' ? 'mock' : 'manual',
                'provider_ref' => $method === 'qris' ? 'MOCK-HIST-' . $orderId : strtoupper($method) . '-HIST-' . $orderId,
                'amount' => $grand,
                'tendered' => $method === 'cash' ? ceil($grand / 5000) * 5000 : $grand,
                'change_due' => $method === 'cash' ? (ceil($grand / 5000) * 5000) - $grand : 0,
                'status' => 'paid',
                'qr_string' => null,
                'expires_at' => null,
                'paid_at' => $createdUtc,
                'signature_verified' => $method === 'qris' ? 1 : 0,
                'idempotency_key' => 'history-' . $orderId,
                'created_at' => $createdUtc,
            ]);
            if ($method === 'cash') {
                $cashTotal += $grand;
            }
            if ($customerId !== null) {
                $points = (int) floor($grand / 1000);
                $customerMap[$customerId]['points'] += $points;
                $customerMap[$customerId]['spent'] += $grand;
                Database::insert('loyalty_transactions', [
                    'tenant_id' => $tenantId, 'customer_id' => $customerId, 'order_id' => $orderId,
                    'points_delta' => $points, 'reason' => 'purchase', 'created_at' => $createdUtc,
                ]);
            }
            if ($orderType === 'dine_in') {
                $ticketId = Database::insert('kitchen_tickets', [
                    'public_id' => ulid(), 'tenant_id' => $tenantId, 'order_id' => $orderId,
                    'station' => 'kitchen', 'status' => 'served', 'created_at' => $createdUtc, 'updated_at' => $createdUtc,
                ]);
                foreach ($lines as $line) {
                    if ($line['product']['station'] === null) {
                        continue;
                    }
                    Database::insert('kitchen_ticket_items', [
                        'ticket_id' => $ticketId, 'order_item_id' => null,
                        'name' => $line['product']['name'], 'qty' => $line['qty'], 'notes' => null,
                    ]);
                }
            }
            $orderCount++;
        }
        Database::execute(
            'UPDATE cash_shifts SET expected_cash = ?, closing_count = ?, variance = 0 WHERE id = ?',
            [round(200000 + $cashTotal, 2), round(200000 + $cashTotal, 2), $shiftId]
        );
    }

    foreach ($balances as $pid => $qty) {
        Database::execute(
            'INSERT INTO stock_balances (tenant_id, outlet_id, product_id, qty) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
            [$tenantId, $outletId, $pid, round($qty, 3)]
        );
    }
    foreach ($customerMap as $cid => $data) {
        Database::execute('UPDATE customers SET points = ?, total_spent = ? WHERE id = ?', [$data['points'], $data['spent'], $cid]);
    }
    Database::execute(
        'UPDATE customers SET tier = CASE WHEN points >= 300 THEN "gold" WHEN points >= 100 THEN "silver" ELSE "basic" END WHERE tenant_id = ?',
        [$tenantId]
    );

    return ['skipped' => false, 'orders' => $orderCount, 'shifts' => $shiftCount, 'days' => $days];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $tenantId = (int) ($argv[1] ?? 1);
    $days = (int) ($argv[2] ?? 120);
    $result = seed_history($tenantId, $days);
    echo 'Demo history: ' . json_encode($result) . PHP_EOL;
}
