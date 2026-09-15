<?php

declare(strict_types=1);

namespace Inni;

use PDO;

/**
 * Status board for 실험실습재료 (lab practice materials).
 *
 * Uses existing catalog types consumable/part only. fixture is stockable
 * 비품 and stays on #29 catalog browse; equipment is #30 기자재 현황.
 * Do not invent a new catalog type without a schema comment and owner confirmation.
 */
final class MaterialBoard
{
    /** @var list<string> */
    public const MATERIAL_TYPES = ['consumable', 'part'];

    public const ACTION_ISSUE = 'issue';
    public const ACTION_RESTOCK = 'restock';

    /**
     * @param array<string, mixed> $query
     * @return array{type: ?string, low_stock: bool, room: ?string, budget_program: ?string, budget_year: ?int}
     */
    public static function filtersFromRequest(array $query): array
    {
        $room = trim((string) ($query['room'] ?? ''));
        $budget = Budget::queryFilters($query);

        return [
            'type' => self::normalizeType($query['type'] ?? null),
            'low_stock' => Catalog::isTruthyFilter($query['low_stock'] ?? null),
            'room' => $room !== '' ? $room : null,
            'budget_program' => $budget['program'],
            'budget_year' => $budget['year'],
        ];
    }

    /**
     * Browse consumable/part stock. Search is not required; unknown type/budget values are ignored.
     *
     * @param array{type?: mixed, low_stock?: mixed, room?: ?string, budget_program?: ?string, budget_year?: int|string|null} $filters
     * @return list<array<string, mixed>>
     */
    public static function list(PDO $pdo, array $filters = []): array
    {
        $filters = self::normalizeFilters($filters);

        $sql = "SELECT c.id, c.name, c.type, c.unit, c.min_stock,
                       c.budget_program, c.budget_year,
                       COALESCE(SUM(s.quantity), 0) AS stock_qty
                FROM catalog_items c
                LEFT JOIN stock_lots s ON s.catalog_item_id = c.id
                WHERE c.type IN ('consumable','part')";
        $params = [];

        if ($filters['type'] !== null) {
            $sql .= ' AND c.type = ?';
            $params[] = $filters['type'];
        }

        if ($filters['room'] !== null) {
            $ids = Support::descendantIds($pdo, $filters['room']);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND c.id IN (SELECT catalog_item_id FROM stock_lots WHERE location_id IN ($in))";
            array_push($params, ...$ids);
        }

        $budget = Budget::filterSql('c', $filters['budget_program'], $filters['budget_year']);
        $sql .= $budget['sql'];
        $params = array_merge($params, $budget['params']);

        $sql .= ' GROUP BY c.id';
        if ($filters['low_stock']) {
            $sql .= ' HAVING c.min_stock IS NOT NULL
                      AND COALESCE(SUM(s.quantity), 0) < c.min_stock';
        }
        $sql .= " ORDER BY CASE
                    WHEN c.min_stock IS NOT NULL AND COALESCE(SUM(s.quantity), 0) < c.min_stock THEN 0
                    ELSE 1
                  END, c.name";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        return self::attachLots($pdo, $rows);
    }

    /**
     * Unfiltered board totals. Low-stock matches Alert::listLowStock / Catalog::rowIsLowStock.
     *
     * @return array{total: int, low_stock: int, by_type: array<string, int>}
     */
    public static function summary(PDO $pdo): array
    {
        $byType = [];
        foreach (self::MATERIAL_TYPES as $type) {
            $byType[$type] = 0;
        }
        $rows = $pdo->query(
            "SELECT type, COUNT(*) AS n FROM catalog_items
             WHERE type IN ('consumable','part') GROUP BY type"
        );
        if ($rows) {
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $type = (string) ($row['type'] ?? '');
                if (isset($byType[$type])) {
                    $byType[$type] = (int) $row['n'];
                }
            }
        }

        $low = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT c.id
                FROM catalog_items c
                LEFT JOIN stock_lots s ON s.catalog_item_id = c.id
                WHERE c.type IN ('consumable','part') AND c.min_stock IS NOT NULL
                GROUP BY c.id
                HAVING COALESCE(SUM(s.quantity), 0) < c.min_stock
             )"
        )->fetchColumn();

        return [
            'total' => array_sum($byType),
            'low_stock' => $low,
            'by_type' => $byType,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rooms(PDO $pdo): array
    {
        return AssetBoard::rooms($pdo);
    }

    public static function normalizeType(mixed $type): ?string
    {
        if (!is_string($type)) {
            return null;
        }
        $type = trim($type);
        return in_array($type, self::MATERIAL_TYPES, true) ? $type : null;
    }

    /**
     * Same rule as Alert::listLowStock: consumable/part with min_stock, qty < min.
     *
     * @param array<string, mixed> $row
     */
    public static function rowIsLowStock(array $row): bool
    {
        return Catalog::rowIsLowStock($row);
    }

    /**
     * @param list<array<string, mixed>> $lots
     */
    public static function formatRooms(array $lots): string
    {
        $parts = [];
        foreach ($lots as $lot) {
            $name = trim((string) ($lot['location_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty = $lot['quantity'] ?? 0;
            $qtyText = is_numeric($qty) ? (string) (0 + (float) $qty) : (string) $qty;
            $parts[] = $name . ' ' . $qtyText;
        }
        return implode(' · ', $parts);
    }

    /**
     * GET deep-link to the item-show 분출 / 재입고 forms. Unknown action falls back to 분출.
     */
    public static function itemActionUrl(string $itemId, string $action): string
    {
        $itemId = trim($itemId);
        $normalized = self::normalizeAction($action) ?? self::ACTION_ISSUE;
        return App::url('items/show', ['id' => $itemId]) . '#' . $normalized;
    }

    public static function normalizeAction(mixed $action): ?string
    {
        if (!is_string($action)) {
            return null;
        }
        $action = trim($action);
        return in_array($action, [self::ACTION_ISSUE, self::ACTION_RESTOCK], true) ? $action : null;
    }

    /**
     * Unfiltered shortage list — 재입고 대기. Same qty < min_stock rule as summary.low_stock.
     *
     * @return list<array<string, mixed>>
     */
    public static function waitingRestock(PDO $pdo): array
    {
        return self::list($pdo, ['low_stock' => true]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{type: ?string, low_stock: bool, room: ?string, budget_program: ?string, budget_year: ?int}
     */
    private static function normalizeFilters(array $filters): array
    {
        $room = isset($filters['room']) && is_string($filters['room']) ? trim($filters['room']) : '';
        $budget = Budget::queryFilters($filters);

        return [
            'type' => self::normalizeType($filters['type'] ?? null),
            'low_stock' => Catalog::isTruthyFilter($filters['low_stock'] ?? null),
            'room' => $room !== '' ? $room : null,
            'budget_program' => $budget['program'],
            'budget_year' => $budget['year'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function attachLots(PDO $pdo, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row['id'];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT s.catalog_item_id, s.quantity,
                    l.id AS location_id, l.name AS location_name, l.kind AS location_kind,
                    p.name AS parent_name
             FROM stock_lots s
             JOIN locations l ON l.id = s.location_id
             LEFT JOIN locations p ON p.id = l.parent_id
             WHERE s.catalog_item_id IN ($in)
             ORDER BY p.name, l.name"
        );
        $stmt->execute($ids);
        $byItem = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lot) {
            $itemId = (string) $lot['catalog_item_id'];
            $byItem[$itemId][] = $lot;
        }

        foreach ($rows as &$row) {
            $lots = $byItem[(string) $row['id']] ?? [];
            $row['lots'] = $lots;
            $row['rooms_label'] = self::formatRooms($lots);
            $row['low_stock'] = self::rowIsLowStock($row) ? 1 : 0;
        }
        unset($row);

        return $rows;
    }
}
