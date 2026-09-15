<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use Throwable;

final class Asset
{
    public static function updateBudget(
        PDO $pdo,
        array $actor,
        string $assetId,
        mixed $budgetProgram,
        mixed $budgetYear,
    ): void {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('수정 권한이 없습니다.');
        }

        $assetId = trim($assetId);
        if ($assetId === '') {
            throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
        }
        $budgetProgram = Budget::parseProgram($budgetProgram);
        $budgetYear = Budget::parseYear($budgetYear);

        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
            $stmt->execute([$assetId]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$asset) {
                throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
            }

            $t = Support::now();
            $pdo->prepare(
                'UPDATE assets SET budget_program = ?, budget_year = ?, updated_at = ? WHERE id = ?'
            )->execute([$budgetProgram, $budgetYear, $t, $assetId]);

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'update',
                'asset',
                $assetId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "«{$asset['name']}» 구입 사업예산 수정",
                json_encode([
                    'before' => [
                        'budget_program' => $asset['budget_program'] ?? null,
                        'budget_year' => $asset['budget_year'] ?? null,
                    ],
                    'after' => [
                        'budget_program' => $budgetProgram,
                        'budget_year' => $budgetYear,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Transaction may already be closed after a constraint abort.
            }
            throw $e;
        }
    }

    public static function updateLife(
        PDO $pdo,
        array $actor,
        string $assetId,
        mixed $purchaseDate,
        mixed $usefulLifeYears,
    ): void {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('수정 권한이 없습니다.');
        }

        $assetId = trim($assetId);
        if ($assetId === '') {
            throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
        }
        $purchaseDate = AssetLife::parsePurchaseDate($purchaseDate);
        $usefulLifeYears = AssetLife::parseUsefulLifeYears($usefulLifeYears);

        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
            $stmt->execute([$assetId]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$asset) {
                throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
            }

            $t = Support::now();
            $pdo->prepare(
                'UPDATE assets SET purchase_date = ?, useful_life_years = ?, updated_at = ? WHERE id = ?'
            )->execute([$purchaseDate, $usefulLifeYears, $t, $assetId]);

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'update',
                'asset',
                $assetId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "«{$asset['name']}» 도입일·내용연한 수정",
                json_encode([
                    'before' => [
                        'purchase_date' => $asset['purchase_date'] ?? null,
                        'useful_life_years' => $asset['useful_life_years'] ?? null,
                    ],
                    'after' => [
                        'purchase_date' => $purchaseDate,
                        'useful_life_years' => $usefulLifeYears,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Transaction may already be closed after a constraint abort.
            }
            throw $e;
        }
    }

    public const REASON_MAX = 200;

    /**
     * Irreversible dispose: assets.status = retired. Manager+ only.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function retire(
        PDO $pdo,
        array $actor,
        string $assetId,
        mixed $reason,
        mixed $retiredOn,
        ?string $evidencePath = null,
    ): array {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('파기 권한이 없습니다.');
        }
        $id = $actor['id'] ?? null;
        $name = $actor['display_name'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($name) || $name === '') {
            throw new InvalidArgumentException('파기 권한이 없습니다.');
        }

        $assetId = trim($assetId);
        if ($assetId === '') {
            throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
        }
        $reason = self::parseRequiredText(
            $reason,
            '파기 사유를 입력하세요.',
            self::REASON_MAX,
            '파기 사유는 200자 이내로 입력하세요.',
        );
        $retiredOn = self::parseRetireDate($retiredOn);
        $evidencePath = self::nullablePath($evidencePath);

        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
            $stmt->execute([$assetId]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$asset) {
                throw new InvalidArgumentException('장비를 찾을 수 없습니다.');
            }
            $from = (string) ($asset['status'] ?? '');
            if ($from === 'retired') {
                throw new InvalidArgumentException('이미 파기된 장비입니다.');
            }
            if ($from === 'on_loan') {
                throw new InvalidArgumentException('대여 중인 장비는 반납 후 파기하세요.');
            }

            $openLoan = $pdo->prepare(
                "SELECT COUNT(*) FROM loans WHERE asset_id = ? AND status IN ('active','overdue')"
            );
            $openLoan->execute([$assetId]);
            if ((int) $openLoan->fetchColumn() > 0) {
                throw new InvalidArgumentException('대여 중인 장비는 반납 후 파기하세요.');
            }

            $exists = $pdo->prepare('SELECT id FROM asset_retirements WHERE asset_id = ?');
            $exists->execute([$assetId]);
            if ($exists->fetchColumn()) {
                throw new InvalidArgumentException('이미 파기된 장비입니다.');
            }

            $t = Support::now();
            $upd = $pdo->prepare(
                "UPDATE assets SET status = 'retired', updated_at = ?
                 WHERE id = ? AND status != 'retired' AND status != 'on_loan'"
            );
            $upd->execute([$t, $assetId]);
            if ($upd->rowCount() !== 1) {
                throw new InvalidArgumentException('이미 파기되었거나 파기할 수 없는 상태입니다.');
            }

            $retId = Support::id('ret');
            $pdo->prepare(
                'INSERT INTO asset_retirements(id,asset_id,reason,retired_on,evidence_path,actor_id,actor_name,created_at)
                 VALUES(?,?,?,?,?,?,?,?)'
            )->execute([
                $retId,
                $assetId,
                $reason,
                $retiredOn,
                $evidencePath,
                $actor['id'],
                $actor['display_name'],
                $t,
            ]);

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'retire',
                'asset',
                $assetId,
                $actor['id'],
                $actor['display_name'],
                "«{$asset['name']}» 파기 · {$retiredOn} · {$reason}",
                json_encode([
                    'retirement_id' => $retId,
                    'from' => $from,
                    'to' => 'retired',
                    'reason' => $reason,
                    'retired_on' => $retiredOn,
                    'evidence_path' => $evidencePath,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Transaction may already be closed after a constraint abort.
            }
            throw $e;
        }

        $row = self::retirement($pdo, $assetId);
        if (!$row) {
            throw new InvalidArgumentException('파기를 저장하지 못했습니다.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function retirement(PDO $pdo, string $assetId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM asset_retirements WHERE asset_id = ?');
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function parseRetireDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('파기 일자를 입력하세요.');
        }
        try {
            $date = AssetLife::parsePurchaseDate($value);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('파기 일자는 YYYY-MM-DD 형식으로 입력하세요.');
        }
        if ($date === null) {
            throw new InvalidArgumentException('파기 일자를 입력하세요.');
        }
        return $date;
    }

    private static function parseRequiredText(mixed $value, string $emptyMessage, int $max, string $maxMessage): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException($emptyMessage);
        }
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($emptyMessage);
        }
        if (str_contains($text, "\n") || str_contains($text, "\r")) {
            throw new InvalidArgumentException($emptyMessage);
        }
        if (function_exists('mb_strlen') ? mb_strlen($text) > $max : strlen($text) > $max) {
            throw new InvalidArgumentException($maxMessage);
        }
        return $text;
    }

    private static function nullablePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $path = trim($path);
        return $path === '' ? null : $path;
    }
}
