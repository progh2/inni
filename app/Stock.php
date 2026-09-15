<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class Stock
{
    private const STOCKABLE_TYPES = ['fixture', 'consumable', 'part'];

    public const CLASS_MEMO_MAX = 200;

    /**
     * Usage issue (분출). Room is optional in the engine so existing quantity
     * tests stay valid; the item form requires a room.
     */
    public static function issue(
        PDO $pdo,
        array $actor,
        string $itemId,
        string $lotId,
        mixed $quantity,
        string $purpose,
        mixed $roomId = null,
        mixed $classMemo = '',
    ): void {
        if (!Auth::canLoan($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('출고 권한이 없습니다.');
        }
        $quantity = self::parsePositiveQuantity($quantity, '출고 수량은 0보다 큰 숫자로 입력하세요.');
        $purpose = trim($purpose);
        if ($purpose === '') {
            throw new InvalidArgumentException('사용 사유를 입력하세요.');
        }
        $room = self::resolveIssueRoom($pdo, $roomId);
        $classMemo = self::parseClassMemo($classMemo);

        self::beginImmediate($pdo);
        try {
            // The quantity check and subtraction happen in one write, including concurrent requests.
            $update = $pdo->prepare(
                "UPDATE stock_lots SET quantity = quantity - CAST(? AS REAL), updated_at = ?
                 WHERE id = ? AND catalog_item_id = ? AND quantity >= CAST(? AS REAL)
                 AND quantity - CAST(? AS REAL) < quantity
                 AND catalog_item_id IN (SELECT id FROM catalog_items WHERE type IN ('consumable','part'))"
            );
            $update->execute([$quantity, Support::now(), $lotId, $itemId, $quantity, $quantity]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('출고할 재고를 확인하세요. 재고가 부족하거나 사용 출고할 수 없는 품목입니다.');
            }
            $stmt = $pdo->prepare(
                'SELECT s.location_id, s.quantity, c.name, c.unit, l.name AS location_name
                 FROM stock_lots s JOIN catalog_items c ON c.id = s.catalog_item_id
                 JOIN locations l ON l.id = s.location_id WHERE s.id = ?'
            );
            $stmt->execute([$lotId]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);
            $meta = [
                'lot_id' => $lotId, 'location_id' => $lot['location_id'],
                'quantity' => $quantity, 'remaining_quantity' => (float) $lot['quantity'], 'purpose' => $purpose,
            ];
            $destName = (string) $lot['location_name'];
            if ($room !== null) {
                $meta['room_id'] = $room['id'];
                $meta['room_name'] = $room['name'];
                $destName = $room['name'];
            }
            if ($classMemo !== '') {
                $meta['class_memo'] = $classMemo;
            }
            $memoPart = $classMemo !== '' ? " · {$classMemo}" : '';
            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'), 'issue', 'catalog', $itemId, $actor['id'], $actor['display_name'],
                "«{$lot['name']}» {$quantity} {$lot['unit']} 분출 · {$destName} · {$purpose}{$memoPart}",
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Support::now(),
            ]);
            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
        Alert::notifyLowStock($pdo, $itemId);
    }

    public static function parseClassMemo(mixed $memo): string
    {
        if ($memo === null || $memo === '') {
            return '';
        }
        if (!is_string($memo)) {
            throw new InvalidArgumentException('수업 메모를 확인하세요.');
        }
        $memo = trim($memo);
        if ($memo === '') {
            return '';
        }
        if (str_contains($memo, "\n") || str_contains($memo, "\r")) {
            throw new InvalidArgumentException('수업 메모는 한 줄로 입력하세요.');
        }
        if (mb_strlen($memo) > self::CLASS_MEMO_MAX) {
            throw new InvalidArgumentException('수업 메모는 200자 이내로 입력하세요.');
        }
        return $memo;
    }

    /**
     * GET `room` (품목 상세 이력) or `issue_room` (재료 보드 이력).
     */
    public static function issueRoomFromRequest(array $query, string $key = 'room'): ?string
    {
        $room = trim((string) ($query[$key] ?? ''));
        return $room !== '' ? $room : null;
    }

    /**
     * Latest catalog activity for one item. Room filter keeps issue/cancel_issue only.
     *
     * @return list<array<string, mixed>>
     */
    public static function itemLogs(PDO $pdo, string $itemId, ?string $roomId = null, int $limit = 20): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM activity_logs
             WHERE entity_type = 'catalog' AND entity_id = ?
             ORDER BY created_at DESC, rowid DESC
             LIMIT 200"
        );
        $stmt->execute([$itemId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($roomId === null || $roomId === '') {
            return array_slice($rows, 0, $limit);
        }
        $issueLike = [];
        foreach ($rows as $row) {
            if (in_array((string) ($row['action'] ?? ''), ['issue', 'cancel_issue'], true)) {
                $issueLike[] = $row;
            }
        }
        return self::filterLogsByRoom($pdo, $issueLike, $roomId, $limit);
    }

    /**
     * Recent material issues across items. Board links to item issue/restock; writes stay there.
     *
     * @return list<array<string, mixed>>
     */
    public static function issueHistory(PDO $pdo, ?string $roomId = null, int $limit = 20): array
    {
        $stmt = $pdo->query(
            "SELECT * FROM activity_logs
             WHERE action IN ('issue','cancel_issue') AND entity_type = 'catalog'
             ORDER BY created_at DESC, rowid DESC
             LIMIT 200"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return self::filterLogsByRoom($pdo, $rows, $roomId, $limit);
    }

    /**
     * @param array{lot_code?: mixed, expires_at?: mixed}|null $lotAttrs
     */
    public static function restock(
        PDO $pdo,
        array $actor,
        string $itemId,
        string $locationId,
        mixed $quantity,
        string $note = '',
        ?array $lotAttrs = null,
    ): void {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('재입고 권한이 없습니다.');
        }
        $itemId = trim($itemId);
        $locationId = trim($locationId);
        if ($itemId === '' || $locationId === '') {
            throw new InvalidArgumentException('입고할 품목과 위치를 확인하세요.');
        }
        $quantity = self::parsePositiveQuantity($quantity, '입고 수량은 0보다 큰 숫자로 입력하세요.');
        $note = trim($note);
        $applyLot = $lotAttrs !== null;
        $lotCode = $applyLot ? StockLot::parseLotCode($lotAttrs['lot_code'] ?? null) : null;
        $expiresAt = $applyLot ? StockLot::parseExpiresAt($lotAttrs['expires_at'] ?? null) : null;

        self::beginImmediate($pdo);
        try {
            $itemStmt = $pdo->prepare('SELECT id, name, type, unit FROM catalog_items WHERE id = ?');
            $itemStmt->execute([$itemId]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!$item || !in_array((string) $item['type'], self::STOCKABLE_TYPES, true)) {
                throw new InvalidArgumentException('재입고할 수 없는 품목입니다.');
            }

            $locStmt = $pdo->prepare('SELECT id, name FROM locations WHERE id = ?');
            $locStmt->execute([$locationId]);
            $location = $locStmt->fetch(PDO::FETCH_ASSOC);
            if (!$location) {
                throw new InvalidArgumentException('입고할 위치를 확인하세요.');
            }

            $t = Support::now();
            $lotStmt = $pdo->prepare(
                'SELECT id, quantity FROM stock_lots WHERE catalog_item_id = ? AND location_id = ?'
            );
            $lotStmt->execute([$itemId, $locationId]);
            $lot = $lotStmt->fetch(PDO::FETCH_ASSOC);
            if ($lot) {
                $lotId = (string) $lot['id'];
                if ($applyLot) {
                    $add = $pdo->prepare(
                        'UPDATE stock_lots SET quantity = quantity + CAST(? AS REAL),
                         lot_code = ?, expires_at = ?, updated_at = ?
                         WHERE id = ? AND catalog_item_id = ?
                         AND quantity + CAST(? AS REAL) > quantity'
                    );
                    $add->execute([$quantity, $lotCode, $expiresAt, $t, $lotId, $itemId, $quantity]);
                } else {
                    $add = $pdo->prepare(
                        'UPDATE stock_lots SET quantity = quantity + CAST(? AS REAL), updated_at = ?
                         WHERE id = ? AND catalog_item_id = ?
                         AND quantity + CAST(? AS REAL) > quantity'
                    );
                    $add->execute([$quantity, $t, $lotId, $itemId, $quantity]);
                }
                if ($add->rowCount() !== 1) {
                    throw new InvalidArgumentException('입고할 재고를 확인하세요.');
                }
            } else {
                $lotId = Support::id('lot');
                try {
                    $pdo->prepare(
                        'INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,lot_code,expires_at,updated_at)
                         VALUES(?,?,?,?,?,?,?)'
                    )->execute([$lotId, $itemId, $locationId, $quantity, $lotCode, $expiresAt, $t]);
                } catch (PDOException $e) {
                    if (self::isUniqueConflict($e)) {
                        throw new InvalidArgumentException('같은 위치에 대한 입고가 겹쳤습니다. 다시 시도하세요.', 0, $e);
                    }
                    throw $e;
                }
            }

            $remainStmt = $pdo->prepare('SELECT quantity FROM stock_lots WHERE id = ?');
            $remainStmt->execute([$lotId]);
            $remaining = (float) $remainStmt->fetchColumn();
            $meta = [
                'lot_id' => $lotId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'remaining_quantity' => $remaining,
                'note' => $note,
            ];
            if ($applyLot) {
                $meta['lot_code'] = $lotCode;
                $meta['expires_at'] = $expiresAt;
            }
            $notePart = $note !== '' ? " · {$note}" : '';
            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'restock',
                'catalog',
                $itemId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "«{$item['name']}» {$quantity} {$item['unit']} 재입고 · {$location['name']}{$notePart}",
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
        Alert::notifyLowStock($pdo, $itemId);
    }

    public static function updateLot(
        PDO $pdo,
        array $actor,
        string $itemId,
        string $lotId,
        mixed $lotCode,
        mixed $expiresAt,
    ): void {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('로트 수정 권한이 없습니다.');
        }
        $itemId = trim($itemId);
        $lotId = trim($lotId);
        if ($itemId === '' || $lotId === '') {
            throw new InvalidArgumentException('수정할 로트를 확인하세요.');
        }
        $lotCode = StockLot::parseLotCode($lotCode);
        $expiresAt = StockLot::parseExpiresAt($expiresAt);

        self::beginImmediate($pdo);
        try {
            $stmt = $pdo->prepare(
                'SELECT s.id, s.lot_code, s.expires_at, c.name, c.type
                 FROM stock_lots s JOIN catalog_items c ON c.id = s.catalog_item_id
                 WHERE s.id = ? AND s.catalog_item_id = ?'
            );
            $stmt->execute([$lotId, $itemId]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lot || !in_array((string) $lot['type'], self::STOCKABLE_TYPES, true)) {
                throw new InvalidArgumentException('수정할 로트를 확인하세요.');
            }

            $t = Support::now();
            $pdo->prepare(
                'UPDATE stock_lots SET lot_code = ?, expires_at = ?, updated_at = ?
                 WHERE id = ? AND catalog_item_id = ?'
            )->execute([$lotCode, $expiresAt, $t, $lotId, $itemId]);

            $meta = [
                'lot_id' => $lotId,
                'before' => [
                    'lot_code' => $lot['lot_code'] ?? null,
                    'expires_at' => $lot['expires_at'] ?? null,
                ],
                'after' => [
                    'lot_code' => $lotCode,
                    'expires_at' => $expiresAt,
                ],
            ];
            $label = StockLot::format($lotCode, $expiresAt);
            $summary = $label !== ''
                ? "«{$lot['name']}» 로트 수정 · {$label}"
                : "«{$lot['name']}» 로트 정보 비움";
            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'update_lot',
                'catalog',
                $itemId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                $summary,
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
    }

    public static function cancelIssue(PDO $pdo, array $actor, string $itemId, string $issueLogId, string $reason): void
    {
        if (!Auth::canLoan($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('출고 취소 권한이 없습니다.');
        }
        $itemId = trim($itemId);
        $issueLogId = trim($issueLogId);
        $reason = trim($reason);
        if ($itemId === '' || $issueLogId === '') {
            throw new InvalidArgumentException('취소할 출고를 확인하세요.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('취소 사유를 입력하세요.');
        }

        self::beginImmediate($pdo);
        try {
            $logStmt = $pdo->prepare(
                "SELECT * FROM activity_logs WHERE id = ? AND action = 'issue' AND entity_type = 'catalog'"
            );
            $logStmt->execute([$issueLogId]);
            $log = $logStmt->fetch(PDO::FETCH_ASSOC);
            if (!$log || (string) $log['entity_id'] !== $itemId) {
                throw new InvalidArgumentException('취소할 출고를 확인하세요.');
            }

            $meta = Support::jsonDecode(isset($log['meta_json']) ? (string) $log['meta_json'] : null);
            $lotId = isset($meta['lot_id']) && is_string($meta['lot_id']) ? $meta['lot_id'] : '';
            $locationId = isset($meta['location_id']) && is_string($meta['location_id']) ? $meta['location_id'] : '';
            $quantity = $meta['quantity'] ?? null;
            if ($lotId === '' || $locationId === '') {
                throw new InvalidArgumentException('출고 이력에 위치 정보가 없어 취소할 수 없습니다.');
            }
            $quantity = self::parsePositiveQuantity($quantity, '출고 이력의 수량을 확인할 수 없습니다.');

            $dup = $pdo->prepare('SELECT id FROM stock_issue_cancels WHERE issue_log_id = ?');
            $dup->execute([$issueLogId]);
            if ($dup->fetchColumn()) {
                throw new InvalidArgumentException('이미 취소된 출고입니다.');
            }

            $t = Support::now();
            $cancelId = Support::id('cx');
            try {
                $pdo->prepare(
                    'INSERT INTO stock_issue_cancels(id,issue_log_id,catalog_item_id,lot_id,location_id,quantity,reason,actor_id,actor_name,created_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $cancelId, $issueLogId, $itemId, $lotId, $locationId, $quantity,
                    $reason, $actor['id'] ?? null, $actor['display_name'] ?? '시스템', $t,
                ]);
            } catch (PDOException $e) {
                if (self::isUniqueConflict($e)) {
                    throw new InvalidArgumentException('이미 취소된 출고입니다.', 0, $e);
                }
                throw $e;
            }

            $restore = $pdo->prepare(
                'UPDATE stock_lots SET quantity = quantity + CAST(? AS REAL), updated_at = ?
                 WHERE id = ? AND catalog_item_id = ?'
            );
            $restore->execute([$quantity, $t, $lotId, $itemId]);
            if ($restore->rowCount() !== 1) {
                throw new InvalidArgumentException('출고 당시 재고 위치를 찾을 수 없어 취소할 수 없습니다.');
            }

            $lotStmt = $pdo->prepare(
                'SELECT s.quantity, c.name, c.unit, l.name AS location_name
                 FROM stock_lots s JOIN catalog_items c ON c.id = s.catalog_item_id
                 JOIN locations l ON l.id = s.location_id WHERE s.id = ?'
            );
            $lotStmt->execute([$lotId]);
            $lot = $lotStmt->fetch(PDO::FETCH_ASSOC);
            $cancelMeta = [
                'issue_log_id' => $issueLogId,
                'cancel_id' => $cancelId,
                'lot_id' => $lotId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'remaining_quantity' => (float) $lot['quantity'],
                'reason' => $reason,
            ];
            $issueRoomId = isset($meta['room_id']) && is_string($meta['room_id']) ? $meta['room_id'] : '';
            if ($issueRoomId !== '') {
                $cancelMeta['room_id'] = $issueRoomId;
                if (isset($meta['room_name']) && is_string($meta['room_name']) && $meta['room_name'] !== '') {
                    $cancelMeta['room_name'] = $meta['room_name'];
                }
            }
            if (isset($meta['class_memo']) && is_string($meta['class_memo']) && $meta['class_memo'] !== '') {
                $cancelMeta['class_memo'] = $meta['class_memo'];
            }
            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'cancel_issue',
                'catalog',
                $itemId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "«{$lot['name']}» {$quantity} {$lot['unit']} 출고 취소 · {$lot['location_name']} · {$reason}",
                json_encode($cancelMeta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
        Alert::notifyLowStock($pdo, $itemId);
    }

    /**
     * @return array{id: string, name: string, kind: string}|null
     */
    private static function resolveIssueRoom(PDO $pdo, mixed $roomId): ?array
    {
        if ($roomId === null || $roomId === '') {
            return null;
        }
        if (!is_string($roomId) && !is_int($roomId)) {
            throw new InvalidArgumentException('분출할 실을 확인하세요.');
        }
        $roomId = trim((string) $roomId);
        if ($roomId === '') {
            return null;
        }
        $stmt = $pdo->prepare("SELECT id, name, kind FROM locations WHERE id = ? AND kind = 'room'");
        $stmt->execute([$roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('분출할 실을 확인하세요.');
        }
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function filterLogsByRoom(PDO $pdo, array $rows, ?string $roomId, int $limit): array
    {
        if ($roomId === null || $roomId === '') {
            return array_slice($rows, 0, $limit);
        }
        $ids = array_fill_keys(Support::descendantIds($pdo, $roomId), true);
        $out = [];
        foreach ($rows as $row) {
            $meta = Support::jsonDecode(isset($row['meta_json']) ? (string) $row['meta_json'] : null);
            $rid = isset($meta['room_id']) && is_string($meta['room_id']) ? $meta['room_id'] : '';
            if ($rid !== '' && isset($ids[$rid])) {
                $out[] = $row;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private static function parsePositiveQuantity(mixed $quantity, string $message): float
    {
        if ((!is_string($quantity) && !is_int($quantity) && !is_float($quantity)) || !is_numeric($quantity)) {
            throw new InvalidArgumentException($message);
        }
        $quantity = (float) $quantity;
        if (!is_finite($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException($message);
        }
        return $quantity;
    }

    private static function isUniqueConflict(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'UNIQUE constraint failed');
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
