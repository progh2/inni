<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Auth;
use Inni\Database;
use Inni\Inventory;
use Inni\Support;
use Inni\View;

final class HomeController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $pdo = Database::pdo();

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
        $lowStock = $pdo->query(
            "SELECT c.name, c.unit, c.min_stock, COALESCE(SUM(s.quantity),0) AS qty
             FROM catalog_items c
             LEFT JOIN stock_lots s ON s.catalog_item_id = c.id
             WHERE c.type IN ('consumable','part') AND c.min_stock IS NOT NULL
             GROUP BY c.id
             HAVING qty < c.min_stock
             LIMIT 6"
        )->fetchAll();

        $recent = $pdo->query(
            'SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 8'
        )->fetchAll();

        $activeCheck = Auth::canInventory($user) ? Inventory::active($pdo) : null;

        View::render('home/index', compact(
            'user', 'activeLoans', 'openReports', 'assetCount', 'roomCount', 'lowStock', 'recent', 'activeCheck'
        ));
    }
}
