<?php

declare(strict_types=1);

namespace Inni;

use JsonException;
use RuntimeException;

/**
 * Static 조달청 내용연수 catalog (고시 제2024-30호, 2025-01-01 시행).
 *
 * Seed: app/data/pps_useful_life.json
 * Extend: append {class_number, name, years} to items, or replace notice+items
 * when the 고시 is revised. Do not call a live 조달청 API or crawl the PDF.
 *
 * Suggestions are candidates only — never written unless the user accepts
 * and the existing 내용연한 field is saved.
 */
final class PpsUsefulLife
{
    public const SEED_RELATIVE = 'app/data/pps_useful_life.json';
    public const DEFAULT_LIMIT = 8;
    public const MIN_NAME_LEN = 2;
    public const MIN_CLASS_PREFIX = 4;

    /** @var array<string, mixed>|null */
    private static ?array $catalog = null;

    public static function catalogPath(): string
    {
        return dirname(__DIR__) . '/' . self::SEED_RELATIVE;
    }

    public static function reset(): void
    {
        self::$catalog = null;
    }

    /**
     * @return array{notice: array<string, mixed>, items: list<array<string, mixed>>, extend?: string}
     */
    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $path = self::catalogPath();
        if (!is_file($path)) {
            throw new RuntimeException('조달청 내용연수 시드 파일이 없습니다.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('조달청 내용연수 시드를 읽지 못했습니다.');
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('조달청 내용연수 시드 JSON이 올바르지 않습니다.', 0, $e);
        }
        if (!is_array($data) || !isset($data['notice'], $data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('조달청 내용연수 시드 형식이 올바르지 않습니다.');
        }
        self::$catalog = $data;
        return $data;
    }

    public static function noticeLabel(): string
    {
        $notice = self::catalog()['notice'];
        if (isset($notice['label']) && is_string($notice['label']) && $notice['label'] !== '') {
            return $notice['label'];
        }
        $title = isset($notice['title']) && is_string($notice['title']) ? $notice['title'] : '조달청고시';
        return $title;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function items(): array
    {
        $out = [];
        foreach (self::catalog()['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::normalizeItem($row);
            if ($item !== null) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public static function normalizeClassNumber(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            return '';
        }
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\s·\-_.\/(),\[\]{}]+/u', '', $name) ?? $name;
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($name, 'UTF-8');
        }
        return strtolower($name);
    }

    /**
     * @return list<array{class_number: string, name: string, years: int, source: string, match: string, score: int, notice: string}>
     */
    public static function suggest(mixed $name, mixed $classNumber = '', int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(20, $limit));
        $classNumber = self::normalizeClassNumber($classNumber);
        $name = is_string($name) || is_int($name) ? trim((string) $name) : '';
        $normName = $name !== '' ? self::normalizeName($name) : '';

        $best = [];
        foreach (self::items() as $item) {
            $score = 0;
            $match = '';
            if ($classNumber !== '') {
                if ($item['class_number'] === $classNumber) {
                    $score = 100;
                    $match = 'class';
                } elseif (
                    strlen($classNumber) >= self::MIN_CLASS_PREFIX
                    && str_starts_with($item['class_number'], $classNumber)
                ) {
                    $score = 70;
                    $match = 'class';
                }
            }
            if ($normName !== '' && mb_strlen($normName, 'UTF-8') >= self::MIN_NAME_LEN) {
                $nameScore = self::nameScore($normName, $item['name']);
                if ($nameScore > $score) {
                    $score = $nameScore;
                    $match = 'name';
                } elseif ($nameScore > 0 && $match === '') {
                    $score = $nameScore;
                    $match = 'name';
                }
            }
            if ($score <= 0) {
                continue;
            }
            $key = $item['class_number'];
            if (!isset($best[$key]) || $score > $best[$key]['score']) {
                $best[$key] = self::hit($item, $score, $match);
            }
        }

        $hits = array_values($best);
        usort($hits, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $a['name'] <=> $b['name'];
        });
        return array_slice($hits, 0, $limit);
    }

    /**
     * @return array{notice: array<string, mixed>, query: array{name: string, class_number: string}, suggestions: list<array<string, mixed>>}
     */
    public static function response(mixed $name, mixed $classNumber = '', int $limit = self::DEFAULT_LIMIT): array
    {
        $nameText = is_string($name) || is_int($name) ? trim((string) $name) : '';
        return [
            'notice' => self::catalog()['notice'],
            'query' => [
                'name' => $nameText,
                'class_number' => self::normalizeClassNumber($classNumber),
            ],
            'suggestions' => self::suggest($name, $classNumber, $limit),
        ];
    }

    public static function sourceText(string $name, string $classNumber = ''): string
    {
        $label = self::noticeLabel();
        $name = trim($name);
        $classNumber = self::normalizeClassNumber($classNumber);
        $parts = [$label];
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($classNumber !== '') {
            $parts[] = $classNumber;
        }
        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{class_number: string, name: string, years: int}|null
     */
    private static function normalizeItem(array $row): ?array
    {
        $classNumber = self::normalizeClassNumber($row['class_number'] ?? '');
        $name = isset($row['name']) && (is_string($row['name']) || is_int($row['name']))
            ? trim((string) $row['name'])
            : '';
        $years = $row['years'] ?? null;
        if (!is_int($years) && !(is_string($years) && preg_match('/^\d{1,3}$/', $years) === 1)) {
            return null;
        }
        $years = (int) $years;
        if ($classNumber === '' || $name === '' || $years < AssetLife::YEARS_MIN || $years > AssetLife::YEARS_MAX) {
            return null;
        }
        return [
            'class_number' => $classNumber,
            'name' => $name,
            'years' => $years,
        ];
    }

    /**
     * @param array{class_number: string, name: string, years: int} $item
     * @return array{class_number: string, name: string, years: int, source: string, match: string, score: int, notice: string}
     */
    private static function hit(array $item, int $score, string $match): array
    {
        return [
            'class_number' => $item['class_number'],
            'name' => $item['name'],
            'years' => $item['years'],
            'source' => self::sourceText($item['name'], $item['class_number']),
            'match' => $match,
            'score' => $score,
            'notice' => self::noticeLabel(),
        ];
    }

    private static function nameScore(string $query, string $itemName): int
    {
        $item = self::normalizeName($itemName);
        if ($item === '' || $query === '') {
            return 0;
        }
        if ($query === $item) {
            return 90;
        }
        if (str_starts_with($item, $query)) {
            return 80;
        }
        if (mb_strlen($item, 'UTF-8') >= self::MIN_NAME_LEN && str_starts_with($query, $item)) {
            return 75;
        }
        if (str_contains($item, $query)) {
            return 60;
        }
        if (mb_strlen($item, 'UTF-8') >= 3 && str_contains($query, $item)) {
            return 55;
        }
        return 0;
    }
}
