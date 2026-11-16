<?php
declare(strict_types=1);

use App\Modules\Integrations\WebhookController;

/** @var \App\Core\Router $router */

$router->post('/webhooks/payments/{provider}', [WebhookController::class, 'handle']);
