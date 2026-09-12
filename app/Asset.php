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
}
