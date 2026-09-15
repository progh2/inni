<?php

declare(strict_types=1);

namespace Inni;

use PDO;

/**
 * Dedicated 연한·노후 board. Uses #39 purchase_date / useful_life_years
 * (filled by hand or by accepting a #40 PPS suggest). No adjust/destroy.
 */
final class AssetLifeBoard
{
    /** @var list<string> */
    public const LIFE_FILTERS = [AssetLife::BUCKET_IMMINENT, AssetLife::BUCKET_EXCEEDED];

    /**
     * @param array<string, mixed> $query
     * @return array{life: ?string}
     */
    public static function filtersFromRequest(array $query): array
    {
        return [
            'life' => self::normalizeLife($query['life'] ?? null),
        ];
    }

    public static function normalizeLife(mixed $life): ?string
    {
        if (!is_string($life) && !is_int($life)) {
            return null;
        }
        $life = trim((string) $life);
        if ($life === '임박') {
            $life = AssetLife::BUCKET_IMMINENT;
        } elseif ($life === '초과') {
            $life = AssetLife::BUCKET_EXCEEDED;
        }
        return in_array($life, self::LIFE_FILTERS, true) ? $life : null;
    }

    /**
     * Aging assets only (임박 ∪ 초과). Unknown life values are ignored.
     *
     * @param array{life?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public static function list(PDO $pdo, array $filters = [], ?string $today = null): array
    {
        $life = self::normalizeLife($filters['life'] ?? null);
        $today = AssetLife::today($today);
        $rows = self::lifeRows($pdo);
        $out = [];
        foreach ($rows as $row) {
            $enriched = self::enrich($row, $today);
            if ($enriched === null) {
                continue;
            }
            if ($life !== null && $enriched['life_bucket'] !== $life) {
                continue;
            }
            $out[] = $enriched;
        }

        usort($out, static function (array $a, array $b): int {
            $order = [
                AssetLife::BUCKET_EXCEEDED => 0,
                AssetLife::BUCKET_IMMINENT => 1,
            ];
            $cmp = ($order[(string) $a['life_bucket']] ?? 9) <=> ($order[(string) $b['life_bucket']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = ((string) ($a['expiry_date'] ?? '')) <=> ((string) ($b['expiry_date'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = ((string) ($a['name'] ?? '')) <=> ((string) ($b['name'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((string) ($a['management_number'] ?? '')) <=> ((string) ($b['management_number'] ?? ''));
        });

        return $out;
    }

    /**
     * Unfiltered aging totals for the current day.
     *
     * @return array{total: int, imminent: int, exceeded: int}
     */
    public static function summary(PDO $pdo, ?string $today = null): array
    {
        $today = AssetLife::today($today);
        $imminent = 0;
        $exceeded = 0;
        foreach (self::lifeRows($pdo) as $row) {
            $bucket = AssetLife::bucket(
                isset($row['purchase_date']) ? (string) $row['purchase_date'] : null,
                $row['useful_life_years'] ?? null,
                $today,
            );
            if ($bucket === AssetLife::BUCKET_IMMINENT) {
                $imminent++;
            } elseif ($bucket === AssetLife::BUCKET_EXCEEDED) {
                $exceeded++;
            }
        }

        return [
            'total' => $imminent + $exceeded,
            'imminent' => $imminent,
            'exceeded' => $exceeded,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function enrich(array $row, ?string $today = null): ?array
    {
        $today = AssetLife::today($today);
        $purchase = isset($row['purchase_date']) ? (string) $row['purchase_date'] : null;
        $years = $row['useful_life_years'] ?? null;
        $bucket = AssetLife::bucket($purchase, $years, $today);
        if ($bucket === null) {
            return null;
        }
        $days = AssetLife::daysUntilExpiry($purchase, $years, $today);
        $row['life_bucket'] = $bucket;
        $row['expiry_date'] = AssetLife::expiryDate($purchase, $years);
        $row['days_until_expiry'] = $days;
        $row['life_label'] = AssetLife::format($purchase, $years);
        $row['remaining_label'] = AssetLife::remainingLabel($days);
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lifeRows(PDO $pdo): array
    {
        $sql = "SELECT a.*,
                       l.name AS location_name,
                       l.kind AS location_kind
                FROM assets a
                JOIN locations l ON l.id = a.location_id
                WHERE a.purchase_date IS NOT NULL
                  AND TRIM(a.purchase_date) != ''
                  AND a.useful_life_years IS NOT NULL
                  AND CAST(a.useful_life_years AS INTEGER) >= 1";
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
