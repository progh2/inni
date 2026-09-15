<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Optional lot attributes on stock_lots (location+qty remains unique).
 * Empty values are allowed. Shortage still uses catalog min_stock vs qty.
 */
final class StockLot
{
    public const CODE_MAX = 80;

    /**
     * @return array{lot_code: ?string, expires_at: ?string, received_at: ?string}
     */
    public static function parseAttributes(mixed $lotCode, mixed $expiresAt, mixed $receivedAt): array
    {
        return [
            'lot_code' => self::parseLotCode($lotCode),
            'expires_at' => self::parseDate($expiresAt, '유통기한'),
            'received_at' => self::parseDate($receivedAt, '입고일'),
        ];
    }

    public static function parseLotCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('로트번호는 텍스트로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::CODE_MAX) {
            throw new InvalidArgumentException('로트번호는 ' . self::CODE_MAX . '자 이내로 입력하세요.');
        }
        return $text;
    }

    public static function parseDate(mixed $value, string $label = '날짜'): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException($label . '은(는) 날짜로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $text, $m) !== 1) {
            throw new InvalidArgumentException($label . '은(는) YYYY-MM-DD 형식으로 입력하세요.');
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException($label . '은(는) 올바른 날짜로 입력하세요.');
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * One-line label: 로트 · 유통기한 · 입고일 (present parts only).
     */
    public static function format(?string $lotCode, ?string $expiresAt, ?string $receivedAt): string
    {
        $parts = [];
        $lotCode = $lotCode !== null ? trim($lotCode) : '';
        if ($lotCode !== '') {
            $parts[] = '로트 ' . $lotCode;
        }
        $expiry = AssetLife::formatDate($expiresAt);
        if ($expiry !== '') {
            $parts[] = '유통기한 ' . $expiry;
        }
        $received = AssetLife::formatDate($receivedAt);
        if ($received !== '') {
            $parts[] = '입고 ' . $received;
        }
        return implode(' · ', $parts);
    }

    public static function insert(
        PDO $pdo,
        string $id,
        string $catalogItemId,
        string $locationId,
        float $quantity,
        ?string $lotCode,
        ?string $expiresAt,
        ?string $receivedAt,
        string $updatedAt,
    ): void {
        $pdo->prepare(
            'INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,lot_code,expires_at,received_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?)'
        )->execute([
            $id, $catalogItemId, $locationId, $quantity, $lotCode, $expiresAt, $receivedAt, $updatedAt,
        ]);
    }

    /**
     * Update lot attributes only. Quantity and location stay unchanged.
     */
    public static function update(
        PDO $pdo,
        array $actor,
        string $itemId,
        string $lotId,
        mixed $lotCode,
        mixed $expiresAt,
        mixed $receivedAt,
    ): void {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('수정 권한이 없습니다.');
        }

        $itemId = trim($itemId);
        $lotId = trim($lotId);
        if ($itemId === '' || $lotId === '') {
            throw new InvalidArgumentException('로트를 확인하세요.');
        }
        $attrs = self::parseAttributes($lotCode, $expiresAt, $receivedAt);

        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare(
                'SELECT s.*, c.name AS item_name, c.type
                 FROM stock_lots s
                 JOIN catalog_items c ON c.id = s.catalog_item_id
                 WHERE s.id = ? AND s.catalog_item_id = ?'
            );
            $stmt->execute([$lotId, $itemId]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lot || !in_array((string) $lot['type'], ['fixture', 'consumable', 'part'], true)) {
                throw new InvalidArgumentException('로트를 찾을 수 없습니다.');
            }

            $t = Support::now();
            $pdo->prepare(
                'UPDATE stock_lots SET lot_code = ?, expires_at = ?, received_at = ?, updated_at = ?
                 WHERE id = ? AND catalog_item_id = ?'
            )->execute([
                $attrs['lot_code'], $attrs['expires_at'], $attrs['received_at'], $t, $lotId, $itemId,
            ]);

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'update',
                'catalog',
                $itemId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "«{$lot['item_name']}» 로트 정보 수정",
                json_encode([
                    'lot_id' => $lotId,
                    'before' => [
                        'lot_code' => $lot['lot_code'] ?? null,
                        'expires_at' => $lot['expires_at'] ?? null,
                        'received_at' => $lot['received_at'] ?? null,
                    ],
                    'after' => $attrs,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Transaction may already be closed after a constraint abort.
            }
            throw $e;
        }
    }
}
