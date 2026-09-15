<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use Throwable;

final class Asset
{
    public const RETIRE_REASON_MAX = 200;
    public const RETIRE_EVIDENCE_MAX = 200;

    /** @var list<string> */
    public const RETIRE_FROM = ['available', 'repair', 'moving', 'lost'];

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

    /**
     * Dispose an asset (파기). Destructive: manager+ only.
     *
     * @param array<string, mixed> $actor
     */
    public static function retire(
        PDO $pdo,
        array $actor,
        string $assetId,
        mixed $reason,
        mixed $retiredAt,
        mixed $evidence,
        ?string $evidencePath = null,
    ): void {
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
        $reason = self::parseRetireReason($reason);
        $retiredAt = self::parseRetireDate($retiredAt);
        $evidence = self::parseRetireEvidence($evidence, $evidencePath);

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
                throw new InvalidArgumentException('이미 폐기된 장비입니다.');
            }
            if ($from === 'on_loan') {
                throw new InvalidArgumentException('대여 중인 장비는 반납 후 파기하세요.');
            }
            if (!in_array($from, self::RETIRE_FROM, true)) {
                throw new InvalidArgumentException('그 상태에서는 파기할 수 없습니다.');
            }

            $t = Support::now();
            $upd = $pdo->prepare(
                "UPDATE assets
                 SET status = 'retired', retired_at = ?, retire_reason = ?, retire_evidence = ?, updated_at = ?
                 WHERE id = ? AND status = ? AND status IN ('available','repair','moving','lost')"
            );
            $upd->execute([$retiredAt, $reason, $evidence, $t, $assetId, $from]);
            if ($upd->rowCount() !== 1) {
                throw new InvalidArgumentException('이미 처리되었거나 파기할 수 없는 상태입니다.');
            }

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'retire',
                'asset',
                $assetId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "«{$asset['name']}» 파기 · {$reason} · {$retiredAt}",
                json_encode([
                    'from' => $from,
                    'to' => 'retired',
                    'reason' => $reason,
                    'retired_at' => $retiredAt,
                    'evidence' => $evidence,
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

    public static function parseRetireReason(mixed $reason): string
    {
        if ($reason === null || (!is_string($reason) && !is_int($reason) && !is_float($reason))) {
            throw new InvalidArgumentException('파기 사유를 입력하세요.');
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new InvalidArgumentException('파기 사유를 입력하세요.');
        }
        if (str_contains($reason, "\n") || str_contains($reason, "\r")) {
            throw new InvalidArgumentException('파기 사유는 한 줄로 입력하세요.');
        }
        if (mb_strlen($reason) > self::RETIRE_REASON_MAX) {
            throw new InvalidArgumentException('파기 사유는 200자 이내로 입력하세요.');
        }
        return $reason;
    }

    public static function parseRetireDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('파기일을 입력하세요.');
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('파기일은 날짜로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException('파기일을 입력하세요.');
        }
        if (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $text, $m) !== 1) {
            throw new InvalidArgumentException('파기일은 YYYY-MM-DD 형식으로 입력하세요.');
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('파기일은 올바른 날짜로 입력하세요.');
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function parseRetireEvidence(mixed $evidence, ?string $evidencePath = null): string
    {
        $path = is_string($evidencePath) ? trim($evidencePath) : '';
        $note = '';
        if ($evidence !== null && (is_string($evidence) || is_int($evidence) || is_float($evidence))) {
            $note = trim((string) $evidence);
        } elseif ($evidence !== null && $evidence !== '') {
            throw new InvalidArgumentException('증빙을 확인하세요.');
        }
        if ($note !== '' && (str_contains($note, "\n") || str_contains($note, "\r"))) {
            throw new InvalidArgumentException('증빙은 한 줄로 입력하세요.');
        }
        if (mb_strlen($note) > self::RETIRE_EVIDENCE_MAX) {
            throw new InvalidArgumentException('증빙은 200자 이내로 입력하세요.');
        }
        if ($note === '' && $path === '') {
            throw new InvalidArgumentException('증빙 사진 또는 증빙 메모를 입력하세요.');
        }
        return $path !== '' ? $path : $note;
    }
}
