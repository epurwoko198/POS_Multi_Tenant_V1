<?php
declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Core\Controller;
use App\Core\Database;
use App\Infrastructure\Payments\PaymentGatewayFactory;
use App\Modules\POS\PaymentService;

final class WebhookController extends Controller
{
    public function handle(string $provider): void
    {
        $body = (string) file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if ($headers === []) {
            foreach ($_SERVER as $k => $v) {
                if (str_starts_with($k, 'HTTP_')) {
                    $headers[str_replace('_', '-', substr($k, 5))] = $v;
                }
            }
        }
        try {
            $gateway = PaymentGatewayFactory::make($provider);
            $notification = $gateway->verifyWebhook($headers, $body);
        } catch (\Throwable $e) {
            error_log('[webhook] ' . $e->getMessage());
            $this->json(['ok' => false, 'message' => 'Gateway error'], 500);
        }
        if ($notification === null) {
            $this->json(['ok' => false, 'message' => 'Invalid signature'], 401);
        }
        $eventId = $provider . ':' . (string) ($notification['event_id'] ?: hash('sha256', $body));
        try {
            $inboxId = Database::insert('webhook_inbox', [
                'provider' => $provider,
                'event_id' => $eventId,
                'payload' => $body,
                'signature_verified' => 1,
                'created_at' => now_utc(),
            ]);
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                $this->json(['ok' => true, 'duplicate' => true]);
            }
            $this->json(['ok' => false, 'message' => 'Inbox error'], 500);
        }
        try {
            $result = PaymentService::applyNotification($provider, $notification);
            Database::execute('UPDATE webhook_inbox SET processed_at = ? WHERE id = ?', [now_utc(), $inboxId]);
            $this->json(['ok' => true, 'handled' => $result['handled']]);
        } catch (\Throwable $e) {
            error_log('[webhook] apply failed: ' . $e->getMessage());
            // Return 200 only for business-level duplicates; provider must retry real failures.
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
