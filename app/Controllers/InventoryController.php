<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\CatalogCsv;
use Inni\Csrf;
use Inni\Database;
use Inni\Inventory;
use Inni\InventoryReport;
use Inni\View;
use InvalidArgumentException;

final class InventoryController
{
    public function index(): void
    {
        $user = $this->requireManager();
        $pdo = Database::pdo();
        $active = Inventory::active($pdo);
        if ($active) {
            App::redirect('inventory/show');
        }

        $rooms = $pdo->query(
            "SELECT r.*, p.name AS parent_name
             FROM locations r
             LEFT JOIN locations p ON p.id = r.parent_id
             WHERE r.kind = 'room'
             ORDER BY p.name, r.name"
        )->fetchAll();

        View::render('inventory/index', compact('user', 'rooms'));
    }

    public function start(): void
    {
        $user = $this->requireManager();
        Csrf::requirePost();
        try {
            Inventory::start(Database::pdo(), $user, (string) ($_POST['location_id'] ?? ''));
        } catch (InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
            App::redirect('inventory');
        }
        App::flash('ok', '실사를 시작했습니다. 스캔하거나 코드를 입력해 확인하세요.');
        App::redirect('inventory/show');
    }

    public function show(): void
    {
        $user = $this->requireManager();
        $pdo = Database::pdo();
        $check = Inventory::active($pdo);
        if (!$check) {
            App::flash('error', '진행 중인 실사가 없습니다.');
            App::redirect('inventory');
        }
        $lines = Inventory::lines($pdo, (string) $check['id']);
        $confirmed = 0;
        foreach ($lines as $line) {
            if (($line['confirmed_at'] ?? null) !== null) {
                $confirmed++;
            }
        }
        View::render('inventory/show', compact('user', 'check', 'lines', 'confirmed'));
    }

    public function confirm(): void
    {
        $user = $this->requireManager();
        Csrf::requirePost();
        try {
            $line = Inventory::confirm(Database::pdo(), $user, (string) ($_POST['code'] ?? ''));
            App::flash('ok', '확인: ' . (string) $line['name']);
        } catch (InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        }
        App::redirect('inventory/show');
    }

    public function finish(): void
    {
        $user = $this->requireManager();
        Csrf::requirePost();
        $pdo = Database::pdo();
        $checkId = trim((string) ($_POST['check_id'] ?? ''));
        if ($checkId === '') {
            $active = Inventory::active($pdo);
            $checkId = $active['id'] ?? '';
        }
        try {
            $check = Inventory::finish($pdo, $user, (string) $checkId);
        } catch (InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
            App::redirect('inventory');
        }
        App::redirect('inventory/result', ['id' => $check['id']]);
    }

    public function result(): void
    {
        $user = $this->requireManager();
        $pdo = Database::pdo();
        $id = (string) ($_GET['id'] ?? '');
        $check = Inventory::get($pdo, $id);
        if (!$check) {
            App::flash('error', '실사 결과를 찾지 못했습니다.');
            App::redirect('inventory');
        }
        if (($check['status'] ?? '') === 'active') {
            App::redirect('inventory/show');
        }
        $unchecked = Inventory::unchecked($pdo, (string) $check['id']);
        $lines = Inventory::lines($pdo, (string) $check['id']);
        View::render('inventory/result', compact('user', 'check', 'unchecked', 'lines'));
    }

    public function report(): void
    {
        $user = $this->requireManager();
        $pdo = Database::pdo();
        $filters = InventoryReport::filtersFromRequest($_GET);
        $sessionOptions = InventoryReport::sessionOptions($pdo);
        $sessions = InventoryReport::sessions($pdo, $filters);
        $aggregates = InventoryReport::aggregates($pdo, $filters);
        $differences = InventoryReport::differences($pdo, $filters);
        $exportQuery = InventoryReport::queryParams($filters);
        View::render(
            'inventory/report',
            compact('user', 'filters', 'sessionOptions', 'sessions', 'aggregates', 'differences', 'exportQuery')
        );
    }

    public function export(): void
    {
        $this->requireManager();
        $filters = InventoryReport::filtersFromRequest($_GET);
        $exported = InventoryReport::export(Database::pdo(), $filters);
        $stamp = gmdate('Ymd');
        $this->sendCsv("inni-inventory-diff-{$stamp}.csv", $exported['csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireManager(): array
    {
        $user = Auth::requireLogin();
        if (!Auth::canInventory($user)) {
            App::flash('error', '실사 권한이 없습니다. 담당교사만 진행할 수 있습니다.');
            App::redirect('more');
        }
        return $user;
    }

    private function sendCsv(string $filename, string $csv): never
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'inni-inventory-diff.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo CatalogCsv::BOM . $csv;
        exit;
    }
}
