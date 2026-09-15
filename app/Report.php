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
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_REJECTED,
    ];

    /** Open repair work — 접수 + 수리중. */
    /** @var list<string> */
    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS];

    /**
     * @return list<string>
     */
    public static function nextStatuses(string $from): array
    {
        return match ($from) {
            self::STATUS_OPEN => [self::STATUS_IN_PROGRESS, self::STATUS_REJECTED],
            self::STATUS_IN_PROGRESS => [self::STATUS_DONE, self::STATUS_REJECTED],
            default => [],
        };
    }

    public static function actionLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_IN_PROGRESS => '수리 시작',
            self::STATUS_DONE => '수리 완료',
            self::STATUS_REJECTED => '수리 불가',
            default => Support::statusLabel($status),
        };
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: ?string}
     */
    public static function filterFromRequest(array $query): array
    {
        $status = trim((string) ($query['status'] ?? ''));
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            $status = '';
        }
        return ['status' => $status !== '' ? $status : null];
    }

    /**
     * Teacher (canLoan) files a repair request. Sets the asset to `repair`
     * when it is sitting idle (`available` / `moving`). On-loan / lost / retired stay put.
     */
    public static function file(
        PDO $pdo,
        array $actor,
        string $assetId,
        string $symptom,
        ?string $title = null,
        ?string $imagePath = null,
    ): string {
        if (!Auth::canLoan($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('수리 요청 권한이 없습니다.');
        }

        $assetId = trim($assetId);
        $symptom = trim($symptom);
        $title = self::nullableTrim($title);
        $imagePath = self::nullableTrim($imagePath);
        if ($assetId === '') {
            throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
        }
        if ($symptom === '') {
            throw new InvalidArgumentException('증상을 입력하세요.');
        }
        if ($title === null) {
            $title = self::titleFromSymptom($symptom);
        }

        $reportId = '';
        self::beginImmediate($pdo);
        try {
            $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
            $stmt->execute([$assetId]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$asset) {
                throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
            }
            $assetStatus = (string) ($asset['status'] ?? '');
            if (in_array($assetStatus, ['lost', 'retired'], true)) {
                throw new InvalidArgumentException('폐기·분실 장비는 수리 요청할 수 없습니다.');
            }

            $t = Support::now();
            $reportId = Support::id('rep');
            $pdo->prepare(
                'INSERT INTO reports(id,target_type,target_id,reporter_user_id,reporter_name,title,body,image_path,status,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $reportId,
                'asset',
                $assetId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '알 수 없음',
                $title,
                $symptom,
                $imagePath,
                self::STATUS_OPEN,
                $t,
                $t,
            ]);

            $marked = self::markAssetRepair($pdo, (string) $asset['id'], $t);
            $summary = "«{$asset['name']}» 수리 요청: {$title}";
            self::writeLog($pdo, $actor, 'report', 'report', $reportId, $summary, [
                'asset_id' => $assetId,
                'asset_status' => $marked ? 'repair' : $assetStatus,
            ], $t);
            if ($marked) {
                self::writeLog($pdo, $actor, 'report', 'asset', $assetId, $summary . ' · 상태를 수리중으로 변경', [
                    'report_id' => $reportId,
                    'from' => $assetStatus,
                    'to' => 'repair',
                ], $t);
            }

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }

        return $reportId;
    }

    /**
     * Manager/owner advances 접수 → 수리중 → 완료, or 불가 from 접수/수리중.
     */
    public static function transition(
        PDO $pdo,
        array $actor,
        string $reportId,
        string $to,
        ?string $note = null,
    ): void {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('수리 상태 변경 권한이 없습니다.');
        }

        $reportId = trim($reportId);
        $to = trim($to);
        $note = self::nullableTrim($note);
        if ($reportId === '' || !in_array($to, self::STATUSES, true)) {
            throw new InvalidArgumentException('바꿀 수리 상태를 확인하세요.');
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
            if (!in_array($to, self::nextStatuses($from), true)) {
                throw new InvalidArgumentException('그 상태로 바꿀 수 없습니다.');
            }

            $t = Support::now();
            $upd = $pdo->prepare(
                'UPDATE reports SET status = ?, updated_at = ? WHERE id = ? AND status = ?'
            );
            $upd->execute([$to, $t, $reportId, $from]);
            if ($upd->rowCount() !== 1) {
                throw new InvalidArgumentException('이미 처리되었거나 바꿀 수 없는 상태입니다.');
            }

            $asset = null;
            $assetId = (string) ($report['target_id'] ?? '');
            if (($report['target_type'] ?? '') === 'asset' && $assetId !== '') {
                $as = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
                $as->execute([$assetId]);
                $asset = $as->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $assetChanged = null;
            if (is_array($asset)) {
                if (in_array($to, [self::STATUS_IN_PROGRESS, self::STATUS_OPEN], true)) {
                    if (self::markAssetRepair($pdo, $assetId, $t)) {
                        $assetChanged = 'repair';
                    }
                } elseif ($to === self::STATUS_DONE) {
                    if (self::restoreAssetIfIdle($pdo, $assetId, $reportId, $t)) {
                        $assetChanged = 'available';
                    }
                }
            }

            $fromLabel = Support::statusLabel($from);
            $toLabel = Support::statusLabel($to);
            $summary = "수리 상태: {$fromLabel} → {$toLabel}";
            if ($note !== null) {
                $summary .= " · {$note}";
            }
            $name = is_array($asset) ? (string) $asset['name'] : '';
            if ($name !== '') {
                $summary = "«{$name}» {$summary}";
            }

            self::writeLog($pdo, $actor, 'report', 'report', $reportId, $summary, [
                'from' => $from,
                'to' => $to,
                'asset_id' => $assetId !== '' ? $assetId : null,
                'note' => $note,
            ], $t);
            if (is_array($asset)) {
                $assetSummary = $summary;
                if ($assetChanged === 'repair') {
                    $assetSummary .= ' · 상태를 수리중으로 변경';
                } elseif ($assetChanged === 'available') {
                    $assetSummary .= ' · 보관중으로 복귀';
                }
                self::writeLog($pdo, $actor, 'report', 'asset', $assetId, $assetSummary, [
                    'report_id' => $reportId,
                    'from' => $from,
                    'to' => $to,
                    'asset_status' => $assetChanged,
                ], $t);
            }

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
    }

    /**
     * Manager queue. Default (no status filter) is open work: 접수 + 수리중.
     *
     * @param array{status?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public static function queue(PDO $pdo, array $filters = []): array
    {
        $status = isset($filters['status']) && is_string($filters['status']) ? $filters['status'] : null;
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            $status = null;
        }

        $sql = "SELECT r.*,
                       a.name AS asset_name,
                       a.management_number,
                       a.status AS asset_status
                FROM reports r
                LEFT JOIN assets a ON r.target_type = 'asset' AND a.id = r.target_id";
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE r.status = ?';
            $params[] = $status;
        } else {
            $sql .= " WHERE r.status IN ('open','in_progress')";
        }
        $sql .= ' ORDER BY CASE r.status WHEN \'open\' THEN 0 WHEN \'in_progress\' THEN 1 ELSE 2 END, r.created_at ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function openCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM reports WHERE status IN ('open','in_progress')"
        )->fetchColumn();
    }

    /**
     * @return array{open: int, in_progress: int, done: int, rejected: int, active: int}
     */
    public static function counts(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT status, COUNT(*) AS n FROM reports GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
        $out = [
            'open' => 0,
            'in_progress' => 0,
            'done' => 0,
            'rejected' => 0,
            'active' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($out[$status])) {
                $out[$status] = (int) $row['n'];
            }
        }
        $out['active'] = $out['open'] + $out['in_progress'];
        return $out;
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
                    a.name AS asset_name,
                    a.management_number,
                    a.status AS asset_status,
                    a.location_id
             FROM reports r
             LEFT JOIN assets a ON r.target_type = 'asset' AND a.id = r.target_id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function logs(PDO $pdo, string $reportId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM activity_logs WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute(['report', $reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function titleFromSymptom(string $symptom): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $symptom));
        if (function_exists('mb_substr')) {
            $cut = mb_substr($line, 0, 60);
            return mb_strlen($line) > 60 ? $cut . '…' : $cut;
        }
        $cut = substr($line, 0, 60);
        return strlen($line) > 60 ? $cut . '…' : $cut;
    }

    private static function markAssetRepair(PDO $pdo, string $assetId, string $now): bool
    {
        $upd = $pdo->prepare(
            "UPDATE assets SET status = 'repair', updated_at = ?
             WHERE id = ? AND status IN ('available','moving')"
        );
        $upd->execute([$now, $assetId]);
        return $upd->rowCount() === 1;
    }

    private static function restoreAssetIfIdle(PDO $pdo, string $assetId, string $exceptReportId, string $now): bool
    {
        $open = $pdo->prepare(
            "SELECT COUNT(*) FROM reports
             WHERE target_type = 'asset' AND target_id = ?
               AND status IN ('open','in_progress') AND id != ?"
        );
        $open->execute([$assetId, $exceptReportId]);
        if ((int) $open->fetchColumn() > 0) {
            return false;
        }
        $upd = $pdo->prepare(
            "UPDATE assets SET status = 'available', updated_at = ?
             WHERE id = ? AND status = 'repair'"
        );
        $upd->execute([$now, $assetId]);
        return $upd->rowCount() === 1;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private static function writeLog(
        PDO $pdo,
        array $actor,
        string $action,
        string $entityType,
        string $entityId,
        string $summary,
        ?array $meta,
        string $at,
    ): void {
        $pdo->prepare(
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
            $at,
        ]);
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
