<?php

declare(strict_types=1);

namespace Inni;

use PDO;

/**
 * Budget-program inventory (재물조사) report.
 * Read-only: joins check lines to current asset/catalog budget fields.
 * Book vs physical: confirmed => physical = expected; missing => physical = 0.
 * Quantity re-entry and ledger adjust are out of scope (#53).
 */
final class InventoryBudget
{
    /** @var list<string> */
    public const CHECK_STATUSES = ['active', 'done'];

    /** @var list<string> */
    public const DIFFS = ['all', 'missing', 'confirmed'];

    /** @var list<string> */
    public const CSV_HEADERS = [
        '실사ID',
        '실사상태',
        '실',
        '종류',
        '이름',
        '코드',
        '사업명',
        '예산연도',
        '장부수량',
        '실물수량',
        '차이',
        '단위',
        '결과',
        '확인시각',
    ];

    /**
     * GET filters. Invalid program/year/status/diff values are ignored.
     *
     * @param array<string, mixed> $query
     * @return array{budget_program: ?string, budget_year: ?int, check_status: ?string, check_id: ?string, diff: string}
     */
    public static function filtersFromRequest(array $query): array
    {
        $budget = Budget::queryFilters($query);

        $status = trim((string) ($query['check_status'] ?? ''));
        if (!in_array($status, self::CHECK_STATUSES, true)) {
            $status = '';
        }

        $checkId = trim((string) ($query['check_id'] ?? ''));

        $diff = trim((string) ($query['diff'] ?? 'all'));
        if (!in_array($diff, self::DIFFS, true)) {
            $diff = 'all';
        }

        return [
            'budget_program' => $budget['program'],
            'budget_year' => $budget['year'],
            'check_status' => $status !== '' ? $status : null,
            'check_id' => $checkId !== '' ? $checkId : null,
            'diff' => $diff,
        ];
    }

    /**
     * Allowlisted query string for the report / CSV links.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public static function query(array $filters): array
    {
        $filters = self::normalizeFilters($filters);
        $out = [];
        if ($filters['budget_program'] !== null) {
            $out['budget_program'] = $filters['budget_program'];
        }
        if ($filters['budget_year'] !== null) {
            $out['budget_year'] = (string) $filters['budget_year'];
        }
        if ($filters['check_status'] !== null) {
            $out['check_status'] = $filters['check_status'];
        }
        if ($filters['check_id'] !== null) {
            $out['check_id'] = $filters['check_id'];
        }
        if ($filters['diff'] !== 'all') {
            $out['diff'] = $filters['diff'];
        }
        return $out;
    }

    /**
     * All inventory sessions (for the filter dropdown). Active first.
     *
     * @return list<array<string, mixed>>
     */
    public static function checks(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT id, location_id, location_name, status, started_at, finished_at
             FROM inventory_checks
             ORDER BY CASE status WHEN 'active' THEN 0 ELSE 1 END, started_at DESC"
        );
        return $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Filtered check lines with book/physical/diff quantities.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function lines(PDO $pdo, array $filters = []): array
    {
        [$where, $params] = self::whereSql($filters);
        $sql = 'SELECT l.id, l.check_id, l.kind, l.asset_id, l.catalog_item_id, l.stock_lot_id,
                       l.location_id, l.name, l.code, l.expected_qty, l.unit, l.confirmed_at, l.confirmed_by,
                       c.status AS check_status, c.location_name AS check_location_name,
                       c.started_at, c.finished_at,
                       loc.name AS location_name,
                       ' . self::budgetProgramExpr() . ' AS budget_program,
                       ' . self::budgetYearExpr() . ' AS budget_year
                ' . self::fromSql() . $where . '
                ORDER BY CASE WHEN l.confirmed_at IS NULL THEN 0 ELSE 1 END,
                         c.started_at DESC, l.kind, l.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::decorateLine($row);
        }
        return $out;
    }

    /**
     * Totals grouped by budget program + year.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function aggregates(PDO $pdo, array $filters = []): array
    {
        [$where, $params] = self::whereSql($filters);
        $sql = 'SELECT ' . self::budgetProgramExpr() . ' AS budget_program,
                       ' . self::budgetYearExpr() . ' AS budget_year,
                       COUNT(*) AS expected_count,
                       SUM(CASE WHEN l.confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed_count,
                       SUM(CASE WHEN l.confirmed_at IS NULL THEN 1 ELSE 0 END) AS missing_count,
                       SUM(l.expected_qty) AS expected_qty,
                       SUM(CASE WHEN l.confirmed_at IS NOT NULL THEN l.expected_qty ELSE 0 END) AS confirmed_qty,
                       SUM(CASE WHEN l.confirmed_at IS NULL THEN l.expected_qty ELSE 0 END) AS missing_qty
                ' . self::fromSql() . $where . '
                GROUP BY 1, 2
                ORDER BY budget_year DESC, budget_program';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::decorateAggregate($row);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   expected_count: int,
     *   confirmed_count: int,
     *   missing_count: int,
     *   expected_qty: float,
     *   confirmed_qty: float,
     *   missing_qty: float,
     *   check_count: int
     * }
     */
    public static function summary(PDO $pdo, array $filters = []): array
    {
        [$where, $params] = self::whereSql($filters);
        $sql = 'SELECT COUNT(*) AS expected_count,
                       SUM(CASE WHEN l.confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed_count,
                       SUM(CASE WHEN l.confirmed_at IS NULL THEN 1 ELSE 0 END) AS missing_count,
                       COALESCE(SUM(l.expected_qty), 0) AS expected_qty,
                       COALESCE(SUM(CASE WHEN l.confirmed_at IS NOT NULL THEN l.expected_qty ELSE 0 END), 0) AS confirmed_qty,
                       COALESCE(SUM(CASE WHEN l.confirmed_at IS NULL THEN l.expected_qty ELSE 0 END), 0) AS missing_qty,
                       COUNT(DISTINCT l.check_id) AS check_count
                ' . self::fromSql() . $where;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'expected_count' => (int) ($row['expected_count'] ?? 0),
            'confirmed_count' => (int) ($row['confirmed_count'] ?? 0),
            'missing_count' => (int) ($row['missing_count'] ?? 0),
            'expected_qty' => (float) ($row['expected_qty'] ?? 0),
            'confirmed_qty' => (float) ($row['confirmed_qty'] ?? 0),
            'missing_qty' => (float) ($row['missing_qty'] ?? 0),
            'check_count' => (int) ($row['check_count'] ?? 0),
        ];
    }

    /**
     * UTF-8 CSV without BOM (controller prepends BOM for Excel).
     *
     * @param array<string, mixed> $filters
     */
    public static function csv(PDO $pdo, array $filters = []): string
    {
        $rows = [self::CSV_HEADERS];
        foreach (self::lines($pdo, $filters) as $line) {
            $year = $line['budget_year'] ?? null;
            $rows[] = [
                (string) $line['check_id'],
                self::checkStatusLabel((string) ($line['check_status'] ?? '')),
                (string) ($line['check_location_name'] ?? ''),
                self::kindLabel((string) ($line['kind'] ?? '')),
                (string) ($line['name'] ?? ''),
                (string) ($line['code'] ?? ''),
                (string) ($line['budget_program'] ?? ''),
                $year !== null && $year !== '' ? (string) (int) $year : '',
                self::formatQty((float) $line['expected_qty']),
                self::formatQty((float) $line['physical_qty']),
                self::formatQty((float) $line['diff_qty']),
                (string) ($line['unit'] ?? ''),
                $line['is_confirmed'] ? '확인' : '미확인',
                (string) ($line['confirmed_at'] ?? ''),
            ];
        }
        return CatalogCsv::encode($rows);
    }

    public static function checkStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => '진행중',
            'done' => '종료',
            default => $status,
        };
    }

    public static function kindLabel(string $kind): string
    {
        return $kind === 'asset' ? '장비' : '품목';
    }

    public static function resultLabel(bool $confirmed): string
    {
        return $confirmed ? '확인' : '미확인';
    }

    public static function formatQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.0001) {
            return (string) (int) round($qty);
        }
        return rtrim(rtrim(sprintf('%.4f', $qty), '0'), '.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorateLine(array $row): array
    {
        $expected = (float) ($row['expected_qty'] ?? 0);
        $confirmed = ($row['confirmed_at'] ?? null) !== null && (string) $row['confirmed_at'] !== '';
        $physical = $confirmed ? $expected : 0.0;
        $row['expected_qty'] = $expected;
        $row['physical_qty'] = $physical;
        $row['diff_qty'] = $expected - $physical;
        $row['is_confirmed'] = $confirmed;
        $row['is_missing'] = !$confirmed;
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorateAggregate(array $row): array
    {
        $row['expected_count'] = (int) ($row['expected_count'] ?? 0);
        $row['confirmed_count'] = (int) ($row['confirmed_count'] ?? 0);
        $row['missing_count'] = (int) ($row['missing_count'] ?? 0);
        $row['expected_qty'] = (float) ($row['expected_qty'] ?? 0);
        $row['confirmed_qty'] = (float) ($row['confirmed_qty'] ?? 0);
        $row['missing_qty'] = (float) ($row['missing_qty'] ?? 0);
        $row['diff_qty'] = $row['expected_qty'] - $row['confirmed_qty'];
        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereSql(array $filters): array
    {
        $filters = self::normalizeFilters($filters);
        $clauses = [];
        $params = [];

        if ($filters['check_status'] !== null) {
            $clauses[] = 'c.status = ?';
            $params[] = $filters['check_status'];
        }
        if ($filters['check_id'] !== null) {
            $clauses[] = 'c.id = ?';
            $params[] = $filters['check_id'];
        }
        if ($filters['diff'] === 'missing') {
            $clauses[] = 'l.confirmed_at IS NULL';
        } elseif ($filters['diff'] === 'confirmed') {
            $clauses[] = 'l.confirmed_at IS NOT NULL';
        }
        if ($filters['budget_program'] !== null) {
            $clauses[] = "((l.kind = 'asset' AND a.budget_program = ?) OR (l.kind = 'item' AND ci.budget_program = ?))";
            $params[] = $filters['budget_program'];
            $params[] = $filters['budget_program'];
        }
        if ($filters['budget_year'] !== null) {
            $clauses[] = "((l.kind = 'asset' AND a.budget_year = ?) OR (l.kind = 'item' AND ci.budget_year = ?))";
            $params[] = $filters['budget_year'];
            $params[] = $filters['budget_year'];
        }

        if ($clauses === []) {
            return ['', []];
        }
        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{budget_program: ?string, budget_year: ?int, check_status: ?string, check_id: ?string, diff: string}
     */
    private static function normalizeFilters(array $filters): array
    {
        return self::filtersFromRequest($filters);
    }

    private static function fromSql(): string
    {
        return "FROM inventory_check_lines l
                JOIN inventory_checks c ON c.id = l.check_id
                JOIN locations loc ON loc.id = l.location_id
                LEFT JOIN assets a ON l.kind = 'asset' AND a.id = l.asset_id
                LEFT JOIN catalog_items ci ON l.kind = 'item' AND ci.id = l.catalog_item_id";
    }

    private static function budgetProgramExpr(): string
    {
        return "CASE WHEN l.kind = 'asset' THEN a.budget_program ELSE ci.budget_program END";
    }

    private static function budgetYearExpr(): string
    {
        return "CASE WHEN l.kind = 'asset' THEN a.budget_year ELSE ci.budget_year END";
    }
}
