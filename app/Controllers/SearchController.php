<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Auth;
use Inni\Budget;
use Inni\Database;
use Inni\Support;
use Inni\View;

final class SearchController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        $q = trim((string) ($_GET['q'] ?? ''));

        $assets = [];
        $catalog = [];
        $locations = [];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare(
                "SELECT a.*, l.name AS location_name FROM assets a
                 JOIN locations l ON l.id = a.location_id
                 WHERE a.name LIKE ? OR a.management_number LIKE ?
                    OR IFNULL(a.serial_number,'') LIKE ? OR IFNULL(a.edufine_number,'') LIKE ?
                    OR a.tags LIKE ?
                    OR " . Budget::likeSql('a') . "
                 ORDER BY a.name LIMIT 50"
            );
            $stmt->execute([$like, $like, $like, $like, $like, $like, $like]);
            $assets = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT * FROM catalog_items c
                 WHERE c.name LIKE ? OR c.tags LIKE ? OR IFNULL(c.manufacturer,'') LIKE ?
                    OR " . Budget::likeSql('c') . "
                 ORDER BY c.name LIMIT 30"
            );
            $stmt->execute([$like, $like, $like, $like, $like]);
            $catalog = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                "SELECT * FROM locations
                 WHERE name LIKE ? OR IFNULL(code,'') LIKE ?
                 ORDER BY kind, name LIMIT 30"
            );
            $stmt->execute([$like, $like]);
            $locations = $stmt->fetchAll();
        }

        View::render('search/index', compact('q', 'assets', 'catalog', 'locations'));
    }
}
