<?php

declare(strict_types=1);

namespace Inni;

use PDO;

final class Desk
{
    public const DUE_HOUR = 17;

    /**
     * School-day due time as `datetime-local` value.
     * Same day 17:00, or next day 17:00 when it is already 17:00 or later.
     */
    public static function defaultDueLocal(?int $now = null): string
    {
        $now = $now ?? time();
        $today1700 = strtotime(date('Y-m-d', $now) . ' 17:00:00');
        if ($today1700 === false) {
            $today1700 = $now + 4 * 3600;
        }
        $due = ((int) date('G', $now) >= self::DUE_HOUR)
            ? strtotime('+1 day', $today1700)
            : $today1700;
        return date('Y-m-d\TH:i', $due !== false ? $due : $now);
    }

    public static function defaultDueIso(?int $now = null): string
    {
        return date('c', (int) strtotime(self::defaultDueLocal($now)));
    }

    public static function dueIsoFromLocal(?string $local, ?int $now = null): string
    {
        $local = trim((string) $local);
        if ($local === '') {
            return self::defaultDueIso($now);
        }
        $ts = strtotime($local);
        return $ts === false ? self::defaultDueIso($now) : date('c', $ts);
    }

    /**
     * Active staff who can be picked as borrowers. Current actor first.
     *
     * @return list<array{id: string, display_name: string, role: string}>
     */
    public static function borrowers(PDO $pdo, array $actor): array
    {
        $stmt = $pdo->query(
            "SELECT id, display_name, role FROM users
             WHERE status = 'active' AND role IN ('owner','manager','teacher')
             ORDER BY display_name COLLATE NOCASE, id"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $actorId = (string) ($actor['id'] ?? '');
        usort($rows, static function (array $a, array $b) use ($actorId): int {
            if ($a['id'] === $actorId) {
                return -1;
            }
            if ($b['id'] === $actorId) {
                return 1;
            }
            return strcasecmp((string) $a['display_name'], (string) $b['display_name']);
        });
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findAsset(PDO $pdo, string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT a.*, l.name AS location_name
             FROM assets a
             JOIN locations l ON l.id = a.location_id
             WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        return $asset ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function openLoanForAsset(PDO $pdo, string $assetId): ?array
    {
        $assetId = trim($assetId);
        if ($assetId === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM loans
             WHERE asset_id = ? AND status IN ('active','overdue')
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$assetId]);
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
        return $loan ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function searchAssets(PDO $pdo, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . $q . '%';
        $limit = max(1, min(50, $limit));
        $stmt = $pdo->prepare(
            "SELECT a.id, a.name, a.management_number, a.status, a.qr_code, l.name AS location_name
             FROM assets a
             JOIN locations l ON l.id = a.location_id
             WHERE a.name LIKE ? OR a.management_number LIKE ? OR a.qr_code LIKE ?
                OR IFNULL(a.serial_number,'') LIKE ?
             ORDER BY a.name LIMIT {$limit}"
        );
        $stmt->execute([$like, $like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
