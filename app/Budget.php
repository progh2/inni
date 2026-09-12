<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;

/**
 * Purchase-budget fields shared by catalog_items and assets.
 * Free-text program name (no school presets). Year is YYYY or empty.
 */
final class Budget
{
    public const YEAR_MIN = 1900;
    public const YEAR_MAX = 2100;
    public const PROGRAM_MAX = 200;

    public static function parseProgram(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('사업명은 텍스트로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::PROGRAM_MAX) {
            throw new InvalidArgumentException('사업명은 ' . self::PROGRAM_MAX . '자 이내로 입력하세요.');
        }
        return $text;
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
            throw new InvalidArgumentException('예산연도는 네 자리 연도(YYYY)로 입력하세요.');
        }
        if ($year < self::YEAR_MIN || $year > self::YEAR_MAX) {
            throw new InvalidArgumentException(
                '예산연도는 ' . self::YEAR_MIN . '–' . self::YEAR_MAX . ' 사이로 입력하세요.'
            );
        }
        return $year;
    }

    public static function format(?string $program, mixed $year): string
    {
        $program = $program !== null ? trim($program) : '';
        $yearText = '';
        if ($year !== null && $year !== '') {
            $yearText = (string) (int) $year;
        }
        if ($program !== '' && $yearText !== '') {
            return $yearText . '년 · ' . $program;
        }
        if ($program !== '') {
            return $program;
        }
        if ($yearText !== '') {
            return $yearText . '년';
        }
        return '';
    }

    /**
     * Optional exact-match SQL for list/board filters (#29/#30).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public static function filterSql(string $alias, ?string $program, ?int $year): array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('잘못된 테이블 별칭입니다.');
        }
        $clauses = [];
        $params = [];
        if ($program !== null && $program !== '') {
            $clauses[] = "{$alias}.budget_program = ?";
            $params[] = $program;
        }
        if ($year !== null) {
            $clauses[] = "{$alias}.budget_year = ?";
            $params[] = $year;
        }
        if ($clauses === []) {
            return ['sql' => '', 'params' => []];
        }
        return ['sql' => ' AND ' . implode(' AND ', $clauses), 'params' => $params];
    }

    public static function likeSql(string $alias): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('잘못된 테이블 별칭입니다.');
        }
        return "IFNULL({$alias}.budget_program,'') LIKE ? OR CAST({$alias}.budget_year AS TEXT) LIKE ?";
    }
}
