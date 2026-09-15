<?php

declare(strict_types=1);

namespace Inni;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * 실무사 대여·반납 데스크 (#47). 스캔/검색 결과를 빌려주기·받아주기 액션으로 잇는다.
 */
final class Desk
{
    public const SESSION_BORROWER_KEY = 'desk_borrower_id';

    /**
     * @return list<array{id: string, display_name: string, role: string}>
     */
    public static function teachers(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT id, display_name, role FROM users
             WHERE status = 'active' AND role IN ('owner','manager','teacher')
             ORDER BY display_name COLLATE NOCASE, id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) $row['id'],
                'display_name' => (string) $row['display_name'],
                'role' => (string) $row['role'],
            ];
        }
        return $out;
    }

    /**
     * School-day default: today 16:00 local, or tomorrow 16:00 once that time has passed.
     */
    public static function defaultDueLocal(?DateTimeInterface $now = null): string
    {
        $now = $now instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($now)
            : new DateTimeImmutable('now');
        $due = $now->setTime(16, 0);
        if ($now >= $due) {
            $due = $due->modify('+1 day');
        }
        return $due->format('Y-m-d\TH:i');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveAsset(PDO $pdo, string $code): ?array
    {
        $hit = Scan::lookup($pdo, $code);
        if ($hit === null || $hit['kind'] !== 'asset') {
            return null;
        }
        return self::assetCard($pdo, $hit['id']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function searchAssets(PDO $pdo, string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $resolved = self::resolveAsset($pdo, $q);
        if ($resolved !== null) {
            return [$resolved];
        }

        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            "SELECT a.id, a.name, a.management_number, a.status, a.qr_code, a.location_id,
                    l.name AS location_name
             FROM assets a
             JOIN locations l ON l.id = a.location_id
             WHERE a.name LIKE ? OR a.management_number LIKE ?
                OR a.qr_code LIKE ? OR IFNULL(a.serial_number,'') LIKE ?
             ORDER BY a.name LIMIT 20"
        );
        $stmt->execute([$like, $like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function assetCard(PDO $pdo, string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT a.id, a.name, a.management_number, a.status, a.qr_code, a.location_id,
                    l.name AS location_name
             FROM assets a
             JOIN locations l ON l.id = a.location_id
             WHERE a.id = ?"
        );
        $stmt->execute([$id]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        return $asset ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function openLoan(PDO $pdo, string $assetId): ?array
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

    public static function rememberBorrower(?string $userId): void
    {
        $userId = trim((string) $userId);
        if ($userId === '') {
            unset($_SESSION[self::SESSION_BORROWER_KEY]);
            return;
        }
        $_SESSION[self::SESSION_BORROWER_KEY] = $userId;
    }

    public static function rememberedBorrower(): ?string
    {
        $id = $_SESSION[self::SESSION_BORROWER_KEY] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }
}
