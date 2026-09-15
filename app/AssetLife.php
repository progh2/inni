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

    public const BAND_DUE = 'due';
    public const BAND_OVER = 'over';
    public const BAND_OK = 'ok';

    /** Calendar days before expiry that count as 임박 (inclusive of today and the horizon). */
    public const IMMINENT_DAYS = 365;

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

    /**
     * Classify useful life: 초과 (past expiry), 임박 (today through +IMMINENT_DAYS), 잔여, or null.
     */
    public static function band(?string $purchaseDate, mixed $years, ?string $onDate = null): ?string
    {
        $expiry = self::expiryDate($purchaseDate, $years);
        if ($expiry === null) {
            return null;
        }
        $today = self::normalizeOnDate($onDate);
        if ($expiry < $today) {
            return self::BAND_OVER;
        }
        $horizon = self::horizonDate($today);
        if ($expiry <= $horizon) {
            return self::BAND_DUE;
        }
        return self::BAND_OK;
    }

    public static function daysUntilExpiry(?string $purchaseDate, mixed $years, ?string $onDate = null): ?int
    {
        $expiry = self::expiryDate($purchaseDate, $years);
        if ($expiry === null) {
            return null;
        }
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', self::normalizeOnDate($onDate));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry);
        if ($today === false || $end === false) {
            return null;
        }
        return (int) $today->diff($end)->format('%r%a');
    }

    public static function bandLabel(string $band): string
    {
        return match ($band) {
            self::BAND_DUE => '임박',
            self::BAND_OVER => '초과',
            self::BAND_OK => '잔여',
            default => $band,
        };
    }

    public static function formatRemaining(?int $days): string
    {
        if ($days === null) {
            return '';
        }
        if ($days < 0) {
            return '초과 ' . abs($days) . '일';
        }
        if ($days === 0) {
            return '오늘 만료';
        }
        return '잔여 ' . $days . '일';
    }

    public static function normalizeOnDate(?string $onDate): string
    {
        $text = $onDate !== null ? trim($onDate) : '';
        if ($text !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    public static function horizonDate(string $onDate): string
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', self::normalizeOnDate($onDate));
        if ($start === false) {
            $start = new DateTimeImmutable('today');
        }
        return $start->modify('+' . self::IMMINENT_DAYS . ' days')->format('Y-m-d');
    }
}
