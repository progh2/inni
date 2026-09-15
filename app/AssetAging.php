<?php

declare(strict_types=1);

namespace Inni;

use PDO;

/**
 * Read-only 내용연한 임박/초과 board.
 * Expiry is purchase_date + useful_life_years (AssetLife::expiryDate).
 * Dispose/adjust (#53) and repair cost (#54) are out of scope.
 */
final class AssetAging
{
    /** @var list<string> */
    public const BANDS = [AssetLife::BAND_DUE, AssetLife::BAND_OVER];

    /**
     * GET filters. Unknown band values are ignored (show 임박+초과).
     *
     * @param array<string, mixed> $query
     * @return array{band: ?string}
     */
    public static function filtersFromRequest(array $query): array
    {
        $band = trim((string) ($query['band'] ?? ''));
        if (!in_array($band, self::BANDS, true)) {
            $band = '';
        }

        return [
            'band' => $band !== '' ? $band : null,
        ];
    }

    /**
     * Allowlisted query for filter chips / form.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public static function query(array $filters): array
    {
        $filters = self::normalizeFilters($filters);
        $query = [];
        if ($filters['band'] !== null) {
            $query['band'] = $filters['band'];
        }
        return $query;
    }

    /**
     * Assets whose useful life is 임박 or 초과. Incomplete dates are omitted.
     *
     * @param array{band?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public static function list(PDO $pdo, array $filters = [], ?string $onDate = null): array
    {
        $filters = self::normalizeFilters($filters);
        $onDate = AssetLife::normalizeOnDate($onDate);
        $rows = self::datedAssets($pdo);
        $out = [];
        foreach ($rows as $row) {
            $annotated = self::annotate($row, $onDate);
            if ($annotated === null) {
                continue;
            }
            $band = (string) $annotated['life_band'];
            if ($filters['band'] !== null && $band !== $filters['band']) {
                continue;
            }
            if ($filters['band'] === null && !in_array($band, self::BANDS, true)) {
                continue;
            }
            $out[] = $annotated;
        }

        usort($out, static function (array $a, array $b): int {
            $rank = [AssetLife::BAND_OVER => 0, AssetLife::BAND_DUE => 1];
            $ra = $rank[(string) ($a['life_band'] ?? '')] ?? 9;
            $rb = $rank[(string) ($b['life_band'] ?? '')] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return ((int) ($a['days_until'] ?? 0)) <=> ((int) ($b['days_until'] ?? 0));
        });

        return $out;
    }

    /**
     * Unfiltered 임박/초과 totals. Residual (잔여) is not counted.
     *
     * @return array{due: int, over: int, watch: int}
     */
    public static function summary(PDO $pdo, ?string $onDate = null): array
    {
        $due = 0;
        $over = 0;
        foreach (self::list($pdo, [], $onDate) as $row) {
            if (($row['life_band'] ?? '') === AssetLife::BAND_DUE) {
                $due++;
            } elseif (($row['life_band'] ?? '') === AssetLife::BAND_OVER) {
                $over++;
            }
        }

        return [
            'due' => $due,
            'over' => $over,
            'watch' => $due + $over,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function annotate(array $row, ?string $onDate = null): ?array
    {
        $purchase = isset($row['purchase_date']) ? (string) $row['purchase_date'] : null;
        $years = $row['useful_life_years'] ?? null;
        $band = AssetLife::band($purchase, $years, $onDate);
        if ($band === null) {
            return null;
        }
        $row['expiry_date'] = AssetLife::expiryDate($purchase, $years);
        $row['life_band'] = $band;
        $row['days_until'] = AssetLife::daysUntilExpiry($purchase, $years, $onDate);
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function datedAssets(PDO $pdo): array
    {
        $sql = "SELECT a.*,
                       l.name AS location_name,
                       l.kind AS location_kind
                FROM assets a
                JOIN locations l ON l.id = a.location_id
                WHERE a.purchase_date IS NOT NULL
                  AND TRIM(a.purchase_date) != ''
                  AND a.useful_life_years IS NOT NULL
                  AND a.useful_life_years >= 1
                ORDER BY a.name, a.management_number";
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{band: ?string}
     */
    private static function normalizeFilters(array $filters): array
    {
        $band = isset($filters['band']) && is_string($filters['band']) ? trim($filters['band']) : '';
        if (!in_array($band, self::BANDS, true)) {
            $band = '';
        }

        return [
            'band' => $band !== '' ? $band : null,
        ];
    }
}
