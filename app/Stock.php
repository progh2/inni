<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;

final class Stock
{
    public static function issue(PDO $pdo, array $actor, string $itemId, string $lotId, mixed $quantity, string $purpose): void
    {
        if (!Auth::canLoan($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('출고 권한이 없습니다.');
        }
        if ((!is_string($quantity) && !is_int($quantity) && !is_float($quantity)) || !is_numeric($quantity)) {
            throw new InvalidArgumentException('출고 수량은 0보다 큰 숫자로 입력하세요.');
        }
        $quantity = (float) $quantity;
        if (!is_finite($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException('출고 수량은 0보다 큰 숫자로 입력하세요.');
        }
        $purpose = trim($purpose);
        if ($purpose === '') {
            throw new InvalidArgumentException('사용 사유를 입력하세요.');
        }

        $pdo->beginTransaction();
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
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
