<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, string $entity = '', string $entityId = '', array $details = [], ?int $userId = null): void
    {
        try {
            Db::insert('audit_log', [
                'user_id' => $userId ?? Auth::id(),
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'details' => $details ? Masker::json($details) : null,
                'ip' => App::isCli() ? 'cli' : Request::ip(),
                'created_at' => now_str(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('app', 'Falha ao gravar auditoria: ' . $e->getMessage());
        }
    }
}
