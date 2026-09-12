<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use Throwable;

final class Catalog
{
    /**
     * Update existing catalog fields. Type, QR, and stock quantities stay unchanged.
     *
     * @param list<string> $tags
     */
    public static function update(
        PDO $pdo,
        array $actor,
        string $itemId,
        string $name,
        ?string $description,
        array $tags,
        string $unit,
        mixed $minStock,
        ?string $edufine,
        ?string $manufacturer,
        ?string $imagePath,
        bool $favorite,
    ): void {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('수정 권한이 없습니다.');
        }

        $itemId = trim($itemId);
        $name = trim($name);
        $unit = trim($unit);
        if ($itemId === '' || $name === '') {
            throw new InvalidArgumentException('필수 항목을 확인하세요.');
        }
        if ($unit === '') {
            $unit = 'ea';
        }
        $description = self::nullableTrim($description);
        $edufine = self::nullableTrim($edufine);
        $manufacturer = self::nullableTrim($manufacturer);
        $minStock = self::parseMinStock($minStock);
        $cleanTags = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $tag = trim($tag);
            if ($tag !== '') {
                $cleanTags[] = $tag;
            }
        }

        self::beginImmediate($pdo);
        try {
            $stmt = $pdo->prepare('SELECT * FROM catalog_items WHERE id = ?');
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new InvalidArgumentException('품목을 찾을 수 없습니다.');
            }

            $image = $imagePath !== null && $imagePath !== '' ? $imagePath : ($item['image_path'] ?? null);
            $t = Support::now();
            $update = $pdo->prepare(
                'UPDATE catalog_items
                 SET name = ?, description = ?, tags = ?, unit = ?, min_stock = ?,
                     edufine_number = ?, manufacturer = ?, image_path = ?, favorite = ?, updated_at = ?
                 WHERE id = ?'
            );
            $update->execute([
                $name,
                $description,
                json_encode($cleanTags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $unit,
                $minStock,
                $edufine,
                $manufacturer,
                $image,
                $favorite ? 1 : 0,
                $t,
                $itemId,
            ]);

            $meta = [
                'before' => [
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'min_stock' => $item['min_stock'],
                    'manufacturer' => $item['manufacturer'],
                    'edufine_number' => $item['edufine_number'],
                    'favorite' => (int) $item['favorite'],
                ],
                'after' => [
                    'name' => $name,
                    'unit' => $unit,
                    'min_stock' => $minStock,
                    'manufacturer' => $manufacturer,
                    'edufine_number' => $edufine,
                    'favorite' => $favorite ? 1 : 0,
                ],
            ];
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
                "«{$name}» 품목 수정",
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
    }

    private static function parseMinStock(mixed $minStock): ?float
    {
        if ($minStock === null || $minStock === '') {
            return null;
        }
        if ((!is_string($minStock) && !is_int($minStock) && !is_float($minStock)) || !is_numeric($minStock)) {
            throw new InvalidArgumentException('최소재고는 0 이상의 숫자로 입력하세요.');
        }
        $minStock = (float) $minStock;
        if (!is_finite($minStock) || $minStock < 0) {
            throw new InvalidArgumentException('최소재고는 0 이상의 숫자로 입력하세요.');
        }
        return $minStock;
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
