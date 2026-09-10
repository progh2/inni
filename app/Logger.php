<?php

declare(strict_types=1);

namespace Inni;

final class Logger
{
    public static function write(
        string $action,
        string $entityType,
        string $entityId,
        string $summary,
        ?array $meta = null,
        ?array $actor = null,
    ): void {
        $actor = $actor ?? Auth::user();
        Database::pdo()->prepare(
            'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        )->execute([
            Support::id('log'),
            $action,
            $entityType,
            $entityId,
            $actor['id'] ?? null,
            $actor['display_name'] ?? '시스템',
            $summary,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            Support::now(),
        ]);
    }
}
