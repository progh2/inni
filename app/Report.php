<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use Throwable;

final class Report
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_IMPOSSIBLE = 'impossible';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_IMPOSSIBLE,
    ];

    /** Open repair workflow — keeps an asset in `repair` when it was `available`. */
    /** @var list<string> */
    public const OPEN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
    ];

    /**
     * Forward-only repair workflow. Existing codes stay: open/in_progress/done + impossible(불가).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_IN_PROGRESS, self::STATUS_DONE, self::STATUS_IMPOSSIBLE],
        self::STATUS_IN_PROGRESS => [self::STATUS_DONE, self::STATUS_IMPOSSIBLE],
        self::STATUS_DONE => [],
        self::STATUS_IMPOSSIBLE => [],
    ];

    public static function canCreate(?array $user): bool
    {
        return Auth::canLoan($user);
    }

    public static function canTransition(?array $user): bool
    {
        return Auth::canWrite($user);
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function isOpenStatus(string $status): bool
    {
        return in_array($status, self::OPEN_STATUSES, true);
    }

    /**
     * @return list<string>
     */
    public static function allowedNext(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function create(
        PDO $pdo,
        array $actor,
        string $targetType,
        string $targetId,
        string $title,
        string $body,
        ?string $imagePath = null,
    ): string {
        if (!self::canCreate($actor)) {
            throw new InvalidArgumentException('수리 요청 권한이 없습니다.');
        }

        $targetType = trim($targetType);
        if (!in_array($targetType, ['asset', 'room'], true)) {
            throw new InvalidArgumentException('신고 대상을 확인하세요.');
        }
        $targetId = trim($targetId);
        if ($targetId === '') {
            throw new InvalidArgumentException('신고 대상을 확인하세요.');
        }
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('제목과 증상을 입력하세요.');
        }
        if (mb_strlen($title) > 200) {
            throw new InvalidArgumentException('제목이 너무 깁니다.');
        }
        $imagePath = self::nullableTrim($imagePath);

        $id = '';
        self::beginImmediate($pdo);
        try {
            if ($targetType === 'asset') {
                $assetStmt = $pdo->prepare('SELECT id, name, status FROM assets WHERE id = ?');
                $assetStmt->execute([$targetId]);
                $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
                if (!$asset) {
                    throw new InvalidArgumentException('신고 대상을 확인하세요.');
                }
            } else {
                $locStmt = $pdo->prepare('SELECT id, name FROM locations WHERE id = ?');
                $locStmt->execute([$targetId]);
                if (!$locStmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new InvalidArgumentException('신고 대상을 확인하세요.');
                }
            }

            $id = Support::id('rep');
            $t = Support::now();
            $pdo->prepare(
                'INSERT INTO reports(id,target_type,target_id,reporter_user_id,reporter_name,title,body,image_path,status,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id,
                $targetType,
                $targetId,
                $actor['id'] ?? null,
                (string) ($actor['display_name'] ?? '사용자'),
                $title,
                $body,
                $imagePath,
                self::STATUS_OPEN,
                $t,
                $t,
            ]);

            self::syncAssetStatus($pdo, $targetType, $targetId, $t);

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'report',
                'report',
                $id,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "수리 요청 접수: {$title}",
                json_encode([
                    'from' => null,
                    'to' => self::STATUS_OPEN,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                ], JSON_UNESCAPED_UNICODE),
                $t,
            ]);

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }

        return $id;
    }

    public static function transition(
        PDO $pdo,
        array $actor,
        string $reportId,
        string $toStatus,
        ?string $note = null,
    ): void {
        if (!self::canTransition($actor)) {
            throw new InvalidArgumentException('수리 상태를 바꿀 권한이 없습니다.');
        }

        $reportId = trim($reportId);
        $toStatus = trim($toStatus);
        if ($reportId === '' || !self::isValidStatus($toStatus)) {
            throw new InvalidArgumentException('바꿀 상태를 확인하세요.');
        }
        $note = self::nullableTrim($note);
        if ($note !== null && mb_strlen($note) > 500) {
            throw new InvalidArgumentException('처리 메모가 너무 깁니다.');
        }

        self::beginImmediate($pdo);
        try {
            $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ?');
            $stmt->execute([$reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                throw new InvalidArgumentException('수리 요청을 찾을 수 없습니다.');
            }

            $from = (string) ($report['status'] ?? '');
            $allowed = self::allowedNext($from);
            if (!in_array($toStatus, $allowed, true)) {
                throw new InvalidArgumentException('그 상태로 바꿀 수 없습니다.');
            }

            $t = Support::now();
            $update = $pdo->prepare(
                'UPDATE reports SET status = ?, updated_at = ? WHERE id = ? AND status = ?'
            );
            $update->execute([$toStatus, $t, $reportId, $from]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('이미 다른 상태로 바뀌었거나 처리할 수 없습니다.');
            }

            self::syncAssetStatus($pdo, (string) $report['target_type'], (string) $report['target_id'], $t);

            $fromLabel = Support::statusLabel($from);
            $toLabel = Support::statusLabel($toStatus);
            $summary = "수리 상태 {$fromLabel} → {$toLabel}";
            if ($note !== null) {
                $summary .= ": {$note}";
            }

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'report_status',
                'report',
                $reportId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                $summary,
                json_encode([
                    'from' => $from,
                    'to' => $toStatus,
                    'note' => $note,
                ], JSON_UNESCAPED_UNICODE),
                $t,
            ]);

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(PDO $pdo, string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT r.*,
                    CASE r.target_type
                      WHEN 'asset' THEN a.name
                      WHEN 'room' THEN l.name
                    END AS target_name,
                    a.management_number,
                    a.status AS asset_status
             FROM reports r
             LEFT JOIN assets a ON r.target_type = 'asset' AND a.id = r.target_id
             LEFT JOIN locations l ON r.target_type = 'room' AND l.id = r.target_id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function queue(PDO $pdo, string $status = 'queue'): array
    {
        $where = "r.status IN ('open','in_progress')";
        $params = [];
        if ($status === 'all') {
            $where = '1=1';
        } elseif (self::isValidStatus($status)) {
            $where = 'r.status = ?';
            $params[] = $status;
        }

        $sql = "SELECT r.*,
                       CASE r.target_type
                         WHEN 'asset' THEN a.name
                         WHEN 'room' THEN l.name
                       END AS target_name,
                       a.management_number,
                       a.status AS asset_status
                FROM reports r
                LEFT JOIN assets a ON r.target_type = 'asset' AND a.id = r.target_id
                LEFT JOIN locations l ON r.target_type = 'room' AND l.id = r.target_id
                WHERE {$where}
                ORDER BY CASE r.status
                           WHEN 'open' THEN 0
                           WHEN 'in_progress' THEN 1
                           ELSE 2
                         END,
                         r.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(PDO $pdo, string $reportId): array
    {
        $reportId = trim($reportId);
        if ($reportId === '') {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM activity_logs
             WHERE entity_type = 'report' AND entity_id = ?
             ORDER BY created_at ASC, rowid ASC"
        );
        $stmt->execute([$reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function hasOpenForAsset(PDO $pdo, string $assetId): bool
    {
        $assetId = trim($assetId);
        if ($assetId === '') {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reports
             WHERE target_type = 'asset' AND target_id = ?
               AND status IN ('open','in_progress')"
        );
        $stmt->execute([$assetId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function openCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM reports WHERE status IN ('open','in_progress')"
        )->fetchColumn();
    }

    /**
     * Link `assets.status` only when it does not fight the loan/lost/retired machine:
     * available ↔ repair. on_loan / moving / lost / retired stay untouched.
     */
    private static function syncAssetStatus(PDO $pdo, string $targetType, string $targetId, string $now): void
    {
        if ($targetType !== 'asset' || $targetId === '') {
            return;
        }
        if (self::hasOpenForAsset($pdo, $targetId)) {
            $pdo->prepare(
                "UPDATE assets SET status = 'repair', updated_at = ?
                 WHERE id = ? AND status = 'available'"
            )->execute([$now, $targetId]);
            return;
        }
        $pdo->prepare(
            "UPDATE assets SET status = 'available', updated_at = ?
             WHERE id = ? AND status = 'repair'"
        )->execute([$now, $targetId]);
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function beginImmediate(PDO $pdo): void
    {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('BEGIN IMMEDIATE');
    }

    private static function commitImmediate(PDO $pdo): void
    {
        $pdo->exec('COMMIT');
    }

    private static function rollBackImmediate(PDO $pdo): void
    {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Transaction may already be closed after a constraint abort.
        }
    }
}
