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
    public const IMMINENT_DAYS = 365;
    public const BUCKET_IMMINENT = 'imminent';
    public const BUCKET_EXCEEDED = 'exceeded';

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

    public static function today(?string $today = null): string
    {
        if ($today !== null) {
            $text = trim($today);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
                return $text;
            }
        }
        return date('Y-m-d');
    }

    /**
     * 임박 = 만료일이 오늘부터 IMMINENT_DAYS일 이내. 초과 = 만료일이 오늘 이전.
     * 도입일·내용연한이 없거나 만료가 더 멀면 null.
     */
    public static function bucket(?string $purchaseDate, mixed $years, ?string $today = null): ?string
    {
        $expiry = self::expiryDate($purchaseDate, $years);
        if ($expiry === null) {
            return null;
        }
        $today = self::today($today);
        if ($expiry < $today) {
            return self::BUCKET_EXCEEDED;
        }
        $horizon = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if ($horizon === false) {
            return null;
        }
        $until = $horizon->modify('+' . self::IMMINENT_DAYS . ' days')->format('Y-m-d');
        return $expiry <= $until ? self::BUCKET_IMMINENT : null;
    }

    public static function daysUntilExpiry(?string $purchaseDate, mixed $years, ?string $today = null): ?int
    {
        $expiry = self::expiryDate($purchaseDate, $years);
        if ($expiry === null) {
            return null;
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', self::today($today));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry);
        if ($start === false || $end === false) {
            return null;
        }
        $diff = $start->diff($end);
        $days = (int) $diff->format('%a');
        return $diff->invert === 1 ? -$days : $days;
    }

    public static function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            self::BUCKET_IMMINENT => '임박',
            self::BUCKET_EXCEEDED => '초과',
            default => $bucket,
        };
    }

    public static function remainingLabel(?int $days): string
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
