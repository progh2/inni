<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Alert;
use Inni\Auth;
use Inni\Database;
use Inni\Inventory;
use Inni\Loan;
use Inni\Telegram;
use Inni\View;

final class HomeController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $pdo = Database::pdo();
        Alert::refreshOverdue($pdo);

        $activeLoans = $pdo->query(
            "SELECT l.*, a.name AS asset_name, a.management_number
             FROM loans l
             LEFT JOIN assets a ON a.id = l.asset_id
             WHERE l.status IN ('active','overdue')
             ORDER BY CASE l.status WHEN 'overdue' THEN 0 ELSE 1 END, l.due_at ASC
             LIMIT 8"
        )->fetchAll();

        $openReports = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();
        $assetCount = (int) $pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn();
        $roomCount = (int) $pdo->query("SELECT COUNT(*) FROM locations WHERE kind = 'room'")->fetchColumn();
        $lowStock = Alert::listLowStock($pdo, 6);
        $telegramConnectNeeded = Auth::canConfigureAlerts($user) && !Telegram::isReady();

        $recent = $pdo->query(
            'SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 8'
        )->fetchAll();

        $activeCheck = Auth::canInventory($user) ? Inventory::active($pdo) : null;
        $myLoans = Auth::canLoan($user) ? Loan::inboxForUser($pdo, (string) ($user['id'] ?? '')) : [];
        $myOverdueCount = 0;
        foreach ($myLoans as $loan) {
            if (($loan['status'] ?? '') === 'overdue') {
                $myOverdueCount++;
            }
        }

        View::render('home/index', compact(
            'user', 'activeLoans', 'openReports', 'assetCount', 'roomCount', 'lowStock', 'recent', 'activeCheck', 'telegramConnectNeeded', 'myLoans', 'myOverdueCount'
        ));
    }
}
