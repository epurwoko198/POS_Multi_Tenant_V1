<?php
declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Core\Config;

final class PaymentGatewayFactory
{
    public static function make(?string $provider = null): PaymentGateway
    {
        $provider = $provider ?: (string) Config::get('PAYMENT_PROVIDER', 'mock');
        return match ($provider) {
            'midtrans' => new MidtransGateway(),
            'xendit' => new XenditGateway(),
            default => new MockGateway(),
        };
    }
}
