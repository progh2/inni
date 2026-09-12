<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Asset;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Loan;
use Inni\Logger;
use Inni\Support;
use Inni\Uploader;
use Inni\View;

final class AssetController
{
    /** GET stub — 현황 보드·필터는 #30에서 채운다. */
    public function index(): void
    {
        Auth::requireLogin();
        View::render('assets/index');
    }

    public function show(): void
    {
        $user = Auth::requireLogin();
        $pdo = Database::pdo();
        $id = (string) ($_GET['id'] ?? '');
        $stmt = $pdo->prepare(
            'SELECT a.*, c.type AS item_type, c.manufacturer,
                    c.budget_program AS catalog_budget_program,
                    c.budget_year AS catalog_budget_year,
                    l.name AS location_name
             FROM assets a
             JOIN catalog_items c ON c.id = a.catalog_item_id
             JOIN locations l ON l.id = a.location_id
             WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        $asset = $stmt->fetch();
        if (!$asset) {
            http_response_code(404);
            View::render('errors/404', [], 'layouts/bare');
            return;
        }

        $path = Support::locationPath($pdo, $asset['location_id']);
        $locations = $pdo->query('SELECT id, name, kind FROM locations ORDER BY kind, name')->fetchAll();

        $loan = null;
        if ($asset['status'] === 'on_loan') {
            $ls = $pdo->prepare(
                "SELECT * FROM loans WHERE asset_id = ? AND status IN ('active','overdue') ORDER BY created_at DESC LIMIT 1"
            );
            $ls->execute([$id]);
            $loan = $ls->fetch() ?: null;
        }

        $logs = $pdo->prepare(
            'SELECT * FROM activity_logs WHERE entity_id = ? OR (entity_type = ? AND entity_id IN (SELECT id FROM loans WHERE asset_id = ?))
             ORDER BY created_at DESC LIMIT 20'
        );
        $logs->execute([$id, 'loan', $id]);
        $logs = $logs->fetchAll();

        $reports = $pdo->prepare(
            "SELECT * FROM reports WHERE target_type = 'asset' AND target_id = ? ORDER BY created_at DESC LIMIT 10"
        );
        $reports->execute([$id]);
        $reports = $reports->fetchAll();

        View::render('assets/show', compact('user', 'asset', 'path', 'locations', 'loan', 'logs', 'reports'));
    }

    public function loan(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canLoan($user)) {
            App::flash('error', '대여 권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();

        $assetId = (string) ($_POST['asset_id'] ?? '');
        $borrowerName = trim((string) ($_POST['borrower_name'] ?? ''));
        $borrowerNote = trim((string) ($_POST['borrower_note'] ?? '')) ?: null;
        $purpose = trim((string) ($_POST['purpose'] ?? '')) ?: null;
        $dueAt = trim((string) ($_POST['due_at'] ?? ''));
        $dueIso = $dueAt !== '' ? date('c', strtotime($dueAt)) : null;

        if ($borrowerName === '') {
            $borrowerName = $user['display_name'];
        }

        try {
            Loan::checkout(
                Database::pdo(),
                $user,
                $assetId,
                $borrowerName,
                $borrowerNote,
                $purpose,
                $dueIso,
            );
            App::flash('ok', '대여 처리되었습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '대여를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect('assets/show', ['id' => $assetId]);
    }

    public function move(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user) && !Auth::canLoan($user)) {
            App::flash('error', '권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();
        $assetId = (string) ($_POST['asset_id'] ?? '');
        $locationId = (string) ($_POST['location_id'] ?? '');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch();
        if (!$asset) {
            App::redirect('home');
        }
        $from = Support::locationPath($pdo, $asset['location_id']);
        $to = Support::locationPath($pdo, $locationId);
        $pdo->prepare('UPDATE assets SET location_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$locationId, Support::now(), $assetId]);
        Logger::write('move', 'asset', $assetId, "«{$asset['name']}» 이동: {$from} → {$to}", [
            'from' => $asset['location_id'],
            'to' => $locationId,
        ]);
        App::flash('ok', '위치를 변경했습니다.');
        App::redirect('assets/show', ['id' => $assetId]);
    }

    public function report(): void
    {
        $user = Auth::requireLogin();
        Csrf::requirePost();
        $assetId = (string) ($_POST['asset_id'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || $body === '') {
            App::flash('error', '제목과 내용을 입력하세요.');
            App::redirect('assets/show', ['id' => $assetId]);
        }
        $imagePath = null;
        try {
            if (!empty($_FILES['photo']['name'])) {
                $imagePath = Uploader::store($_FILES['photo'], 'reports');
            }
        } catch (\Throwable $e) {
            App::flash('error', $e->getMessage());
            App::redirect('assets/show', ['id' => $assetId]);
        }
        $id = Support::id('rep');
        $t = Support::now();
        Database::pdo()->prepare(
            'INSERT INTO reports(id,target_type,target_id,reporter_user_id,reporter_name,title,body,image_path,status,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id, 'asset', $assetId, $user['id'], $user['display_name'], $title, $body,
            $imagePath, 'open', $t, $t,
        ]);
        Logger::write('report', 'report', $id, "고장 신고: {$title}");
        App::flash('ok', '신고가 접수되었습니다.');
        App::redirect('assets/show', ['id' => $assetId]);
    }

    public function updateBudget(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '수정 권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();
        $assetId = is_string($_POST['asset_id'] ?? null) ? $_POST['asset_id'] : '';
        try {
            Asset::updateBudget(
                Database::pdo(),
                $user,
                $assetId,
                $_POST['budget_program'] ?? null,
                $_POST['budget_year'] ?? null,
            );
            App::flash('ok', '구입 사업예산을 저장했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '구입 사업예산을 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect('assets/show', ['id' => $assetId]);
    }

    public function photo(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();
        $assetId = (string) ($_POST['asset_id'] ?? '');
        try {
            $path = Uploader::store($_FILES['photo'] ?? [], 'assets');
            if (!$path) {
                throw new \RuntimeException('사진을 선택하세요.');
            }
            Database::pdo()->prepare('UPDATE assets SET image_path = ?, updated_at = ? WHERE id = ?')
                ->execute([$path, Support::now(), $assetId]);
            Logger::write('update', 'asset', $assetId, '사진 업로드');
            App::flash('ok', '사진을 저장했습니다.');
        } catch (\Throwable $e) {
            App::flash('error', $e->getMessage());
        }
        App::redirect('assets/show', ['id' => $assetId]);
    }
}
