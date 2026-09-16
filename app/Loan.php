<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class Loan
{
    public const RETURN_OK = 'ok';
    public const RETURN_ABNORMAL = 'abnormal';
    public const RETURN_NOTE_MAX = 200;

    /** @var list<string> */
    public const RETURN_CONDITIONS = [self::RETURN_OK, self::RETURN_ABNORMAL];

    public static function checkout(
        PDO $pdo,
        array $actor,
        string $assetId,
        string $borrowerName,
        ?string $borrowerNote,
        ?string $purpose,
        ?string $dueAt,
        ?string $borrowerUserId = null,
    ): string {
        if (!Auth::canLoan($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('대여 권한이 없습니다.');
        }

        $assetId = trim($assetId);
        if ($assetId === '') {
            throw new InvalidArgumentException('대여할 수 없는 장비입니다.');
        }

        $borrowerUserId = self::resolveBorrowerUserId($pdo, $actor, $borrowerUserId);
        $borrowerName = trim($borrowerName);
        if ($borrowerName === '') {
            $borrowerName = self::borrowerDisplayName($pdo, $borrowerUserId);
        }
        if ($borrowerName === '') {
            $borrowerName = trim((string) ($actor['display_name'] ?? ''));
        }
        if ($borrowerName === '') {
            throw new InvalidArgumentException('빌리는 사람을 입력하세요.');
        }

        $borrowerNote = self::nullableTrim($borrowerNote);
        $purpose = self::nullableTrim($purpose);
        $dueAt = self::nullableTrim($dueAt);

        $loanId = '';
        self::beginImmediate($pdo);
        try {
            $t = Support::now();
            $claim = $pdo->prepare(
                "UPDATE assets SET status = 'on_loan', updated_at = ?
                 WHERE id = ? AND status = 'available'"
            );
            $claim->execute([$t, $assetId]);
            if ($claim->rowCount() !== 1) {
                throw new InvalidArgumentException('대여할 수 없는 장비입니다.');
            }

            $assetStmt = $pdo->prepare('SELECT id, name, location_id FROM assets WHERE id = ?');
            $assetStmt->execute([$assetId]);
            $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$asset) {
                throw new InvalidArgumentException('대여할 수 없는 장비입니다.');
            }

            $loanId = Support::id('loan');
            try {
                $pdo->prepare(
                    'INSERT INTO loans(id,kind,asset_id,quantity,borrower_user_id,borrower_name,borrower_note,from_location_id,due_at,status,purpose,created_at,created_by)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $loanId, 'asset', $assetId, 1, $borrowerUserId, $borrowerName, $borrowerNote,
                    $asset['location_id'], $dueAt, 'active', $purpose, $t, $actor['id'],
                ]);
            } catch (PDOException $e) {
                if (self::isOpenLoanConflict($e)) {
                    throw new InvalidArgumentException('대여할 수 없는 장비입니다.', 0, $e);
                }
                throw $e;
            }

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'loan',
                'loan',
                $loanId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "«{$asset['name']}» 대여 → {$borrowerName}",
                null,
                $t,
            ]);

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
        Alert::refreshOverdue($pdo);
        return $loanId;
    }

    /**
     * @return string|null Asset id when the returned loan was tied to an asset.
     */
    public static function checkin(
        PDO $pdo,
        array $actor,
        string $loanId,
        mixed $condition = null,
        mixed $note = null,
    ): ?string {
        $loanId = trim($loanId);
        if ($loanId === '') {
            throw new InvalidArgumentException('반납할 대여를 확인하세요.');
        }
        $condition = self::parseReturnCondition($condition);
        $note = self::parseReturnNote($note);

        $assetId = null;
        self::beginImmediate($pdo);
        try {
            $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ?');
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$loan) {
                throw new InvalidArgumentException('반납할 대여를 확인하세요.');
            }
            if (!Auth::canReturn($actor, $loan)) {
                throw new InvalidArgumentException('반납 권한이 없습니다.');
            }

            $t = Support::now();
            $close = $pdo->prepare(
                "UPDATE loans SET status = 'returned', returned_at = ?, return_condition = ?, return_note = ?
                 WHERE id = ? AND status IN ('active','overdue')"
            );
            $close->execute([$t, $condition, $note, $loanId]);
            if ($close->rowCount() !== 1) {
                throw new InvalidArgumentException('이미 반납되었거나 반납할 수 없는 대여입니다.');
            }

            $assetId = $loan['asset_id'] ?? null;
            if (is_string($assetId) && $assetId !== '') {
                $free = $pdo->prepare(
                    "UPDATE assets SET status = 'available', updated_at = ?
                     WHERE id = ? AND status = 'on_loan'"
                );
                $free->execute([$t, $assetId]);
                if ($free->rowCount() !== 1) {
                    throw new InvalidArgumentException('장비 상태가 대여중이 아니라 반납할 수 없습니다.');
                }
            } else {
                $assetId = null;
            }

            $summary = '대여 반납';
            if ($condition !== null) {
                $summary .= ' · ' . self::returnConditionLabel($condition);
            }
            if ($note !== null) {
                $summary .= ' · ' . $note;
            }

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'return',
                'loan',
                $loanId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                $summary,
                json_encode([
                    'return_condition' => $condition,
                    'return_note' => $note,
                ], JSON_UNESCAPED_UNICODE),
                $t,
            ]);

            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            throw $e;
        }
        Alert::clearDispatch($pdo, Alert::EVENT_OVERDUE_LOAN, $loanId);
        return $assetId;
    }

    /**
     * Open loans for one borrower (`borrower_user_id`), overdue first.
     *
     * @return list<array<string, mixed>>
     */
    public static function inboxForUser(PDO $pdo, string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT l.*, a.name AS asset_name, a.management_number
             FROM loans l
             LEFT JOIN assets a ON a.id = l.asset_id
             WHERE l.status IN ('active','overdue')
               AND l.borrower_user_id = ?
             ORDER BY CASE l.status WHEN 'overdue' THEN 0 ELSE 1 END, l.due_at ASC, l.created_at ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recently closed loans for one borrower.
     *
     * @return list<array<string, mixed>>
     */
    public static function recentReturnsForUser(PDO $pdo, string $userId, int $limit = 8): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $stmt = $pdo->prepare(
            "SELECT l.*, a.name AS asset_name, a.management_number
             FROM loans l
             LEFT JOIN assets a ON a.id = l.asset_id
             WHERE l.status = 'returned'
               AND l.borrower_user_id = ?
             ORDER BY l.returned_at DESC, l.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function parseReturnCondition(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('이상유무를 확인하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $map = [
            self::RETURN_OK => self::RETURN_OK,
            self::RETURN_ABNORMAL => self::RETURN_ABNORMAL,
            '정상' => self::RETURN_OK,
            '이상' => self::RETURN_ABNORMAL,
        ];
        if (!isset($map[$text])) {
            throw new InvalidArgumentException('이상유무는 정상 또는 이상으로 입력하세요.');
        }
        return $map[$text];
    }

    public static function parseReturnNote(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('이상 내용은 텍스트로 입력하세요.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (str_contains($text, "\n") || str_contains($text, "\r")) {
            throw new InvalidArgumentException('이상 내용은 한 줄로 입력하세요.');
        }
        if (mb_strlen($text) > self::RETURN_NOTE_MAX) {
            throw new InvalidArgumentException('이상 내용은 ' . self::RETURN_NOTE_MAX . '자 이내로 입력하세요.');
        }
        return $text;
    }

    public static function returnConditionLabel(?string $condition): string
    {
        return match ($condition) {
            self::RETURN_OK => '정상',
            self::RETURN_ABNORMAL => '이상',
            default => '',
        };
    }

    private static function resolveBorrowerUserId(PDO $pdo, array $actor, ?string $borrowerUserId): ?string
    {
        $borrowerUserId = self::nullableTrim($borrowerUserId);
        if ($borrowerUserId === null) {
            $id = $actor['id'] ?? null;
            return is_string($id) && $id !== '' ? $id : null;
        }
        $stmt = $pdo->prepare('SELECT id, status FROM users WHERE id = ?');
        $stmt->execute([$borrowerUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('빌리는 사람을 확인하세요.');
        }
        return (string) $row['id'];
    }

    private static function borrowerDisplayName(PDO $pdo, ?string $userId): string
    {
        if ($userId === null || $userId === '') {
            return '';
        }
        $stmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function isOpenLoanConflict(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    private static function beginImmediate(PDO $pdo): void
    {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('BEGIN IMMEDIATE');
    }

    private static function commitImmediate(PDO $pdo): void
    {
        $pdo->exec('COMMIT');
    }

    private static function rollBackImmediate(PDO $pdo): void
    {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Transaction may already be closed after a constraint abort.
        }
    }
}
