<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\CatalogCsv;
use Inni\Csrf;
use Inni\Database;
use Inni\Inventory;
use Inni\InventoryBudget;
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
        $adjustments = Inventory::adjustmentsForLines(
            $pdo,
            array_map(static fn(array $line): string => (string) ($line['id'] ?? ''), $unchecked),
        );
        View::render('inventory/result', compact('user', 'check', 'unchecked', 'lines', 'adjustments'));
    }

    public function adjust(): void
    {
        $user = $this->requireManager();
        if (!Auth::canWrite($user)) {
            App::flash('error', '보정 권한이 없습니다.');
            App::redirect('more');
        }
        Csrf::requirePost();
        $lineId = trim((string) ($_POST['line_id'] ?? ''));
        $checkId = trim((string) ($_POST['check_id'] ?? ''));
        try {
            $row = Inventory::adjust(
                Database::pdo(),
                $user,
                $lineId,
                $_POST['physical_qty'] ?? '',
                (string) ($_POST['reason'] ?? ''),
                (string) ($_POST['approver_name'] ?? ''),
            );
            $checkId = (string) ($row['check_id'] ?? $checkId);
            App::flash('ok', '장부 보정을 기록했습니다.');
        } catch (InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '보정을 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        $this->redirectAfterAdjust($checkId);
    }

    public function report(): void
    {
        $user = $this->requireManager();
        $pdo = Database::pdo();
        $filters = InventoryBudget::filtersFromRequest($_GET);
        $checks = InventoryBudget::checks($pdo);
        $aggregates = InventoryBudget::aggregates($pdo, $filters);
        $lines = InventoryBudget::lines($pdo, $filters);
        $summary = InventoryBudget::summary($pdo, $filters);
        $adjustments = Inventory::adjustmentsForLines(
            $pdo,
            array_map(static fn(array $line): string => (string) ($line['id'] ?? ''), $lines),
        );
        View::render('inventory/report', compact('user', 'filters', 'checks', 'aggregates', 'lines', 'summary', 'adjustments'));
    }

    public function reportCsv(): void
    {
        $this->requireManager();
        $filters = InventoryBudget::filtersFromRequest($_GET);
        $csv = InventoryBudget::csv(Database::pdo(), $filters);
        $this->sendCsv('inni-inventory-budget-' . gmdate('Ymd') . '.csv', $csv);
    }

    private function redirectAfterAdjust(string $checkId): never
    {
        $returnTo = trim((string) ($_POST['return_to'] ?? 'result'));
        if ($returnTo === 'report') {
            App::redirect('inventory/report', InventoryBudget::query(InventoryBudget::filtersFromRequest($_POST)));
        }
        if ($checkId === '') {
            App::redirect('inventory');
        }
        App::redirect('inventory/result', ['id' => $checkId]);
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
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'inni-inventory-budget.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo CatalogCsv::BOM . $csv;
        exit;
    }
}
