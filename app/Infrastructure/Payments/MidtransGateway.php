<?php
declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Core\Config;
use App\Core\Http;

final class MidtransGateway implements PaymentGateway
{
    private string $serverKey;
    private bool $production;

    public function __construct()
    {
        $this->serverKey = (string) Config::get('MIDTRANS_SERVER_KEY', '');
        $this->production = Config::get('PAYMENT_MODE', 'sandbox') === 'production';
    }

    private function baseUrl(): string
    {
        return $this->production ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }

    private function auth(): array
    {
        if ($this->serverKey === '') {
            throw new \RuntimeException('MIDTRANS_SERVER_KEY belum diatur.');
        }
        return ['Authorization: Basic ' . base64_encode($this->serverKey . ':')];
    }

    public function createQris(array $payload): array
    {
        $res = Http::json('POST', $this->baseUrl() . '/v2/charge', [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => $payload['order_no'],
                'gross_amount' => (int) round((float) $payload['amount']),
            ],
            'qris' => ['acquirer' => 'gopay'],
            'custom_expiry' => [
                'expiry_duration' => (int) ($payload['expires_minutes'] ?? 15),
                'unit' => 'minute',
            ],
        ], $this->auth());
        if ($res['status'] >= 400) {
            throw new \RuntimeException('Midtrans: ' . ($res['body']['status_message'] ?? 'gagal membuat QRIS'));
        }
        $qr = (string) ($res['body']['qr_string'] ?? '');
        foreach (($res['body']['actions'] ?? []) as $action) {
            if (($action['name'] ?? '') === 'generate-qr-code') {
                $qr = $qr !== '' ? $qr : (string) ($action['url'] ?? '');
            }
        }
        return [
            'provider' => 'midtrans',
            'provider_ref' => (string) ($res['body']['transaction_id'] ?? $payload['order_no']),
            'qr_string' => $qr,
            'status' => 'pending',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ((int) ($payload['expires_minutes'] ?? 15) * 60)),
            'raw' => $res['body'],
        ];
    }

    public function verifyWebhook(array $headers, string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        $expected = hash('sha512', ($data['order_id'] ?? '') . ($data['status_code'] ?? '') . ($data['gross_amount'] ?? '') . $this->serverKey);
        if (!hash_equals($expected, (string) ($data['signature_key'] ?? ''))) {
            return null;
        }
        $status = (string) ($data['transaction_status'] ?? 'pending');
        $mapped = in_array($status, ['settlement', 'capture'], true)
            ? 'paid'
            : ($status === 'expire' ? 'expired' : (in_array($status, ['deny', 'cancel'], true) ? 'failed' : 'pending'));
        return [
            'event_id' => (string) ($data['transaction_id'] ?? '') . ':' . $status,
            'provider_ref' => (string) ($data['transaction_id'] ?? ''),
            'order_no' => (string) ($data['order_id'] ?? ''),
            'amount' => (float) ($data['gross_amount'] ?? 0),
            'status' => $mapped,
            'verified' => true,
            'raw' => $data,
        ];
    }

    public function inquire(string $providerRef, string $orderNo): ?array
    {
        $res = Http::json('GET', $this->baseUrl() . '/v2/' . rawurlencode($orderNo) . '/status', [], $this->auth());
        if ($res['status'] >= 400) {
            return null;
        }
        $status = (string) ($res['body']['transaction_status'] ?? 'pending');
        return [
            'status' => in_array($status, ['settlement', 'capture'], true) ? 'settlement' : $status,
            'amount' => (float) ($res['body']['gross_amount'] ?? 0),
            'raw' => $res['body'],
        ];
    }

    public function refund(string $providerRef, float $amount, string $reason): bool
    {
        $res = Http::json('POST', $this->baseUrl() . '/v2/' . rawurlencode($providerRef) . '/refund', [
            'refund_key' => 'RF-' . ulid(),
            'amount' => (int) round($amount),
            'reason' => $reason,
        ], $this->auth());
        return $res['status'] < 400;
    }
}
