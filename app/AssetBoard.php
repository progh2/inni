<?php

declare(strict_types=1);

namespace Inni;

use PDO;

final class AssetBoard
{
    /** @var list<string> */
    public const ASSET_STATUSES = ['available', 'on_loan', 'repair', 'moving', 'lost', 'retired'];

    /**
     * @param array<string, mixed> $query
     * @return array{status: ?string, room: ?string, overdue: bool, budget_program: ?string, budget_year: ?int}
     */
    public static function filtersFromRequest(array $query): array
    {
        $status = trim((string) ($query['status'] ?? ''));
        if (!in_array($status, self::ASSET_STATUSES, true)) {
            $status = '';
        }

        $room = trim((string) ($query['room'] ?? ''));

        $overdueRaw = $query['overdue'] ?? '';
        $overdue = $overdueRaw === '1' || $overdueRaw === 1 || $overdueRaw === true;

        $budget = Budget::queryFilters($query);

        return [
            'status' => $status !== '' ? $status : null,
            'room' => $room !== '' ? $room : null,
            'overdue' => $overdue,
            'budget_program' => $budget['program'],
            'budget_year' => $budget['year'],
        ];
    }

    /**
     * Browse assets. Search is not required; unknown status/budget values are ignored.
     *
     * @param array{status?: ?string, room?: ?string, overdue?: bool, budget_program?: ?string, budget_year?: int|string|null} $filters
     * @return list<array<string, mixed>>
     */
    public static function list(PDO $pdo, array $filters = []): array
    {
        $filters = self::normalizeFilters($filters);

        $sql = "SELECT a.*,
                       l.name AS location_name,
                       l.kind AS location_kind,
                       loan.id AS loan_id,
                       loan.status AS loan_status,
                       loan.borrower_name,
                       loan.due_at
                FROM assets a
                JOIN locations l ON l.id = a.location_id
                LEFT JOIN loans loan
                  ON loan.asset_id = a.id
                 AND loan.status IN ('active','overdue')";
        $where = [];
        $params = [];

        if ($filters['status'] !== null) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }

        if ($filters['room'] !== null) {
            $ids = Support::descendantIds($pdo, $filters['room']);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "a.location_id IN ($in)";
            array_push($params, ...$ids);
        }

        if ($filters['overdue']) {
            $where[] = "loan.status = 'overdue'";
        }

        $budget = Budget::filterSql('a', $filters['budget_program'], $filters['budget_year']);
        if ($where !== [] || $budget['sql'] !== '') {
            $sql .= ' WHERE 1=1';
            if ($where !== []) {
                $sql .= ' AND ' . implode(' AND ', $where);
            }
            $sql .= $budget['sql'];
            $params = array_merge($params, $budget['params']);
        }

        $sql .= " ORDER BY CASE
                    WHEN loan.status = 'overdue' THEN 0
                    WHEN a.status = 'on_loan' THEN 1
                    WHEN a.status = 'repair' THEN 2
                    WHEN a.status = 'lost' THEN 3
                    ELSE 4
                  END, a.name, a.management_number";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Unfiltered board totals. Overdue follows loan.status after Alert::refreshOverdue.
     *
     * @return array{total: int, overdue: int, by_status: array<string, int>}
     */
    public static function summary(PDO $pdo): array
    {
        $byStatus = [];
        foreach (self::ASSET_STATUSES as $status) {
            $byStatus[$status] = 0;
        }
        $rows = $pdo->query('SELECT status, COUNT(*) AS n FROM assets GROUP BY status');
        if ($rows) {
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status = (string) ($row['status'] ?? '');
                if (isset($byStatus[$status])) {
                    $byStatus[$status] = (int) $row['n'];
                }
            }
        }

        $overdue = (int) $pdo->query(
            "SELECT COUNT(*) FROM loans
             WHERE status = 'overdue' AND asset_id IS NOT NULL"
        )->fetchColumn();

        return [
            'total' => array_sum($byStatus),
            'overdue' => $overdue,
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rooms(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT r.id, r.name, r.code, p.name AS parent_name
             FROM locations r
             LEFT JOIN locations p ON p.id = r.parent_id
             WHERE r.kind = 'room'
             ORDER BY p.name, r.name"
        );
        return $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Loan overdue wins over asset on_loan, matching 대여 현황 badges.
     *
     * @param array<string, mixed> $row
     */
    public static function displayStatus(array $row): string
    {
        if (($row['loan_status'] ?? '') === 'overdue') {
            return 'overdue';
        }
        return (string) ($row['status'] ?? '');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{status: ?string, room: ?string, overdue: bool, budget_program: ?string, budget_year: ?int}
     */
    private static function normalizeFilters(array $filters): array
    {
        $status = isset($filters['status']) && is_string($filters['status']) ? trim($filters['status']) : '';
        if (!in_array($status, self::ASSET_STATUSES, true)) {
            $status = '';
        }

        $room = isset($filters['room']) && is_string($filters['room']) ? trim($filters['room']) : '';
        $budget = Budget::queryFilters($filters);

        return [
            'status' => $status !== '' ? $status : null,
            'room' => $room !== '' ? $room : null,
            'overdue' => !empty($filters['overdue']),
            'budget_program' => $budget['program'],
            'budget_year' => $budget['year'],
        ];
    }
}
