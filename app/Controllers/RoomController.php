<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Database;
use Inni\Logger;
use Inni\Support;
use Inni\Uploader;
use Inni\View;

final class RoomController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        $rooms = $pdo->query(
            "SELECT r.*, p.name AS parent_name,
                (SELECT COUNT(*) FROM assets a WHERE a.location_id IN (
                    SELECT id FROM locations WHERE id = r.id OR parent_id = r.id
                        OR parent_id IN (SELECT id FROM locations WHERE parent_id = r.id)
                )) AS asset_count
             FROM locations r
             LEFT JOIN locations p ON p.id = r.parent_id
             WHERE r.kind = 'room'
             ORDER BY p.name, r.name"
        )->fetchAll();

        // simpler asset counts via PHP for nested depth
        foreach ($rooms as &$room) {
            $ids = Support::descendantIds($pdo, $room['id']);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE location_id IN ($in)");
            $stmt->execute($ids);
            $room['asset_count'] = (int) $stmt->fetchColumn();
        }
        unset($room);

        $buildings = $pdo->query(
            "SELECT * FROM locations WHERE kind = 'building' ORDER BY sort_order, name"
        )->fetchAll();

        View::render('rooms/index', compact('rooms', 'buildings'));
    }

    public function show(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        $id = (string) ($_GET['id'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM locations WHERE id = ?');
        $stmt->execute([$id]);
        $location = $stmt->fetch();
        if (!$location) {
            http_response_code(404);
            View::render('errors/404', [], 'layouts/bare');
            return;
        }

        $ids = Support::descendantIds($pdo, $id);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT a.* FROM assets a WHERE a.location_id IN ($in) ORDER BY a.name"
        );
        $stmt->execute($ids);
        $assets = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT s.*, c.name AS item_name, c.type, c.unit, c.min_stock
             FROM stock_lots s
             JOIN catalog_items c ON c.id = s.catalog_item_id
             WHERE s.location_id IN ($in)
             ORDER BY c.name"
        );
        $stmt->execute($ids);
        $stock = $stmt->fetchAll();

        $children = $pdo->prepare('SELECT * FROM locations WHERE parent_id = ? ORDER BY sort_order, name');
        $children->execute([$id]);
        $children = $children->fetchAll();

        $path = Support::locationPath($pdo, $id);

        $reports = $pdo->prepare(
            "SELECT * FROM reports WHERE target_type = 'room' AND target_id = ? ORDER BY created_at DESC LIMIT 10"
        );
        $reports->execute([$id]);
        $reports = $reports->fetchAll();

        View::render('rooms/show', compact('location', 'assets', 'stock', 'children', 'path', 'reports'));
    }

    public function save(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '등록 권한이 없습니다.');
            App::redirect('rooms');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            App::redirect('rooms');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $kind = (string) ($_POST['kind'] ?? 'room');
        $parentId = trim((string) ($_POST['parent_id'] ?? '')) ?: null;
        $code = trim((string) ($_POST['code'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;

        if ($name === '' || !in_array($kind, ['building', 'room', 'zone', 'storage', 'bin'], true)) {
            App::flash('error', '이름과 종류를 확인하세요.');
            App::redirect('rooms');
        }

        $id = Support::id('loc');
        $t = Support::now();
        $imagePath = null;
        try {
            if (!empty($_FILES['photo']['name'])) {
                $imagePath = Uploader::store($_FILES['photo'], 'locations');
            }
        } catch (\Throwable $e) {
            App::flash('error', $e->getMessage());
            App::redirect('rooms');
        }

        Database::pdo()->prepare(
            'INSERT INTO locations(id,name,kind,parent_id,code,notes,image_path,sort_order,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id, $name, $kind, $parentId, $code, $notes, $imagePath, 0,
            Support::qr('LOC', $id), $t, $t,
        ]);

        Logger::write('create', 'location', $id, "위치 «{$name}» 등록");
        App::flash('ok', '위치가 등록되었습니다.');
        App::redirect('rooms/show', ['id' => $id]);
    }
}
