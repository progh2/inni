<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Report;
use Inni\View;

final class ReportController
{
    public function index(): void
    {
        Auth::requireLogin();
        $status = is_string($_GET['status'] ?? null) ? trim($_GET['status']) : 'queue';
        if ($status !== 'queue' && $status !== 'all' && !Report::isValidStatus($status)) {
            $status = 'queue';
        }
        $reports = Report::queue(Database::pdo(), $status);
        View::render('reports/index', compact('reports', 'status'));
    }

    public function show(): void
    {
        $user = Auth::requireLogin();
        $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
        $report = Report::find(Database::pdo(), $id);
        if (!$report) {
            http_response_code(404);
            View::render('errors/404', [], 'layouts/bare');
            return;
        }
        $history = Report::history(Database::pdo(), (string) $report['id']);
        $nextStatuses = Report::allowedNext((string) $report['status']);
        View::render('reports/show', compact('user', 'report', 'history', 'nextStatuses'));
    }

    public function updateStatus(): void
    {
        $user = Auth::requireLogin();
        $reportId = is_string($_POST['report_id'] ?? null) ? $_POST['report_id'] : '';
        if (!Report::canTransition($user)) {
            App::flash('error', '수리 상태를 바꿀 권한이 없습니다.');
            App::redirect('reports/show', ['id' => $reportId]);
        }
        Csrf::requirePost();
        $to = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        $note = is_string($_POST['note'] ?? null) ? $_POST['note'] : null;
        try {
            Report::transition(Database::pdo(), $user, $reportId, $to, $note);
            App::flash('ok', '수리 상태를 변경했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '수리 상태를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect('reports/show', ['id' => $reportId]);
    }
}
