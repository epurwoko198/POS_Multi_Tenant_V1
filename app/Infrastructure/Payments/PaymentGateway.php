<?php
declare(strict_types=1);

namespace App\Infrastructure\Payments;

interface PaymentGateway
{
    /**
     * Create a dynamic QRIS charge locked to one order and amount.
     *
     * @param array{order_no:string,amount:float,order_public_id:string,expires_minutes:int,tenant_id:int} $payload
     * @return array{provider:string,provider_ref:string,qr_string:string,status:string,expires_at:string,raw:array}
     */
    public function createQris(array $payload): array;

    /** Normalize a provider notification. Returns null when signature is invalid. */
    public function verifyWebhook(array $headers, string $body): ?array;

    /** Server-to-server status inquiry (source of truth). */
    public function inquire(string $providerRef, string $orderNo): ?array;

    public function refund(string $providerRef, float $amount, string $reason): bool;
}
