<?php
declare(strict_types=1);

namespace App\Modules\Audit;

use App\Core\Auth;
use App\Core\Database;

final class AuditLog
{
    public static function log(string $action, string $entity, ?string $entityId = null, ?array $before = null, ?array $after = null, ?int $tenantId = null, ?int $userId = null): void
    {
        try {
            Database::insert('audit_logs', [
                'tenant_id' => $tenantId ?? (Auth::user()['tenant_id'] ?? null),
                'user_id' => $userId ?? (Auth::id() ?: null),
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                'after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                'ip' => client_ip(),
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable $e) {
            // Audit must never break a transaction, but it must be visible in logs.
            error_log('[audit] ' . $e->getMessage());
        }
    }

    public static function security(string $action, array $meta = []): void
    {
        self::log($action, 'security', null, null, $meta, Auth::user()['tenant_id'] ?? null);
    }

    public static function recent(int $tenantId, int $limit = 100): array
    {
        return Database::all(
            'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit)),
            [$tenantId]
        );
    }
}
