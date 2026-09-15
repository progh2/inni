<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Alert;
use Inni\App;
use Inni\Asset;
use Inni\AssetBoard;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Desk;
use Inni\Loan;
use Inni\Logger;
use Inni\PpsUsefulLife;
use Inni\Report;
use Inni\Support;
use Inni\Uploader;
use Inni\View;

final class AssetController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        Alert::refreshOverdue($pdo);

        $filters = AssetBoard::filtersFromRequest($_GET);
        $assets = AssetBoard::list($pdo, $filters);
        $rooms = AssetBoard::rooms($pdo);
        $summary = AssetBoard::summary($pdo);

        View::render('assets/index', compact('assets', 'rooms', 'filters', 'summary'));
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
            "SELECT * FROM activity_logs
             WHERE entity_id = ?
                OR (entity_type = 'loan' AND entity_id IN (SELECT id FROM loans WHERE asset_id = ?))
                OR (entity_type = 'report' AND entity_id IN (
                    SELECT id FROM reports WHERE target_type = 'asset' AND target_id = ?
                ))
             ORDER BY created_at DESC LIMIT 20"
        );
        $logs->execute([$id, $id, $id]);
        $logs = $logs->fetchAll();

        $reports = $pdo->prepare(
            "SELECT * FROM reports WHERE target_type = 'asset' AND target_id = ? ORDER BY created_at DESC LIMIT 10"
        );
        $reports->execute([$id]);
        $reports = $reports->fetchAll();

        $lifeSuggestions = [];
        if (Auth::canWrite($user)) {
            $lifeSuggestions = PpsUsefulLife::suggest((string) ($asset['name'] ?? ''));
        }

        View::render('assets/show', compact('user', 'asset', 'path', 'locations', 'loan', 'logs', 'reports', 'lifeSuggestions'));
    }

    public function suggestLife(): void
    {
        $user = Auth::requireLogin();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!Auth::canWrite($user)) {
            http_response_code(403);
            echo json_encode([
                'error' => '제안 권한이 없습니다.',
                'suggestions' => [],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode(
            PpsUsefulLife::response($_GET['name'] ?? '', $_GET['class_number'] ?? ''),
            JSON_UNESCAPED_UNICODE
        );
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
        $borrowerUserId = trim((string) ($_POST['borrower_user_id'] ?? '')) ?: null;
        $borrowerNote = trim((string) ($_POST['borrower_note'] ?? '')) ?: null;
        $purpose = trim((string) ($_POST['purpose'] ?? '')) ?: null;
        $dueAt = trim((string) ($_POST['due_at'] ?? ''));
        $dueIso = $dueAt !== '' ? date('c', strtotime($dueAt)) : null;

        if ($borrowerName === '' && $borrowerUserId === null) {
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
                $borrowerUserId,
            );
            if ($borrowerUserId !== null) {
                Desk::rememberBorrower($borrowerUserId);
            }
            App::flash('ok', '대여 처리되었습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '대여를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        if ((string) ($_POST['return_to'] ?? '') === 'desk') {
            App::redirect('loans/desk', $assetId !== '' ? ['id' => $assetId] : []);
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
        if (!Auth::canLoan($user)) {
            App::flash('error', '수리 요청 권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();
        $assetId = (string) ($_POST['asset_id'] ?? '');
        $symptom = trim((string) ($_POST['body'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? '')) ?: null;
        if ($symptom === '') {
            App::flash('error', '증상을 입력하세요.');
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
        try {
            Report::file(Database::pdo(), $user, $assetId, $symptom, $title, $imagePath);
            App::flash('ok', '수리 요청이 접수되었습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '수리 요청을 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
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

    public function updateLife(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '수정 권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();
        $assetId = is_string($_POST['asset_id'] ?? null) ? $_POST['asset_id'] : '';
        try {
            Asset::updateLife(
                Database::pdo(),
                $user,
                $assetId,
                $_POST['purchase_date'] ?? null,
                $_POST['useful_life_years'] ?? null,
            );
            App::flash('ok', '도입일·내용연한을 저장했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '도입일·내용연한을 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
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
