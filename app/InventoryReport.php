<?php

declare(strict_types=1);

namespace Inni;

use PDO;

/**
 * Budget-officer view of existing inventory_checks / inventory_check_lines.
 * Does not start, confirm, or finish sessions. No quantity re-entry.
 * Book qty = line.expected_qty. Physical qty = expected when confirmed, else 0.
 */
final class InventoryReport
{
    /** @var list<string> */
    public const COLUMNS = [
        'check_id',
        'check_location',
        'check_status',
        'started_at',
        'finished_at',
        'kind',
        'name',
        'code',
        'location_name',
        'budget_program',
        'budget_year',
        'book_qty',
        'physical_qty',
        'diff_qty',
        'status',
    ];

    /** @var array<string, string> */
    public const HEADER_LABELS = [
        'check_id' => '실사ID',
        'check_location' => '실',
        'check_status' => '실사상태',
        'started_at' => '시작',
        'finished_at' => '종료',
        'kind' => '종류',
        'name' => '품명',
        'code' => '코드',
        'location_name' => '위치',
        'budget_program' => '사업명',
        'budget_year' => '예산연도',
        'book_qty' => '장부수량',
        'physical_qty' => '실물수량',
        'diff_qty' => '차이',
        'status' => '확인여부',
    ];

    /**
     * GET filters. Invalid program/year values are ignored.
     *
     * @param array<string, mixed> $query
     * @return array{budget_program: ?string, budget_year: ?int, check_id: ?string}
     */
    public static function filtersFromRequest(array $query): array
    {
        $budget = Budget::queryFilters($query);
        $checkId = trim((string) ($query['check_id'] ?? ''));
        return [
            'budget_program' => $budget['program'],
            'budget_year' => $budget['year'],
            'check_id' => $checkId !== '' ? $checkId : null,
        ];
    }

    /**
     * @param array{budget_program?: ?string, budget_year?: int|string|null, check_id?: ?string} $filters
     * @return array{budget_program: ?string, budget_year: ?int, check_id: ?string}
     */
    public static function normalizeFilters(array $filters): array
    {
        return self::filtersFromRequest([
            'budget_program' => $filters['budget_program'] ?? null,
            'budget_year' => $filters['budget_year'] ?? null,
            'check_id' => $filters['check_id'] ?? null,
        ]);
    }

    /**
     * @param array{budget_program?: ?string, budget_year?: int|string|null, check_id?: ?string} $filters
     * @return array<string, string>
     */
    public static function queryParams(array $filters): array
    {
        $filters = self::normalizeFilters($filters);
        $query = [];
        if ($filters['budget_program'] !== null && $filters['budget_program'] !== '') {
            $query['budget_program'] = $filters['budget_program'];
        }
        if ($filters['budget_year'] !== null) {
            $query['budget_year'] = (string) $filters['budget_year'];
        }
        if ($filters['check_id'] !== null && $filters['check_id'] !== '') {
            $query['check_id'] = $filters['check_id'];
        }
        return $query;
    }

    /**
     * All sessions for the report dropdown (not budget-filtered).
     *
     * @return list<array<string, mixed>>
     */
    public static function sessionOptions(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT id, location_name, status, started_at, finished_at
             FROM inventory_checks
             ORDER BY started_at DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sessions with book/confirmed/missing totals for lines matching the budget filter.
     *
     * @param array{budget_program?: ?string, budget_year?: int|string|null, check_id?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public static function sessions(PDO $pdo, array $filters = []): array
    {
        $filters = self::normalizeFilters($filters);
        $budget = self::lineBudgetSql($filters['budget_program'], $filters['budget_year']);

        $sql = 'SELECT chk.id, chk.location_id, chk.location_name, chk.status,
                       chk.started_by, chk.started_at, chk.finished_at,
                       COUNT(l.id) AS book_lines,
                       COALESCE(SUM(CASE WHEN l.confirmed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS confirmed_lines,
                       COALESCE(SUM(CASE WHEN l.id IS NOT NULL AND l.confirmed_at IS NULL THEN 1 ELSE 0 END), 0) AS missing_lines,
                       COALESCE(SUM(l.expected_qty), 0) AS book_qty,
                       COALESCE(SUM(CASE WHEN l.confirmed_at IS NOT NULL THEN l.expected_qty ELSE 0 END), 0) AS confirmed_qty,
                       COALESCE(SUM(CASE WHEN l.id IS NOT NULL AND l.confirmed_at IS NULL THEN l.expected_qty ELSE 0 END), 0) AS missing_qty
                FROM inventory_checks chk
                LEFT JOIN inventory_check_lines l ON l.check_id = chk.id
                LEFT JOIN assets a ON l.kind = \'asset\' AND a.id = l.asset_id
                LEFT JOIN catalog_items ci ON l.kind = \'item\' AND ci.id = l.catalog_item_id
                WHERE 1=1';
        $params = [];
        if ($filters['check_id'] !== null) {
            $sql .= ' AND chk.id = ?';
            $params[] = $filters['check_id'];
        }
        $sql .= $budget['sql'];
        $params = array_merge($params, $budget['params']);
        $sql .= ' GROUP BY chk.id';
        if ($budget['sql'] !== '') {
            $sql .= ' HAVING COUNT(l.id) > 0';
        }
        $sql .= ' ORDER BY chk.started_at DESC, chk.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Book vs missing totals grouped by budget program/year.
     *
     * @param array{budget_program?: ?string, budget_year?: int|string|null, check_id?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public static function aggregates(PDO $pdo, array $filters = []): array
    {
        $filters = self::normalizeFilters($filters);
        $budget = self::lineBudgetSql($filters['budget_program'], $filters['budget_year']);

        $sql = 'SELECT budget_program, budget_year, book_lines, missing_lines, book_qty, missing_qty
                FROM (
                    SELECT ' . self::programExpr() . ' AS budget_program,
                           ' . self::yearExpr() . ' AS budget_year,
                           COUNT(*) AS book_lines,
                           SUM(CASE WHEN l.confirmed_at IS NULL THEN 1 ELSE 0 END) AS missing_lines,
                           COALESCE(SUM(l.expected_qty), 0) AS book_qty,
                           COALESCE(SUM(CASE WHEN l.confirmed_at IS NULL THEN l.expected_qty ELSE 0 END), 0) AS missing_qty
                    FROM inventory_check_lines l
                    JOIN inventory_checks chk ON chk.id = l.check_id
                    LEFT JOIN assets a ON l.kind = \'asset\' AND a.id = l.asset_id
                    LEFT JOIN catalog_items ci ON l.kind = \'item\' AND ci.id = l.catalog_item_id
                    WHERE 1=1';
        $params = [];
        if ($filters['check_id'] !== null) {
            $sql .= ' AND chk.id = ?';
            $params[] = $filters['check_id'];
        }
        $sql .= $budget['sql'];
        $params = array_merge($params, $budget['params']);
        $sql .= ' GROUP BY 1, 2
                ) grouped
                ORDER BY (grouped.budget_year IS NULL), grouped.budget_year DESC,
                         (grouped.budget_program IS NULL OR grouped.budget_program = \'\'), grouped.budget_program';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Unconfirmed lines (장부 수량 있음, 실물 0) matching the budget filter.
     *
     * @param array{budget_program?: ?string, budget_year?: int|string|null, check_id?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public static function differences(PDO $pdo, array $filters = []): array
    {
        $filters = self::normalizeFilters($filters);
        $budget = self::lineBudgetSql($filters['budget_program'], $filters['budget_year']);

        $sql = 'SELECT l.id, l.check_id, l.kind, l.asset_id, l.catalog_item_id, l.stock_lot_id,
                       l.location_id, l.name, l.code, l.expected_qty, l.unit, l.confirmed_at,
                       loc.name AS location_name,
                       chk.location_name AS check_location,
                       chk.status AS check_status,
                       chk.started_at, chk.finished_at,
                       ' . self::programExpr() . ' AS budget_program,
                       ' . self::yearExpr() . ' AS budget_year,
                       l.expected_qty AS book_qty,
                       CASE WHEN l.confirmed_at IS NULL THEN 0 ELSE l.expected_qty END AS physical_qty,
                       CASE WHEN l.confirmed_at IS NULL THEN l.expected_qty ELSE 0 END AS diff_qty
                FROM inventory_check_lines l
                JOIN inventory_checks chk ON chk.id = l.check_id
                JOIN locations loc ON loc.id = l.location_id
                LEFT JOIN assets a ON l.kind = \'asset\' AND a.id = l.asset_id
                LEFT JOIN catalog_items ci ON l.kind = \'item\' AND ci.id = l.catalog_item_id
                WHERE l.confirmed_at IS NULL';
        $params = [];
        if ($filters['check_id'] !== null) {
            $sql .= ' AND chk.id = ?';
            $params[] = $filters['check_id'];
        }
        $sql .= $budget['sql'];
        $params = array_merge($params, $budget['params']);
        $sql .= ' ORDER BY chk.started_at DESC, chk.id DESC, l.kind, l.name, l.id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{budget_program?: ?string, budget_year?: int|string|null, check_id?: ?string} $filters
     * @return array{csv: string, count: int, rows: list<list<string>>}
     */
    public static function export(PDO $pdo, array $filters = []): array
    {
        $differences = self::differences($pdo, $filters);
        $rows = [self::headerRow()];
        foreach ($differences as $line) {
            $rows[] = self::csvRow($line);
        }
        return [
            'csv' => CatalogCsv::encode($rows),
            'count' => count($differences),
            'rows' => $rows,
        ];
    }

    /**
     * @return list<string>
     */
    public static function headerRow(): array
    {
        $out = [];
        foreach (self::COLUMNS as $col) {
            $out[] = self::HEADER_LABELS[$col];
        }
        return $out;
    }

    public static function checkStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => '진행',
            'done' => '종료',
            default => $status,
        };
    }

    public static function lineKindLabel(string $kind): string
    {
        return $kind === 'asset' ? '장비' : '품목';
    }

    /**
     * @param array<string, mixed> $line
     * @return list<string>
     */
    private static function csvRow(array $line): array
    {
        $status = ($line['confirmed_at'] ?? null) !== null ? '확인' : '미확인';
        return [
            (string) ($line['check_id'] ?? ''),
            (string) ($line['check_location'] ?? ''),
            self::checkStatusLabel((string) ($line['check_status'] ?? '')),
            (string) ($line['started_at'] ?? ''),
            (string) ($line['finished_at'] ?? ''),
            self::lineKindLabel((string) ($line['kind'] ?? '')),
            (string) ($line['name'] ?? ''),
            (string) ($line['code'] ?? ''),
            (string) ($line['location_name'] ?? ''),
            (string) ($line['budget_program'] ?? ''),
            self::yearText($line['budget_year'] ?? null),
            self::qtyText($line['book_qty'] ?? $line['expected_qty'] ?? 0),
            self::qtyText($line['physical_qty'] ?? 0),
            self::qtyText($line['diff_qty'] ?? 0),
            $status,
        ];
    }

    /**
     * Match asset lines on assets.budget_* and item lines on catalog_items.budget_*.
     * Compare columns directly (not CASE) so SQLite INTEGER affinity matches PDO binds.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private static function lineBudgetSql(?string $program, ?int $year): array
    {
        $asset = [];
        $item = [];
        $params = [];
        if ($program !== null && $program !== '') {
            $asset[] = 'a.budget_program = ?';
            $item[] = 'ci.budget_program = ?';
        }
        if ($year !== null) {
            $asset[] = 'a.budget_year = ?';
            $item[] = 'ci.budget_year = ?';
        }
        if ($asset === []) {
            return ['sql' => '', 'params' => []];
        }
        if ($program !== null && $program !== '') {
            $params[] = $program;
        }
        if ($year !== null) {
            $params[] = $year;
        }
        $assetSql = implode(' AND ', $asset);
        $itemSql = implode(' AND ', $item);
        $sql = " AND ((l.kind = 'asset' AND {$assetSql}) OR (l.kind = 'item' AND {$itemSql}))";
        return ['sql' => $sql, 'params' => array_merge($params, $params)];
    }

    private static function programExpr(): string
    {
        return "CASE WHEN l.kind = 'asset' THEN a.budget_program ELSE ci.budget_program END";
    }

    private static function yearExpr(): string
    {
        return "CASE WHEN l.kind = 'asset' THEN a.budget_year ELSE ci.budget_year END";
    }

    private static function yearText(mixed $year): string
    {
        if ($year === null || $year === '') {
            return '';
        }
        return (string) (int) $year;
    }

    private static function qtyText(mixed $qty): string
    {
        $n = (float) $qty;
        if (abs($n - round($n)) < 0.00001) {
            return (string) (int) round($n);
        }
        return rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');
    }
}
