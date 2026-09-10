<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Auth;
use Inni\Database;
use Inni\View;

final class LabelController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        $assets = $pdo->query(
            'SELECT id, name, management_number, qr_code FROM assets ORDER BY management_number LIMIT 200'
        )->fetchAll();
        $rooms = $pdo->query(
            "SELECT id, name, code, qr_code FROM locations WHERE kind IN ('room','storage') ORDER BY name"
        )->fetchAll();
        View::render('labels/index', compact('assets', 'rooms'));
    }

    public function print(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        $ids = $_POST['asset_ids'] ?? [];
        $locIds = $_POST['location_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        if (!is_array($locIds)) {
            $locIds = [];
        }

        $labels = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT name, management_number AS code, qr_code, 'asset' AS kind FROM assets WHERE id IN ($in)");
            $stmt->execute(array_values($ids));
            $labels = array_merge($labels, $stmt->fetchAll());
        }
        if ($locIds) {
            $in = implode(',', array_fill(0, count($locIds), '?'));
            $stmt = $pdo->prepare("SELECT name, IFNULL(code,'') AS code, qr_code, 'location' AS kind FROM locations WHERE id IN ($in)");
            $stmt->execute(array_values($locIds));
            $labels = array_merge($labels, $stmt->fetchAll());
        }

        View::render('labels/print', [
            'labels' => $labels,
            'school' => View::schoolName(),
        ], 'layouts/print');
    }
}
