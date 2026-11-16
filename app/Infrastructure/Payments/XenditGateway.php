<?php
declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Core\Config;
use App\Core\Http;

final class XenditGateway implements PaymentGateway
{
    private string $secret;
    private string $callbackToken;

    public function __construct()
    {
        $this->secret = (string) Config::get('XENDIT_SECRET_KEY', '');
        $this->callbackToken = (string) Config::get('XENDIT_CALLBACK_TOKEN', '');
    }

    private function auth(): array
    {
        if ($this->secret === '') {
            throw new \RuntimeException('XENDIT_SECRET_KEY belum diatur.');
        }
        return ['Authorization: Basic ' . base64_encode($this->secret . ':')];
    }

    public function createQris(array $payload): array
    {
        $res = Http::json('POST', 'https://api.xendit.co/qr_codes', [
            'external_id' => $payload['order_no'],
            'type' => 'DYNAMIC',
            'callback_url' => rtrim((string) Config::get('APP_URL', ''), '/') . '/webhooks/payments/xendit',
            'amount' => (int) round((float) $payload['amount']),
        ], $this->auth());
        if ($res['status'] >= 400) {
            throw new \RuntimeException('Xendit: ' . ($res['body']['message'] ?? 'gagal membuat QRIS'));
        }
        return [
            'provider' => 'xendit',
            'provider_ref' => (string) ($res['body']['id'] ?? $payload['order_no']),
            'qr_string' => (string) ($res['body']['qr_string'] ?? ''),
            'status' => 'pending',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ((int) ($payload['expires_minutes'] ?? 15) * 60)),
            'raw' => $res['body'],
        ];
    }

    public function verifyWebhook(array $headers, string $body): ?array
    {
        $token = '';
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'x-callback-token') {
                $token = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
            }
        }
        if ($this->callbackToken === '' || !hash_equals($this->callbackToken, $token)) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        $status = strtoupper((string) ($data['status'] ?? ''));
        return [
            'event_id' => (string) ($data['id'] ?? $data['external_id'] ?? ''),
            'provider_ref' => (string) ($data['qr_code']['id'] ?? $data['id'] ?? ''),
            'order_no' => (string) ($data['external_id'] ?? ''),
            'amount' => (float) ($data['amount'] ?? 0),
            'status' => $status === 'SUCCEEDED' ? 'paid' : ($status === 'EXPIRED' ? 'expired' : 'pending'),
            'verified' => true,
            'raw' => $data,
        ];
    }

    public function inquire(string $providerRef, string $orderNo): ?array
    {
        $res = Http::json('GET', 'https://api.xendit.co/qr_codes/' . rawurlencode($providerRef), [], $this->auth());
        if ($res['status'] >= 400) {
            return null;
        }
        return [
            'status' => ($res['body']['status'] ?? '') === 'SUCCEEDED' ? 'settlement' : 'pending',
            'amount' => (float) ($res['body']['amount'] ?? 0),
            'raw' => $res['body'],
        ];
    }

    public function refund(string $providerRef, float $amount, string $reason): bool
    {
        $res = Http::json('POST', 'https://api.xendit.co/refunds', [
            'qr_code_id' => $providerRef,
            'amount' => (int) round($amount),
            'reason' => $reason,
        ], $this->auth());
        return $res['status'] < 400;
    }
}
