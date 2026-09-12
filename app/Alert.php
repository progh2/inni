<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;

final class Alert
{
    public const EVENT_LOW_STOCK = 'low_stock';
    public const EVENT_OVERDUE_LOAN = 'overdue_loan';

    public const SETTING_CHAT = 'telegram_chat_id';

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return [self::EVENT_LOW_STOCK, self::EVENT_OVERDUE_LOAN];
    }

    public static function settingKey(string $event): string
    {
        return 'telegram_event_' . $event;
    }

    public static function chatId(PDO $pdo): string
    {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([self::SETTING_CHAT]);
        $fromSettings = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }
        return Telegram::defaultChatId();
    }

    public static function eventEnabled(PDO $pdo, string $event): bool
    {
        if (!in_array($event, self::eventKeys(), true)) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([self::settingKey($event)]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return true;
        }
        return (string) $value === '1';
    }

    /**
     * @return array{chat_id: string, events: array<string, bool>}
     */
    public static function settings(PDO $pdo): array
    {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([self::SETTING_CHAT]);
        $stored = trim((string) ($stmt->fetchColumn() ?: ''));
        $events = [];
        foreach (self::eventKeys() as $key) {
            $events[$key] = self::eventEnabled($pdo, $key);
        }
        return [
            'chat_id' => $stored,
            'events' => $events,
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $events
     */
    public static function saveSettings(PDO $pdo, array $actor, string $chatId, array $events): void
    {
        if (!Auth::canConfigureAlerts($actor)) {
            throw new InvalidArgumentException('알림 설정 권한이 없습니다.');
        }
        $chatId = trim($chatId);
        if (str_contains($chatId, "\n") || str_contains($chatId, "\r") || str_contains($chatId, ':')) {
            throw new InvalidArgumentException('채팅 ID 형식을 확인하세요.');
        }
        self::upsertSetting($pdo, self::SETTING_CHAT, $chatId);
        foreach (self::eventKeys() as $key) {
            self::upsertSetting($pdo, self::settingKey($key), !empty($events[$key]) ? '1' : '0');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listLowStock(PDO $pdo, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $pdo->query(
            "SELECT c.id, c.name, c.unit, c.min_stock, c.type, COALESCE(SUM(s.quantity),0) AS qty
             FROM catalog_items c
             LEFT JOIN stock_lots s ON s.catalog_item_id = c.id
             WHERE c.type IN ('consumable','part') AND c.min_stock IS NOT NULL
             GROUP BY c.id
             HAVING qty < c.min_stock
             ORDER BY c.name
             LIMIT {$limit}"
        );
        return $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function lowStockItem(PDO $pdo, string $itemId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT c.id, c.name, c.unit, c.min_stock, c.type, COALESCE(SUM(s.quantity),0) AS qty
             FROM catalog_items c
             LEFT JOIN stock_lots s ON s.catalog_item_id = c.id
             WHERE c.id = ? AND c.type IN ('consumable','part') AND c.min_stock IS NOT NULL
             GROUP BY c.id
             HAVING qty < c.min_stock"
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listOverdueLoans(PDO $pdo, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $pdo->query(
            "SELECT l.*, a.name AS asset_name, a.management_number
             FROM loans l
             LEFT JOIN assets a ON a.id = l.asset_id
             WHERE l.status = 'overdue'
             ORDER BY l.due_at ASC
             LIMIT {$limit}"
        );
        return $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * @return list<string> newly marked loan ids
     */
    public static function markOverdue(PDO $pdo, ?string $now = null): array
    {
        $now = $now ?? Support::now();
        $stmt = $pdo->prepare(
            "SELECT id FROM loans
             WHERE status = 'active' AND due_at IS NOT NULL AND due_at < ?"
        );
        $stmt->execute([$now]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $pdo->prepare(
            "UPDATE loans SET status = 'overdue'
             WHERE status = 'active' AND due_at IS NOT NULL AND due_at < ?"
        )->execute([$now]);
        return $ids;
    }

    public static function refreshOverdue(PDO $pdo, ?string $now = null): void
    {
        foreach (self::markOverdue($pdo, $now) as $loanId) {
            self::notifyOverdueLoan($pdo, $loanId);
        }
    }

    public static function notifyLowStock(PDO $pdo, string $itemId): void
    {
        try {
            $itemId = trim($itemId);
            if ($itemId === '') {
                return;
            }
            $item = self::lowStockItem($pdo, $itemId);
            if ($item === null) {
                self::clearDispatch($pdo, self::EVENT_LOW_STOCK, $itemId);
                return;
            }
            self::dispatch($pdo, self::EVENT_LOW_STOCK, $itemId, self::formatLowStock($item));
        } catch (\Throwable $e) {
            error_log((string) $e);
        }
    }

    public static function notifyOverdueLoan(PDO $pdo, string $loanId): void
    {
        try {
            $loanId = trim($loanId);
            if ($loanId === '') {
                return;
            }
            $stmt = $pdo->prepare(
                "SELECT l.*, a.name AS asset_name, a.management_number
                 FROM loans l
                 LEFT JOIN assets a ON a.id = l.asset_id
                 WHERE l.id = ? AND l.status = 'overdue'"
            );
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$loan) {
                self::clearDispatch($pdo, self::EVENT_OVERDUE_LOAN, $loanId);
                return;
            }
            self::dispatch($pdo, self::EVENT_OVERDUE_LOAN, $loanId, self::formatOverdue($loan));
        } catch (\Throwable $e) {
            error_log((string) $e);
        }
    }

    public static function clearDispatch(PDO $pdo, string $event, string $entityId): void
    {
        $pdo->prepare('DELETE FROM alert_dispatches WHERE event_key = ? AND entity_id = ?')
            ->execute([$event, $entityId]);
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function formatLowStock(array $item): string
    {
        $name = (string) ($item['name'] ?? '품목');
        $qty = self::formatQty($item['qty'] ?? 0);
        $min = self::formatQty($item['min_stock'] ?? 0);
        $unit = (string) ($item['unit'] ?? 'ea');
        return "[inni] 재고 부족\n{$name}: {$qty} / 최소 {$min} {$unit}";
    }

    /**
     * @param array<string, mixed> $loan
     */
    public static function formatOverdue(array $loan): string
    {
        $name = (string) ($loan['asset_name'] ?? '품목');
        $borrower = (string) ($loan['borrower_name'] ?? '');
        $due = Support::formatWhen(isset($loan['due_at']) ? (string) $loan['due_at'] : null);
        $who = $borrower !== '' ? " · {$borrower}" : '';
        return "[inni] 연체 대여\n{$name}{$who} · 예정 {$due}";
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function formatQty(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '0';
        }
        $number = (float) $value;
        if (floor($number) === $number) {
            return (string) (int) $number;
        }
        return rtrim(rtrim(sprintf('%.4f', $number), '0'), '.');
    }

    private static function dispatch(PDO $pdo, string $event, string $entityId, string $text): bool
    {
        if (!self::eventEnabled($pdo, $event)) {
            return false;
        }
        if (!Telegram::isReady()) {
            return false;
        }
        $chatId = self::chatId($pdo);
        if ($chatId === '') {
            return false;
        }
        if (self::alreadySent($pdo, $event, $entityId)) {
            return false;
        }
        $result = Telegram::sendMessage($chatId, $text);
        if (!$result['ok']) {
            return false;
        }
        self::recordDispatch($pdo, $event, $entityId);
        return true;
    }

    private static function alreadySent(PDO $pdo, string $event, string $entityId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM alert_dispatches WHERE event_key = ? AND entity_id = ?');
        $stmt->execute([$event, $entityId]);
        return (bool) $stmt->fetchColumn();
    }

    private static function recordDispatch(PDO $pdo, string $event, string $entityId): void
    {
        $pdo->prepare(
            'INSERT INTO alert_dispatches(event_key, entity_id, sent_at) VALUES(?,?,?)
             ON CONFLICT(event_key, entity_id) DO UPDATE SET sent_at = excluded.sent_at'
        )->execute([$event, $entityId, Support::now()]);
    }

    private static function upsertSetting(PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare(
            'INSERT INTO settings(key,value) VALUES(?,?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        )->execute([$key, $value]);
    }
}
