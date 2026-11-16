<?php
declare(strict_types=1);

namespace App\Modules\AI;

use App\Core\Controller;
use App\Core\Database;
use App\Core\License;

final class AiController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('ai.view');
        $this->requireFeature('ai');
        $this->view('ai/index', [
            'title' => 'AI Analytics',
            'available' => AiClient::available(),
            'serviceUrl' => (string) \App\Core\Config::get('AI_SERVICE_URL', 'http://127.0.0.1:8765'),
        ]);
    }

    public function api(string $action): void
    {
        $this->requirePermission('ai.view');
        $this->requireFeature('ai');
        $allowed = ['forecast', 'anomalies', 'reorder', 'rfm', 'basket', 'menu', 'elasticity', 'summary', 'overview'];
        if (!in_array($action, $allowed, true)) {
            $this->json(['ok' => false, 'message' => 'Aksi AI tidak dikenal.'], 404);
        }
        try {
            $query = $_GET;
            unset($query['action']);
            $data = AiClient::get($action, $query);
            $this->json(['ok' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }

    public function ask(): void
    {
        $this->requirePermission('ai.view');
        $this->requireFeature('ai');
        $payload = $this->payload();
        $question = trim((string) ($payload['question'] ?? ''));
        if ($question === '') {
            $this->json(['ok' => false, 'message' => 'Pertanyaan tidak boleh kosong.'], 422);
        }
        try {
            $data = AiClient::post('ask', ['question' => $question]);
            $this->json(['ok' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 503);
        }
    }

    /** Internal dataset endpoint consumed by the Python service (shared token). */
    public function dataset(): void
    {
        $expected = (string) \App\Core\Config::get('AI_SHARE_TOKEN', '');
        $given = (string) ($_SERVER['HTTP_X_AI_TOKEN'] ?? '');
        if ($expected === '' || !hash_equals($expected, $given)) {
            $this->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }
        $tenantId = (int) ($_GET['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            $this->json(['ok' => false, 'message' => 'tenant_id wajib.'], 422);
        }
        $this->json(DatasetService::build($tenantId));
    }
}
