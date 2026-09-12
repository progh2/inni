<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Auth;
use Inni\Database;
use Inni\MaterialBoard;
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

        View::render('materials/index', compact('items', 'rooms', 'filters', 'summary'));
    }
}
