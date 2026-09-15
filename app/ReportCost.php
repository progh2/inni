<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Repair cost on an existing report. Does not change the status machine.
 */
final class ReportCost
{
    public const VENDOR_MAX = 200;
    public const BUDGET_LINE_MAX = 200;
    public const AMOUNT_MAX = 999999999999.0;
    public const YEAR_MIN = 1900;
    public const YEAR_MAX = 2100;

    /**
     * GET year/month for the totals view. Invalid values are ignored.
     *
     * @param array<string, mixed> $query
     * @return array{year: ?int, month: ?int}
     */
    public static function filtersFromRequest(array $query): array
    {
        $year = null;
        try {
            $year = self::parseYear($query['year'] ?? null);
        } catch (InvalidArgumentException) {
            $year = null;
        }
        $month = null;
        try {
            $month = self::parseMonth($query['month'] ?? null);
        } catch (InvalidArgumentException) {
            $month = null;
        }
        return ['year' => $year, 'month' => $month];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function save(
        PDO $pdo,
        array $actor,
        string $reportId,
        mixed $amount,
        mixed $vendor,
        mixed $budgetLine,
        mixed $costAt,
    ): array {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('수리비 기록 권한이 없습니다.');
        }

        $reportId = trim($reportId);
        if ($reportId === '') {
            throw new InvalidArgumentException('수리 요청을 찾을 수 없습니다.');
        }

        $amount = self::parseAmount($amount);
        $vendor = self::parseText($vendor, '업체', self::VENDOR_MAX);
        $budgetLine = self::parseText($budgetLine, '예산과목', self::BUDGET_LINE_MAX);
        $costAt = self::parseDate($costAt);
        if ($amount !== null && $costAt === null) {
            $costAt = self::today();
        }

        self::beginImmediate($pdo);
        try {
            $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ?');
            $stmt->execute([$reportId]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                throw new InvalidArgumentException('수리 요청을 찾을 수 없습니다.');
            }

            $t = Support::now();
            $upd = $pdo->prepare(
                'UPDATE reports
                 SET cost_amount = ?, cost_vendor = ?, cost_budget_line = ?, cost_at = ?, updated_at = ?
                 WHERE id = ?'
            );
            $upd->execute([$amount, $vendor, $budgetLine, $costAt, $t, $reportId]);

            $summary = self::logSummary($amount, $vendor, $budgetLine, $costAt);
            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'report_cost',
                'report',
                $reportId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                $summary,
                json_encode([
                    'cost_amount' => $amount,
                    'cost_vendor' => $vendor,
                    'cost_budget_line' => $budgetLine,
                    'cost_at' => $costAt,
                    'status' => $report['status'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                $t,
            ]);

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }

        $fresh = Report::find($pdo, $reportId);
        return is_array($fresh) ? $fresh : $report;
    }

    /**
     * @param array{year?: ?int, month?: ?int} $filters
     * @return array{year: ?int, month: ?int, total_amount: float, count: int}
     */
    public static function totals(PDO $pdo, array $filters = []): array
    {
        [$where, $params, $year, $month] = self::periodWhere($filters);
        $sql = 'SELECT COALESCE(SUM(cost_amount), 0) AS total_amount, COUNT(*) AS n
                FROM reports
                WHERE cost_amount IS NOT NULL AND cost_at IS NOT NULL' . $where;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'year' => $year,
            'month' => $month,
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'count' => (int) ($row['n'] ?? 0),
        ];
    }

    /**
     * Twelve months for a year. Empty months stay at 0.
     *
     * @return list<array{year: int, month: int, label: string, total_amount: float, count: int}>
     */
    public static function monthly(PDO $pdo, int $year): array
    {
        $year = self::requireYear($year);
        $stmt = $pdo->prepare(
            'SELECT CAST(substr(cost_at, 6, 2) AS INTEGER) AS month,
                    COALESCE(SUM(cost_amount), 0) AS total_amount,
                    COUNT(*) AS n
             FROM reports
             WHERE cost_amount IS NOT NULL AND cost_at IS NOT NULL
               AND substr(cost_at, 1, 4) = ?
             GROUP BY 1'
        );
        $stmt->execute([sprintf('%04d', $year)]);
        $byMonth = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $m = (int) ($row['month'] ?? 0);
            if ($m >= 1 && $m <= 12) {
                $byMonth[$m] = $row;
            }
        }
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $row = $byMonth[$m] ?? null;
            $out[] = [
                'year' => $year,
                'month' => $m,
                'label' => $m . '월',
                'total_amount' => $row ? (float) $row['total_amount'] : 0.0,
                'count' => $row ? (int) $row['n'] : 0,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{year: int, total_amount: float, count: int}>
     */
    public static function yearly(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT CAST(substr(cost_at, 1, 4) AS INTEGER) AS year,
                    COALESCE(SUM(cost_amount), 0) AS total_amount,
                    COUNT(*) AS n
             FROM reports
             WHERE cost_amount IS NOT NULL AND cost_at IS NOT NULL
             GROUP BY 1
             ORDER BY year DESC'
        );
        $out = [];
        foreach ($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $year = (int) ($row['year'] ?? 0);
            if ($year < self::YEAR_MIN || $year > self::YEAR_MAX) {
                continue;
            }
            $out[] = [
                'year' => $year,
                'total_amount' => (float) $row['total_amount'],
                'count' => (int) $row['n'],
            ];
        }
        return $out;
    }

    /**
     * Cases that have a recorded amount in the selected period.
     *
     * @param array{year?: ?int, month?: ?int} $filters
     * @return list<array<string, mixed>>
     */
    public static function cases(PDO $pdo, array $filters = []): array
    {
        [$where, $params] = self::periodWhere($filters);
        $sql = "SELECT r.*,
                       a.name AS asset_name,
                       a.management_number
                FROM reports r
                LEFT JOIN assets a ON r.target_type = 'asset' AND a.id = r.target_id
                WHERE r.cost_amount IS NOT NULL AND r.cost_at IS NOT NULL" . $where . '
                ORDER BY r.cost_at DESC, r.updated_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $n = (float) $value;
        } elseif (is_string($value)) {
            $text = trim(str_replace([',', ' ', '원'], '', $value));
            if ($text === '') {
                return null;
            }
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
                throw new InvalidArgumentException('금액은 0 이상의 숫자로 입력하세요.');
            }
            $n = (float) $text;
        } else {
            throw new InvalidArgumentException('금액은 0 이상의 숫자로 입력하세요.');
        }
        if ($n < 0 || $n > self::AMOUNT_MAX) {
            throw new InvalidArgumentException('금액은 0 이상으로 입력하세요.');
        }
        return $n;
    }

    public static function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('비용일은 YYYY-MM-DD로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1) {
            throw new InvalidArgumentException('비용일은 YYYY-MM-DD로 입력하세요.');
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year < self::YEAR_MIN || $year > self::YEAR_MAX || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('비용일이 올바른 날짜가 아닙니다.');
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function parseYear(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $year = $value;
        } elseif (is_string($value) && preg_match('/^\s*(\d{4})\s*$/', $value, $m) === 1) {
            $year = (int) $m[1];
        } else {
            throw new InvalidArgumentException('연도는 네 자리(YYYY)로 입력하세요.');
        }
        if ($year < self::YEAR_MIN || $year > self::YEAR_MAX) {
            throw new InvalidArgumentException(
                '연도는 ' . self::YEAR_MIN . '–' . self::YEAR_MAX . ' 사이로 입력하세요.'
            );
        }
        return $year;
    }

    public static function parseMonth(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $month = (int) $value;
        } elseif (is_string($value) && preg_match('/^\s*(\d{1,2})\s*$/', $value, $m) === 1) {
            $month = (int) $m[1];
        } else {
            throw new InvalidArgumentException('월은 1–12로 입력하세요.');
        }
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('월은 1–12로 입력하세요.');
        }
        return $month;
    }

    public static function formatAmount(?float $amount): string
    {
        if ($amount === null) {
            return '';
        }
        if (abs($amount - round($amount)) < 0.0001) {
            return number_format((int) round($amount)) . '원';
        }
        return number_format($amount, 2) . '원';
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    }

    public static function currentYear(): int
    {
        return (int) (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Seoul')))->format('Y');
    }

    private static function parseText(mixed $value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException($label . '은(는) 텍스트로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new InvalidArgumentException($label . '은(는) ' . $max . '자 이내로 입력하세요.');
        }
        return $text;
    }

    private static function requireYear(int $year): int
    {
        $parsed = self::parseYear($year);
        if ($parsed === null) {
            throw new InvalidArgumentException('연도는 네 자리(YYYY)로 입력하세요.');
        }
        return $parsed;
    }

    /**
     * @param array{year?: ?int, month?: ?int} $filters
     * @return array{0: string, 1: list<mixed>, 2: ?int, 3: ?int}
     */
    private static function periodWhere(array $filters): array
    {
        $year = null;
        try {
            $year = self::parseYear($filters['year'] ?? null);
        } catch (InvalidArgumentException) {
            $year = null;
        }
        $month = null;
        try {
            $month = self::parseMonth($filters['month'] ?? null);
        } catch (InvalidArgumentException) {
            $month = null;
        }
        $clauses = [];
        $params = [];
        if ($year !== null) {
            $clauses[] = 'substr(cost_at, 1, 4) = ?';
            $params[] = sprintf('%04d', $year);
        }
        if ($month !== null) {
            $clauses[] = 'CAST(substr(cost_at, 6, 2) AS INTEGER) = ?';
            $params[] = $month;
        }
        $sql = $clauses === [] ? '' : ' AND ' . implode(' AND ', $clauses);
        return [$sql, $params, $year, $month];
    }

    private static function logSummary(?float $amount, ?string $vendor, ?string $budgetLine, ?string $costAt): string
    {
        if ($amount === null && $vendor === null && $budgetLine === null && $costAt === null) {
            return '수리비 지움';
        }
        $parts = [];
        if ($amount !== null) {
            $parts[] = self::formatAmount($amount);
        }
        if ($vendor !== null) {
            $parts[] = $vendor;
        }
        if ($budgetLine !== null) {
            $parts[] = $budgetLine;
        }
        if ($costAt !== null) {
            $parts[] = $costAt;
        }
        return '수리비 기록' . ($parts !== [] ? ': ' . implode(' · ', $parts) : '');
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
