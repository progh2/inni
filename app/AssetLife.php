<?php

declare(strict_types=1);

namespace Inni;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Asset introduction date (도입일) and useful life in years (내용연한).
 * Empty is allowed. Expiry is purchase_date + years when both are set.
 */
final class AssetLife
{
    public const YEARS_MIN = 1;
    public const YEARS_MAX = 100;

    public static function parsePurchaseDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('도입일은 날짜로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $text, $m) !== 1) {
            throw new InvalidArgumentException('도입일은 YYYY-MM-DD 형식으로 입력하세요.');
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('도입일은 올바른 날짜로 입력하세요.');
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function parseUsefulLifeYears(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $years = $value;
        } elseif (is_string($value) && preg_match('/^\s*(\d{1,3})\s*$/', $value, $m) === 1) {
            $years = (int) $m[1];
        } else {
            throw new InvalidArgumentException('내용연한은 년 단위 정수로 입력하세요.');
        }
        if ($years < self::YEARS_MIN || $years > self::YEARS_MAX) {
            throw new InvalidArgumentException(
                '내용연한은 ' . self::YEARS_MIN . '–' . self::YEARS_MAX . '년 사이로 입력하세요.'
            );
        }
        return $years;
    }

    public static function expiryDate(?string $purchaseDate, mixed $years): ?string
    {
        $purchaseDate = $purchaseDate !== null ? trim($purchaseDate) : '';
        if ($purchaseDate === '' || $years === null || $years === '') {
            return null;
        }
        $years = (int) $years;
        if ($years < 1) {
            return null;
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $purchaseDate);
        if ($start === false) {
            return null;
        }
        return $start->modify('+' . $years . ' years')->format('Y-m-d');
    }

    public static function formatDate(?string $purchaseDate): string
    {
        $purchaseDate = $purchaseDate !== null ? trim($purchaseDate) : '';
        if ($purchaseDate === '') {
            return '';
        }
        $ts = strtotime($purchaseDate);
        if ($ts === false) {
            return $purchaseDate;
        }
        return date('Y-m-d', $ts);
    }

    /**
     * One-line label for lists: 도입일 · 내용연한 · 만료 예정일 (present parts only).
     */
    public static function format(?string $purchaseDate, mixed $years): string
    {
        $parts = [];
        $dateText = self::formatDate($purchaseDate);
        if ($dateText !== '') {
            $parts[] = '도입 ' . $dateText;
        }
        if ($years !== null && $years !== '') {
            $parts[] = ((int) $years) . '년';
        }
        $expiry = self::expiryDate($purchaseDate, $years);
        if ($expiry !== null) {
            $parts[] = '만료 예정 ' . $expiry;
        }
        return implode(' · ', $parts);
    }
}
