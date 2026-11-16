<?php
declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Core\Database;

/**
 * Sandbox gateway. Generates a deterministic QRIS-like payload so the whole
 * "pending -> paid -> receipt" flow can be exercised without a PJP account.
 * It never marks an order paid by itself: settlement goes through the same
 * verified webhook / simulate endpoint as real providers.
 */
final class MockGateway implements PaymentGateway
{
    public function createQris(array $payload): array
    {
        $ref = 'MOCK-' . strtoupper(bin2hex(random_bytes(5)));
        $amount = (int) round((float) $payload['amount']);
        $qr = '00020101021226' . str_pad((string) $amount, 10, '0', STR_PAD_LEFT)
            . '5802ID540' . strlen((string) $amount) . $amount
            . '62070503***6304' . strtoupper(substr(hash('sha256', $ref . $amount), 0, 8));
        return [
            'provider' => 'mock',
            'provider_ref' => $ref,
            'qr_string' => $qr,
            'status' => 'pending',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ((int) ($payload['expires_minutes'] ?? 15) * 60)),
            'raw' => ['simulation' => true, 'order_no' => $payload['order_no']],
        ];
    }

    public function verifyWebhook(array $headers, string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['provider_ref'])) {
            return null;
        }
        // Mock webhooks require the shared AI/agent secret as a bearer token so
        // the endpoint cannot be triggered by an anonymous visitor.
        $auth = '';
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'authorization') {
                $auth = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }
        }
        $secret = (string) \App\Core\Config::get('AI_SHARE_TOKEN', '');
        if ($secret === '' || !hash_equals('Bearer ' . $secret, $auth)) {
            return null;
        }
        return [
            'event_id' => (string) ($data['event_id'] ?? $data['provider_ref']),
            'provider_ref' => (string) $data['provider_ref'],
            'order_no' => (string) ($data['order_no'] ?? ''),
            'amount' => (float) ($data['amount'] ?? 0),
            'status' => (string) ($data['status'] ?? 'pending'),
            'verified' => true,
            'raw' => $data,
        ];
    }

    public function inquire(string $providerRef, string $orderNo): ?array
    {
        $row = Database::one('SELECT status, amount FROM payments WHERE provider_ref = ? LIMIT 1', [$providerRef]);
        if (!$row) {
            return null;
        }
        return [
            'status' => in_array($row['status'], ['paid', 'settled'], true) ? 'settlement' : 'pending',
            'amount' => (float) $row['amount'],
            'raw' => [],
        ];
    }

    public function refund(string $providerRef, float $amount, string $reason): bool
    {
        return true;
    }
}
