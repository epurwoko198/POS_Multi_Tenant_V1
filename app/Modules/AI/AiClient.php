<?php
declare(strict_types=1);

namespace App\Modules\AI;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Http;

final class AiClient
{
    public static function available(): bool
    {
        try {
            $res = Http::json('GET', self::base() . '/health', [], self::headers(), 3);
            return $res['status'] === 200 && ($res['body']['status'] ?? '') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Analysis requests push the tenant dataset in the POST body. This avoids
     * a callback from Python into PHP (which would deadlock a single-worker
     * PHP server) and keeps the AI service network-independent.
     */
    public static function get(string $action, array $query = []): array
    {
        $tenantId = Auth::tenantId();
        $payload = array_merge($query, [
            'tenant_id' => $tenantId,
            'dataset' => DatasetService::payload($tenantId),
        ]);
        $res = Http::json('POST', self::base() . '/' . ltrim($action, '/'), $payload, self::headers(), 60);
        if ($res['status'] >= 400) {
            throw new \RuntimeException($res['body']['detail'] ?? 'AI service error');
        }
        return $res['body'];
    }

    public static function post(string $action, array $payload): array
    {
        $tenantId = Auth::tenantId();
        $payload['tenant_id'] = $tenantId;
        $payload['dataset'] = DatasetService::payload($tenantId);
        $res = Http::json('POST', self::base() . '/' . ltrim($action, '/'), $payload, self::headers(), 60);
        if ($res['status'] >= 400) {
            throw new \RuntimeException($res['body']['detail'] ?? 'AI service error');
        }
        return $res['body'];
    }

    public static function datasetUrl(): string
    {
        return rtrim((string) Config::get('APP_URL', 'http://127.0.0.1:8080'), '/') . '/internal/ai/dataset';
    }

    private static function base(): string
    {
        return rtrim((string) Config::get('AI_SERVICE_URL', 'http://127.0.0.1:8765'), '/');
    }

    private static function headers(): array
    {
        return ['X-AI-Token: ' . (string) Config::get('AI_SERVICE_TOKEN', 'change-me')];
    }
}
