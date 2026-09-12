<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Catalog;
use Inni\Csrf;
use Inni\Database;
use Inni\Logger;
use Inni\Support;
use Inni\Stock;
use Inni\Uploader;
use Inni\View;

final class ItemController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = Catalog::listFilters($_GET);
        $items = Catalog::list(Database::pdo(), $filters);
        View::render('items/index', [
            'items' => $items,
            'type' => $filters['type'] ?? '',
            'lowStock' => $filters['low_stock'],
        ]);
    }

    public function createForm(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '등록 권한이 없습니다.');
            App::redirect('home');
        }
        $locations = Database::pdo()->query(
            "SELECT * FROM locations ORDER BY kind, name"
        )->fetchAll();
        View::render('items/new', compact('locations'));
    }

    public function save(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '등록 권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();

        $name = trim((string) ($_POST['name'] ?? ''));
        $type = (string) ($_POST['type'] ?? 'equipment');
        $locationId = (string) ($_POST['location_id'] ?? '');
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $mgmt = trim((string) ($_POST['management_number'] ?? ''));
        $manufacturer = trim((string) ($_POST['manufacturer'] ?? '')) ?: null;
        $edufine = trim((string) ($_POST['edufine_number'] ?? '')) ?: null;
        $unit = trim((string) ($_POST['unit'] ?? 'ea')) ?: 'ea';
        $minStock = $_POST['min_stock'] !== '' ? (float) $_POST['min_stock'] : null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['tags'] ?? '')))));

        if ($name === '' || $locationId === '' || !in_array($type, ['equipment', 'fixture', 'consumable', 'part'], true)) {
            App::flash('error', '필수 항목을 확인하세요.');
            App::redirect('items/new');
        }

        $pdo = Database::pdo();
        $t = Support::now();
        $catalogId = Support::id('ci');
        $imagePath = null;
        try {
            if (!empty($_FILES['photo']['name'])) {
                $imagePath = Uploader::store($_FILES['photo'], 'items');
            }
        } catch (\Throwable $e) {
            App::flash('error', $e->getMessage());
            App::redirect('items/new');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO catalog_items(id,name,type,description,tags,unit,min_stock,edufine_number,manufacturer,image_path,qr_code,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $catalogId, $name, $type, $notes,
                json_encode($tags, JSON_UNESCAPED_UNICODE),
                $unit, $minStock, $edufine, $manufacturer, $imagePath,
                Support::qr('CAT', $catalogId), $t, $t,
            ]);

            $firstAssetId = null;
            if ($type === 'equipment') {
                for ($i = 0; $i < $quantity; $i++) {
                    $aid = Support::id('ast');
                    if ($i === 0) {
                        $firstAssetId = $aid;
                    }
                    $number = $mgmt !== ''
                        ? ($quantity === 1 ? $mgmt : $mgmt . '-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT))
                        : 'MGMT-' . strtoupper(substr($aid, -8));
                    $aname = $quantity === 1 ? $name : $name . ' #' . ($i + 1);
                    $pdo->prepare(
                        'INSERT INTO assets(id,catalog_item_id,name,management_number,edufine_number,status,location_id,tags,image_path,notes,qr_code,created_at,updated_at)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $aid, $catalogId, $aname, $number, $edufine, 'available', $locationId,
                        json_encode($tags, JSON_UNESCAPED_UNICODE), $imagePath, $notes,
                        Support::qr('AST', $aid), $t, $t,
                    ]);
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES(?,?,?,?,?)'
                )->execute([Support::id('lot'), $catalogId, $locationId, $quantity, $t]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            App::flash('error', '저장 실패: ' . $e->getMessage());
            App::redirect('items/new');
        }

        Logger::write('create', 'catalog', $catalogId, "«{$name}» 등록");
        App::flash('ok', '등록되었습니다.');
        if ($firstAssetId) {
            App::redirect('assets/show', ['id' => $firstAssetId]);
        }
        App::redirect('items/show', ['id' => $catalogId]);
    }

    public function show(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        $id = (string) ($_GET['id'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM catalog_items WHERE id = ?');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) {
            http_response_code(404);
            View::render('errors/404', [], 'layouts/bare');
            return;
        }

        $assets = $pdo->prepare(
            'SELECT a.*, l.name AS location_name FROM assets a
             JOIN locations l ON l.id = a.location_id
             WHERE a.catalog_item_id = ? ORDER BY a.management_number'
        );
        $assets->execute([$id]);
        $assets = $assets->fetchAll();

        $lots = $pdo->prepare(
            'SELECT s.*, l.name AS location_name FROM stock_lots s
             JOIN locations l ON l.id = s.location_id
             WHERE s.catalog_item_id = ?'
        );
        $lots->execute([$id]);
        $lots = $lots->fetchAll();

        $logs = $pdo->prepare(
            "SELECT * FROM activity_logs WHERE entity_type = 'catalog' AND entity_id = ? ORDER BY created_at DESC, rowid DESC LIMIT 20"
        );
        $logs->execute([$id]);
        $logs = $logs->fetchAll();

        $cancels = [];
        $cancelStmt = $pdo->prepare(
            'SELECT * FROM stock_issue_cancels WHERE catalog_item_id = ?'
        );
        $cancelStmt->execute([$id]);
        foreach ($cancelStmt->fetchAll() as $cancel) {
            $cancels[(string) $cancel['issue_log_id']] = $cancel;
        }

        $locations = [];
        $openLocations = [];
        if (in_array((string) $item['type'], ['fixture', 'consumable', 'part'], true)) {
            $locations = $pdo->query(
                'SELECT * FROM locations ORDER BY kind, name'
            )->fetchAll();
            $occupied = [];
            foreach ($lots as $lot) {
                $occupied[(string) $lot['location_id']] = true;
            }
            foreach ($locations as $location) {
                if (!isset($occupied[(string) $location['id']])) {
                    $openLocations[] = $location;
                }
            }
        }

        View::render('items/show', compact('item', 'assets', 'lots', 'logs', 'cancels', 'locations', 'openLocations'));
    }

    public function editForm(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '수정 권한이 없습니다.');
            App::redirect('home');
        }
        $id = (string) ($_GET['id'] ?? '');
        $stmt = Database::pdo()->prepare('SELECT * FROM catalog_items WHERE id = ?');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) {
            http_response_code(404);
            View::render('errors/404', [], 'layouts/bare');
            return;
        }
        View::render('items/edit', compact('item'));
    }

    public function update(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '수정 권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();

        $itemId = is_string($_POST['item_id'] ?? null) ? $_POST['item_id'] : '';
        $name = trim((string) ($_POST['name'] ?? ''));
        $manufacturer = trim((string) ($_POST['manufacturer'] ?? '')) ?: null;
        $edufine = trim((string) ($_POST['edufine_number'] ?? '')) ?: null;
        $unit = trim((string) ($_POST['unit'] ?? 'ea')) ?: 'ea';
        $minStock = array_key_exists('min_stock', $_POST) ? $_POST['min_stock'] : '';
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['tags'] ?? '')))));
        $favorite = !empty($_POST['favorite']);

        $imagePath = null;
        try {
            if (!empty($_FILES['photo']['name'])) {
                $imagePath = Uploader::store($_FILES['photo'], 'items');
            }
        } catch (\Throwable $e) {
            App::flash('error', $e->getMessage());
            App::redirect('items/edit', ['id' => $itemId]);
        }

        try {
            Catalog::update(
                Database::pdo(),
                $user,
                $itemId,
                $name,
                $notes,
                $tags,
                $unit,
                $minStock,
                $edufine,
                $manufacturer,
                $imagePath,
                $favorite,
            );
            App::flash('ok', '품목을 수정했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
            App::redirect('items/edit', ['id' => $itemId]);
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '품목을 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
            App::redirect('items/edit', ['id' => $itemId]);
        }
        App::redirect('items/show', ['id' => $itemId]);
    }

    public function issue(): void
    {
        $user = Auth::requireLogin();
        Csrf::requirePost();
        if (!Auth::canLoan($user)) {
            http_response_code(403);
            echo '출고 권한이 없습니다.';
            return;
        }

        $itemId = is_string($_POST['item_id'] ?? null) ? $_POST['item_id'] : '';
        try {
            Stock::issue(
                Database::pdo(), $user, $itemId,
                is_string($_POST['lot_id'] ?? null) ? $_POST['lot_id'] : '',
                $_POST['quantity'] ?? null,
                is_string($_POST['purpose'] ?? null) ? $_POST['purpose'] : '',
            );
            App::flash('ok', '사용 출고를 처리했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '출고를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect('items/show', ['id' => $itemId]);
    }

    public function restock(): void
    {
        $user = Auth::requireLogin();
        Csrf::requirePost();
        if (!Auth::canWrite($user)) {
            http_response_code(403);
            echo '재입고 권한이 없습니다.';
            return;
        }

        $itemId = is_string($_POST['item_id'] ?? null) ? $_POST['item_id'] : '';
        try {
            Stock::restock(
                Database::pdo(),
                $user,
                $itemId,
                is_string($_POST['location_id'] ?? null) ? $_POST['location_id'] : '',
                $_POST['quantity'] ?? null,
                is_string($_POST['note'] ?? null) ? $_POST['note'] : '',
            );
            App::flash('ok', '재입고를 처리했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '재입고를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect('items/show', ['id' => $itemId]);
    }

    public function cancelIssue(): void
    {
        $user = Auth::requireLogin();
        Csrf::requirePost();
        if (!Auth::canLoan($user)) {
            http_response_code(403);
            echo '출고 취소 권한이 없습니다.';
            return;
        }

        $itemId = is_string($_POST['item_id'] ?? null) ? $_POST['item_id'] : '';
        try {
            Stock::cancelIssue(
                Database::pdo(),
                $user,
                $itemId,
                is_string($_POST['issue_log_id'] ?? null) ? $_POST['issue_log_id'] : '',
                is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '',
            );
            App::flash('ok', '출고를 취소했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '출고 취소를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect('items/show', ['id' => $itemId]);
    }
}
