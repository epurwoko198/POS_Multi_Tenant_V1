<?php
declare(strict_types=1);

use App\Modules\AI\AiController;
use App\Modules\Integrations\ApiController;

/** @var \App\Core\Router $router */

$router->get('/api/v1/health', [ApiController::class, 'health']);
$router->get('/api/v1/products', [ApiController::class, 'products']);
$router->get('/api/v1/sales', [ApiController::class, 'sales']);
$router->get('/api/v1/print-jobs', [ApiController::class, 'printJobs']);
$router->post('/api/v1/print-jobs/{id}/ack', [ApiController::class, 'ackPrint']);

$router->get('/internal/ai/dataset', [AiController::class, 'dataset']);
