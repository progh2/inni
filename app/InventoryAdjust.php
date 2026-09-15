<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Ledger correction after a light inventory check.
 * Confirm only stamps the line; this writes qty / location / status.
 */
final class InventoryAdjust
{
    public const REASON_MAX = 200;

    /** @var list<string> */
    public const ASSET_STATUSES = ['available', 'repair', 'moving', 'lost'];

    /**
     * Active owner/manager users who may be recorded as 승인자.
     *
     * @return list<array<string, mixed>>
     */
    public static function approvers(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT id, display_name, role FROM users
             WHERE status = 'active' AND role IN ('owner','manager')
             ORDER BY display_name, id"
        );
        return $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function adjust(
        PDO $pdo,
        array $actor,
        string $lineId,
        mixed $reason,
        mixed $approverId,
        mixed $locationId = null,
        mixed $status = null,
        mixed $quantity = null,
    ): array {
        self::requireWriter($actor);
        $lineId = trim($lineId);
        if ($lineId === '') {
            throw new InvalidArgumentException('보정할 실사 항목이 없습니다.');
        }
        $reason = self::parseReason($reason);
        $approver = self::resolveApprover($pdo, $approverId);

        self::beginImmediate($pdo);
        try {
            $lineStmt = $pdo->prepare(
                'SELECT l.*, c.status AS check_status, c.location_name AS check_location_name
                 FROM inventory_check_lines l
                 JOIN inventory_checks c ON c.id = l.check_id
                 WHERE l.id = ?'
            );
            $lineStmt->execute([$lineId]);
            $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
            if (!$line) {
                throw new InvalidArgumentException('보정할 실사 항목이 없습니다.');
            }
            if (($line['check_status'] ?? '') !== 'done') {
                throw new InvalidArgumentException('종료된 실사만 보정할 수 있습니다.');
            }

            $kind = (string) ($line['kind'] ?? '');
            $t = Support::now();
            if ($kind === 'asset') {
                $change = self::adjustAsset($pdo, $line, $locationId, $status, $t);
            } elseif ($kind === 'item') {
                $change = self::adjustItem($pdo, $line, $quantity, $t);
            } else {
                throw new InvalidArgumentException('보정할 수 없는 항목입니다.');
            }

            $mark = $pdo->prepare(
                'UPDATE inventory_check_lines
                 SET adjusted_at = ?, adjusted_by = ?, adjust_reason = ?, adjust_approver = ?
                 WHERE id = ? AND check_id = ?'
            );
            $mark->execute([
                $t,
                $actor['id'] ?? null,
                $reason,
                $approver['display_name'],
                $lineId,
                $line['check_id'],
            ]);
            if ($mark->rowCount() !== 1) {
                throw new InvalidArgumentException('보정할 실사 항목이 없습니다.');
            }

            $entityType = $kind === 'asset' ? 'asset' : 'catalog';
            $entityId = $kind === 'asset'
                ? (string) $line['asset_id']
                : (string) $line['catalog_item_id'];
            $meta = [
                'check_id' => $line['check_id'],
                'line_id' => $lineId,
                'reason' => $reason,
                'approver_id' => $approver['id'],
                'approver_name' => $approver['display_name'],
                'before' => $change['before'],
                'after' => $change['after'],
            ];
            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'inventory_adjust',
                $entityType,
                $entityId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "실사 보정 «{$line['name']}» · {$reason} · 승인자 {$approver['display_name']}",
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);

            self::commitImmediate($pdo);
            $itemId = $kind === 'item' ? (string) $line['catalog_item_id'] : '';
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }

        if ($itemId !== '') {
            Alert::notifyLowStock($pdo, $itemId);
        }

        $fresh = $pdo->prepare('SELECT * FROM inventory_check_lines WHERE id = ?');
        $fresh->execute([$lineId]);
        $out = $fresh->fetch(PDO::FETCH_ASSOC);
        return is_array($out) ? $out : $line;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function requireWriter(array $actor): void
    {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('보정 권한이 없습니다.');
        }
        $id = $actor['id'] ?? null;
        $name = $actor['display_name'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($name) || $name === '') {
            throw new InvalidArgumentException('보정 권한이 없습니다.');
        }
    }

    public static function parseReason(mixed $reason): string
    {
        if ($reason === null || (!is_string($reason) && !is_int($reason) && !is_float($reason))) {
            throw new InvalidArgumentException('보정 사유를 입력하세요.');
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new InvalidArgumentException('보정 사유를 입력하세요.');
        }
        if (str_contains($reason, "\n") || str_contains($reason, "\r")) {
            throw new InvalidArgumentException('보정 사유는 한 줄로 입력하세요.');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException('보정 사유는 200자 이내로 입력하세요.');
        }
        return $reason;
    }

    /**
     * @return array{id: string, display_name: string, role: string}
     */
    public static function resolveApprover(PDO $pdo, mixed $approverId): array
    {
        if (!is_string($approverId) && !is_int($approverId)) {
            throw new InvalidArgumentException('승인자를 선택하세요.');
        }
        $approverId = trim((string) $approverId);
        if ($approverId === '') {
            throw new InvalidArgumentException('승인자를 선택하세요.');
        }
        $stmt = $pdo->prepare('SELECT id, display_name, role, status FROM users WHERE id = ?');
        $stmt->execute([$approverId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !Auth::canWrite($user)) {
            throw new InvalidArgumentException('승인자는 담당교사 또는 관리자여야 합니다.');
        }
        return [
            'id' => (string) $user['id'],
            'display_name' => (string) $user['display_name'],
            'role' => (string) $user['role'],
        ];
    }

    /**
     * Non-negative quantity (0 allowed: 실물 없음).
     */
    public static function parseQuantity(mixed $quantity): float
    {
        if ((!is_string($quantity) && !is_int($quantity) && !is_float($quantity)) || !is_numeric($quantity)) {
            throw new InvalidArgumentException('보정 수량은 0 이상의 숫자로 입력하세요.');
        }
        $quantity = (float) $quantity;
        if (!is_finite($quantity) || $quantity < 0) {
            throw new InvalidArgumentException('보정 수량은 0 이상의 숫자로 입력하세요.');
        }
        return $quantity;
    }

    /**
     * @param array<string, mixed> $line
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private static function adjustAsset(
        PDO $pdo,
        array $line,
        mixed $locationId,
        mixed $status,
        string $t,
    ): array {
        $assetId = trim((string) ($line['asset_id'] ?? ''));
        if ($assetId === '') {
            throw new InvalidArgumentException('보정할 장비가 없습니다.');
        }
        $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$asset) {
            throw new InvalidArgumentException('보정할 장비가 없습니다.');
        }
        $currentStatus = (string) ($asset['status'] ?? '');
        if ($currentStatus === 'retired') {
            throw new InvalidArgumentException('이미 폐기된 장비는 보정할 수 없습니다.');
        }
        if ($currentStatus === 'on_loan') {
            throw new InvalidArgumentException('대여 중인 장비는 반납 후 보정하세요.');
        }

        $nextLocation = self::parseOptionalLocation($pdo, $locationId);
        $nextStatus = self::parseOptionalAssetStatus($status);
        if ($nextLocation === null && $nextStatus === null) {
            throw new InvalidArgumentException('위치나 상태를 바꿔 주세요.');
        }

        $newLocation = $nextLocation ?? (string) $asset['location_id'];
        $newStatus = $nextStatus ?? $currentStatus;
        if ($newLocation === (string) $asset['location_id'] && $newStatus === $currentStatus) {
            throw new InvalidArgumentException('장부 값과 같습니다. 바꿀 위치나 상태를 입력하세요.');
        }

        $upd = $pdo->prepare(
            "UPDATE assets SET location_id = ?, status = ?, updated_at = ?
             WHERE id = ? AND status = ? AND location_id = ?
               AND status NOT IN ('retired','on_loan')"
        );
        $upd->execute([
            $newLocation,
            $newStatus,
            $t,
            $assetId,
            $currentStatus,
            $asset['location_id'],
        ]);
        if ($upd->rowCount() !== 1) {
            throw new InvalidArgumentException('장비가 바뀌어 보정하지 못했습니다. 다시 확인하세요.');
        }

        return [
            'before' => [
                'location_id' => $asset['location_id'],
                'status' => $currentStatus,
            ],
            'after' => [
                'location_id' => $newLocation,
                'status' => $newStatus,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private static function adjustItem(PDO $pdo, array $line, mixed $quantity, string $t): array
    {
        $lotId = trim((string) ($line['stock_lot_id'] ?? ''));
        $itemId = trim((string) ($line['catalog_item_id'] ?? ''));
        if ($lotId === '' || $itemId === '') {
            throw new InvalidArgumentException('보정할 재고가 없습니다.');
        }
        $qty = self::parseQuantity($quantity);

        $lotStmt = $pdo->prepare(
            'SELECT id, catalog_item_id, quantity FROM stock_lots WHERE id = ? AND catalog_item_id = ?'
        );
        $lotStmt->execute([$lotId, $itemId]);
        $lot = $lotStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lot) {
            throw new InvalidArgumentException('보정할 재고가 없습니다.');
        }
        $before = (float) $lot['quantity'];
        if (abs($before - $qty) < 0.0000001) {
            throw new InvalidArgumentException('장부 수량과 같습니다. 바꿀 수량을 입력하세요.');
        }

        $upd = $pdo->prepare(
            'UPDATE stock_lots SET quantity = CAST(? AS REAL), updated_at = ?
             WHERE id = ? AND catalog_item_id = ?'
        );
        $upd->execute([$qty, $t, $lotId, $itemId]);
        if ($upd->rowCount() !== 1) {
            throw new InvalidArgumentException('재고가 바뀌어 보정하지 못했습니다. 다시 확인하세요.');
        }

        return [
            'before' => ['quantity' => $before, 'lot_id' => $lotId],
            'after' => ['quantity' => $qty, 'lot_id' => $lotId],
        ];
    }

    private static function parseOptionalLocation(PDO $pdo, mixed $locationId): ?string
    {
        if ($locationId === null || $locationId === '') {
            return null;
        }
        if (!is_string($locationId) && !is_int($locationId)) {
            throw new InvalidArgumentException('보정할 위치를 확인하세요.');
        }
        $locationId = trim((string) $locationId);
        if ($locationId === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('보정할 위치를 확인하세요.');
        }
        return $locationId;
    }

    private static function parseOptionalAssetStatus(mixed $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }
        if (!is_string($status)) {
            throw new InvalidArgumentException('보정할 상태를 확인하세요.');
        }
        $status = trim($status);
        if ($status === '') {
            return null;
        }
        if (!in_array($status, self::ASSET_STATUSES, true)) {
            throw new InvalidArgumentException('그 상태로는 보정할 수 없습니다.');
        }
        return $status;
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
