<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Auth;
use Inni\Database;
use Inni\MaterialBoard;
use Inni\Stock;
use Inni\View;

final class MaterialController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();

        $filters = MaterialBoard::filtersFromRequest($_GET);
        $items = MaterialBoard::list($pdo, $filters);
        $rooms = MaterialBoard::rooms($pdo);
        $summary = MaterialBoard::summary($pdo);
        $issueRoom = Stock::issueRoomFromRequest($_GET, 'issue_room');
        $issueLogs = Stock::issueHistory($pdo, $issueRoom);
        $shortage = MaterialBoard::list($pdo, ['low_stock' => true]);
        $restockWait = MaterialBoard::restockWait($pdo);

        View::render('materials/index', compact(
            'items',
            'rooms',
            'filters',
            'summary',
            'issueLogs',
            'issueRoom',
            'shortage',
            'restockWait',
        ));
    }
}
