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

    public static function issue(PDO $pdo, array $actor, string $itemId, string $lotId, mixed $quantity, string $purpose): void
    {
        if (!Auth::canLoan($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('출고 권한이 없습니다.');
        }
        $quantity = self::parsePositiveQuantity($quantity, '출고 수량은 0보다 큰 숫자로 입력하세요.');
        $purpose = trim($purpose);
        if ($purpose === '') {
            throw new InvalidArgumentException('사용 사유를 입력하세요.');
        }

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
            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'), 'issue', 'catalog', $itemId, $actor['id'], $actor['display_name'],
                "«{$lot['name']}» {$quantity} {$lot['unit']} 사용 출고 · {$lot['location_name']} · {$purpose}",
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Support::now(),
            ]);
            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
        Alert::notifyLowStock($pdo, $itemId);
    }

    public static function restock(
        PDO $pdo,
        array $actor,
        string $itemId,
        string $locationId,
        mixed $quantity,
        string $note = '',
        mixed $lotCode = null,
        mixed $expiresAt = null,
        mixed $receivedAt = null,
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
        $attrs = StockLot::parseAttributes($lotCode, $expiresAt, $receivedAt);

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
                $sets = ['quantity = quantity + CAST(? AS REAL)', 'updated_at = ?'];
                $params = [$quantity, $t];
                if ($attrs['lot_code'] !== null) {
                    $sets[] = 'lot_code = ?';
                    $params[] = $attrs['lot_code'];
                }
                if ($attrs['expires_at'] !== null) {
                    $sets[] = 'expires_at = ?';
                    $params[] = $attrs['expires_at'];
                }
                if ($attrs['received_at'] !== null) {
                    $sets[] = 'received_at = ?';
                    $params[] = $attrs['received_at'];
                }
                $params[] = $lotId;
                $params[] = $itemId;
                $params[] = $quantity;
                $add = $pdo->prepare(
                    'UPDATE stock_lots SET ' . implode(', ', $sets) . '
                     WHERE id = ? AND catalog_item_id = ?
                     AND quantity + CAST(? AS REAL) > quantity'
                );
                $add->execute($params);
                if ($add->rowCount() !== 1) {
                    throw new InvalidArgumentException('입고할 재고를 확인하세요.');
                }
            } else {
                $lotId = Support::id('lot');
                try {
                    StockLot::insert(
                        $pdo,
                        $lotId,
                        $itemId,
                        $locationId,
                        $quantity,
                        $attrs['lot_code'],
                        $attrs['expires_at'],
                        $attrs['received_at'],
                        $t,
                    );
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
                'lot_code' => $attrs['lot_code'],
                'expires_at' => $attrs['expires_at'],
                'received_at' => $attrs['received_at'],
            ];
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
