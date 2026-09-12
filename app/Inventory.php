<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class Inventory
{
    /**
     * @return array<string, mixed>|null
     */
    public static function active(PDO $pdo): ?array
    {
        $row = $pdo->query("SELECT * FROM inventory_checks WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(PDO $pdo, string $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM inventory_checks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function lines(PDO $pdo, string $checkId): array
    {
        $stmt = $pdo->prepare(
            "SELECT l.*, loc.name AS location_name
             FROM inventory_check_lines l
             JOIN locations loc ON loc.id = l.location_id
             WHERE l.check_id = ?
             ORDER BY CASE WHEN l.confirmed_at IS NULL THEN 0 ELSE 1 END, l.kind, l.name"
        );
        $stmt->execute([$checkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function unchecked(PDO $pdo, string $checkId): array
    {
        $stmt = $pdo->prepare(
            "SELECT l.*, loc.name AS location_name
             FROM inventory_check_lines l
             JOIN locations loc ON loc.id = l.location_id
             WHERE l.check_id = ? AND l.confirmed_at IS NULL
             ORDER BY l.kind, l.name"
        );
        $stmt->execute([$checkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function start(PDO $pdo, array $actor, string $locationId): array
    {
        self::requireActor($actor);
        $locationId = trim($locationId);
        if ($locationId === '') {
            throw new InvalidArgumentException('실을 선택하세요.');
        }

        self::beginImmediate($pdo);
        try {
            if (self::active($pdo) !== null) {
                throw new InvalidArgumentException('이미 진행 중인 실사가 있습니다.');
            }

            $stmt = $pdo->prepare('SELECT * FROM locations WHERE id = ?');
            $stmt->execute([$locationId]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$location || ($location['kind'] ?? '') !== 'room') {
                throw new InvalidArgumentException('실을 선택하세요.');
            }

            $ids = Support::descendantIds($pdo, $locationId);
            $in = implode(',', array_fill(0, count($ids), '?'));

            $assets = $pdo->prepare(
                "SELECT a.id, a.name, a.management_number, a.qr_code, a.location_id
                 FROM assets a WHERE a.location_id IN ($in) ORDER BY a.name"
            );
            $assets->execute($ids);
            $assets = $assets->fetchAll(PDO::FETCH_ASSOC);

            $lots = $pdo->prepare(
                "SELECT s.id, s.catalog_item_id, s.location_id, s.quantity, c.name, c.unit, c.qr_code
                 FROM stock_lots s
                 JOIN catalog_items c ON c.id = s.catalog_item_id
                 WHERE s.location_id IN ($in)
                 ORDER BY c.name"
            );
            $lots->execute($ids);
            $lots = $lots->fetchAll(PDO::FETCH_ASSOC);

            $t = Support::now();
            $checkId = Support::id('inv');
            try {
                $pdo->prepare(
                    'INSERT INTO inventory_checks(id,location_id,location_name,status,started_by,started_at)
                     VALUES(?,?,?,?,?,?)'
                )->execute([
                    $checkId, $locationId, (string) $location['name'], 'active', $actor['id'], $t,
                ]);
            } catch (PDOException $e) {
                if (self::isUniqueConflict($e)) {
                    throw new InvalidArgumentException('이미 진행 중인 실사가 있습니다.', 0, $e);
                }
                throw $e;
            }

            $insert = $pdo->prepare(
                'INSERT INTO inventory_check_lines(
                    id,check_id,kind,asset_id,catalog_item_id,stock_lot_id,location_id,name,code,expected_qty,unit
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($assets as $asset) {
                $insert->execute([
                    Support::id('inl'),
                    $checkId,
                    'asset',
                    $asset['id'],
                    null,
                    null,
                    $asset['location_id'],
                    $asset['name'],
                    $asset['management_number'] ?: $asset['qr_code'],
                    1,
                    'ea',
                ]);
            }
            foreach ($lots as $lot) {
                $insert->execute([
                    Support::id('inl'),
                    $checkId,
                    'item',
                    null,
                    $lot['catalog_item_id'],
                    $lot['id'],
                    $lot['location_id'],
                    $lot['name'],
                    $lot['qr_code'],
                    $lot['quantity'],
                    $lot['unit'],
                ]);
            }

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'inventory_start',
                'location',
                $locationId,
                $actor['id'],
                $actor['display_name'],
                "«{$location['name']}» 실사 시작",
                json_encode([
                    'check_id' => $checkId,
                    'asset_count' => count($assets),
                    'item_count' => count($lots),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }

        $check = self::get($pdo, $checkId);
        if (!$check) {
            throw new InvalidArgumentException('실사를 시작하지 못했습니다.');
        }
        return $check;
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function confirm(PDO $pdo, array $actor, string $code): array
    {
        self::requireActor($actor);
        $code = trim($code);
        if ($code === '') {
            throw new InvalidArgumentException('코드를 입력하세요.');
        }

        self::beginImmediate($pdo);
        try {
            $check = self::active($pdo);
            if (!$check) {
                throw new InvalidArgumentException('진행 중인 실사가 없습니다.');
            }

            $hit = Scan::lookup($pdo, $code);
            if ($hit !== null && $hit['kind'] === 'location') {
                throw new InvalidArgumentException('위치 코드입니다. 장비나 품목 코드를 스캔하세요.');
            }

            $line = self::matchLine($pdo, (string) $check['id'], $code, $hit);
            if ($line === null) {
                if ($hit === null) {
                    throw new InvalidArgumentException('코드를 찾지 못했습니다: ' . $code);
                }
                throw new InvalidArgumentException('이 실의 예상 목록에 없는 코드입니다.');
            }
            if (($line['confirmed_at'] ?? null) !== null) {
                throw new InvalidArgumentException('이미 확인한 항목입니다.');
            }

            $t = Support::now();
            $upd = $pdo->prepare(
                'UPDATE inventory_check_lines SET confirmed_at = ?, confirmed_by = ?
                 WHERE id = ? AND check_id = ? AND confirmed_at IS NULL'
            );
            $upd->execute([$t, $actor['id'], $line['id'], $check['id']]);
            if ($upd->rowCount() !== 1) {
                throw new InvalidArgumentException('이미 확인한 항목입니다.');
            }

            $entityType = $line['kind'] === 'asset' ? 'asset' : 'catalog';
            $entityId = $line['kind'] === 'asset' ? (string) $line['asset_id'] : (string) $line['catalog_item_id'];
            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'inventory_confirm',
                $entityType,
                $entityId,
                $actor['id'],
                $actor['display_name'],
                "실사 확인 «{$line['name']}»",
                json_encode([
                    'check_id' => $check['id'],
                    'line_id' => $line['id'],
                    'code' => $code,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);

            self::commitImmediate($pdo);
            $line['confirmed_at'] = $t;
            $line['confirmed_by'] = $actor['id'];
            return $line;
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    /** 종료 시 미확인 목록만. 텔레그램·엑셀·이어하기는 범위 밖. */
    public static function finish(PDO $pdo, array $actor, string $checkId): array
    {
        self::requireActor($actor);
        $checkId = trim($checkId);
        if ($checkId === '') {
            throw new InvalidArgumentException('실사 세션이 없습니다.');
        }

        self::beginImmediate($pdo);
        try {
            $check = self::get($pdo, $checkId);
            if (!$check) {
                throw new InvalidArgumentException('실사 세션이 없습니다.');
            }
            if (($check['status'] ?? '') !== 'active') {
                throw new InvalidArgumentException('이미 종료된 실사입니다.');
            }

            $t = Support::now();
            $upd = $pdo->prepare(
                "UPDATE inventory_checks SET status = 'done', finished_at = ?
                 WHERE id = ? AND status = 'active'"
            );
            $upd->execute([$t, $checkId]);
            if ($upd->rowCount() !== 1) {
                throw new InvalidArgumentException('이미 종료된 실사입니다.');
            }

            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM inventory_check_lines WHERE check_id = ? AND confirmed_at IS NULL'
            );
            $countStmt->execute([$checkId]);
            $unchecked = (int) $countStmt->fetchColumn();

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'inventory_finish',
                'location',
                $check['location_id'],
                $actor['id'],
                $actor['display_name'],
                "«{$check['location_name']}» 실사 종료 · 미확인 {$unchecked}건",
                json_encode([
                    'check_id' => $checkId,
                    'unchecked' => $unchecked,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }

        $done = self::get($pdo, $checkId);
        if (!$done) {
            throw new InvalidArgumentException('실사를 종료하지 못했습니다.');
        }
        return $done;
    }

    /**
     * @param array<string, mixed> $actor
     */
    private static function requireActor(array $actor): void
    {
        if (!Auth::canInventory($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('실사 권한이 없습니다.');
        }
        $id = $actor['id'] ?? null;
        $name = $actor['display_name'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($name) || $name === '') {
            throw new InvalidArgumentException('실사 권한이 없습니다.');
        }
    }

    /**
     * @param array{kind: 'asset'|'catalog'|'location', id: string}|null $hit
     * @return array<string, mixed>|null
     */
    private static function matchLine(PDO $pdo, string $checkId, string $code, ?array $hit): ?array
    {
        if ($hit !== null && $hit['kind'] === 'asset') {
            $stmt = $pdo->prepare(
                "SELECT * FROM inventory_check_lines
                 WHERE check_id = ? AND kind = 'asset' AND asset_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$checkId, $hit['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        if ($hit !== null && $hit['kind'] === 'catalog') {
            $stmt = $pdo->prepare(
                "SELECT * FROM inventory_check_lines
                 WHERE check_id = ? AND kind = 'item' AND catalog_item_id = ?
                 ORDER BY CASE WHEN confirmed_at IS NULL THEN 0 ELSE 1 END
                 LIMIT 1"
            );
            $stmt->execute([$checkId, $hit['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM inventory_check_lines WHERE check_id = ? AND code = ? LIMIT 1'
        );
        $stmt->execute([$checkId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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
