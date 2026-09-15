<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;

/**
 * Optional lot code and expiry on a location stock row.
 * One lot per (catalog_item_id, location_id); attributes are not a second unique key.
 */
final class StockLot
{
    public const CODE_MAX = 80;

    public static function parseLotCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('로트 번호를 확인하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (str_contains($text, "\n") || str_contains($text, "\r")) {
            throw new InvalidArgumentException('로트 번호는 한 줄로 입력하세요.');
        }
        if (mb_strlen($text) > self::CODE_MAX) {
            throw new InvalidArgumentException('로트 번호는 ' . self::CODE_MAX . '자 이내로 입력하세요.');
        }
        return $text;
    }

    public static function parseExpiresAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('유통기한은 날짜로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $text, $m) !== 1) {
            throw new InvalidArgumentException('유통기한은 YYYY-MM-DD 형식으로 입력하세요.');
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('유통기한은 올바른 날짜로 입력하세요.');
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function isExpired(?string $expiresAt, ?string $today = null): bool
    {
        $expiresAt = $expiresAt !== null ? trim($expiresAt) : '';
        if ($expiresAt === '') {
            return false;
        }
        $today = $today !== null && trim($today) !== '' ? trim($today) : date('Y-m-d');
        return $expiresAt < $today;
    }

    public static function format(?string $lotCode, ?string $expiresAt): string
    {
        $parts = [];
        $lotCode = $lotCode !== null ? trim($lotCode) : '';
        $expiresAt = $expiresAt !== null ? trim($expiresAt) : '';
        if ($lotCode !== '') {
            $parts[] = '로트 ' . $lotCode;
        }
        if ($expiresAt !== '') {
            $label = self::isExpired($expiresAt) ? '유통기한 만료 ' : '유통기한 ';
            $parts[] = $label . $expiresAt;
        }
        return implode(' · ', $parts);
    }
}
