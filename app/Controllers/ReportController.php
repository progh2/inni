<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Report;
use Inni\ReportCost;
use Inni\Support;
use Inni\View;

final class ReportController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '수리 대기 권한이 없습니다.');
            App::redirect('home');
        }
        $pdo = Database::pdo();
        $filters = Report::filterFromRequest($_GET);
        $reports = Report::queue($pdo, $filters);
        $counts = Report::counts($pdo);

        View::render('reports/index', compact('user', 'reports', 'filters', 'counts'));
    }

    public function show(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '수리 대기 권한이 없습니다.');
            App::redirect('home');
        }
        $pdo = Database::pdo();
        $id = (string) ($_GET['id'] ?? '');
        $report = Report::find($pdo, $id);
        if (!$report) {
            http_response_code(404);
            View::render('errors/404', [], 'layouts/bare');
            return;
        }
        $logs = Report::logs($pdo, (string) $report['id']);
        $path = '';
        if (!empty($report['location_id'])) {
            $path = Support::locationPath($pdo, (string) $report['location_id']);
        }

        View::render('reports/show', compact('user', 'report', 'logs', 'path'));
    }

    public function costs(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canWrite($user)) {
            App::flash('error', '수리비 합계 권한이 없습니다.');
            App::redirect('home');
        }
        $pdo = Database::pdo();
        $filters = ReportCost::filtersFromRequest($_GET);
        $year = $filters['year'] ?? ReportCost::currentYear();
        $month = $filters['month'];
        $period = ['year' => $year, 'month' => $month];
        $summary = ReportCost::totals($pdo, $period);
        $months = ReportCost::monthly($pdo, $year);
        $years = ReportCost::yearly($pdo);
        $cases = ReportCost::cases($pdo, $period);

        View::render('reports/costs', compact('user', 'filters', 'year', 'month', 'summary', 'months', 'years', 'cases'));
    }

    public function updateCost(): void
    {
        $user = Auth::requireLogin();
        $reportId = (string) ($_POST['report_id'] ?? '');
        $after = $reportId !== ''
            ? ['route' => 'reports/show', 'query' => ['id' => $reportId]]
            : ['route' => 'reports', 'query' => []];
        if (!Auth::canWrite($user)) {
            App::flash('error', '수리비 기록 권한이 없습니다.');
            App::redirect($after['route'], $after['query']);
        }
        Csrf::requirePost();
        try {
            ReportCost::save(
                Database::pdo(),
                $user,
                $reportId,
                $_POST['cost_amount'] ?? null,
                $_POST['cost_vendor'] ?? null,
                $_POST['cost_budget_line'] ?? null,
                $_POST['cost_at'] ?? null,
            );
            App::flash('ok', '수리비를 저장했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '수리비를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect($after['route'], $after['query']);
    }

    public function updateStatus(): void
    {
        $user = Auth::requireLogin();
        $after = self::returnTo();
        if (!Auth::canWrite($user)) {
            App::flash('error', '수리 상태 변경 권한이 없습니다.');
            App::redirect($after['route'], $after['query']);
        }
        Csrf::requirePost();
        $reportId = (string) ($_POST['report_id'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            Report::transition(Database::pdo(), $user, $reportId, $status, $note);
            App::flash('ok', '수리 상태를 변경했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '수리 상태를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect($after['route'], $after['query']);
    }

    /**
     * Allowlisted post-transition landing. Default is the manager queue.
     *
     * @return array{route: string, query: array<string, string>}
     */
    private static function returnTo(): array
    {
        $to = (string) ($_POST['return_to'] ?? '');
        if ($to === 'show') {
            $id = (string) ($_POST['report_id'] ?? '');
            return $id !== ''
                ? ['route' => 'reports/show', 'query' => ['id' => $id]]
                : ['route' => 'reports', 'query' => []];
        }
        if ($to === 'asset') {
            $assetId = (string) ($_POST['asset_id'] ?? '');
            return $assetId !== ''
                ? ['route' => 'assets/show', 'query' => ['id' => $assetId]]
                : ['route' => 'reports', 'query' => []];
        }
        return ['route' => 'reports', 'query' => []];
    }
}
