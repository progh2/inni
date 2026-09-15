<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Alert;
use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Loan;
use Inni\View;

final class LoanController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        Alert::refreshOverdue($pdo);

        $loans = $pdo->query(
            "SELECT l.*, a.name AS asset_name, a.management_number
             FROM loans l
             LEFT JOIN assets a ON a.id = l.asset_id
             WHERE l.status IN ('active','overdue')
             ORDER BY CASE l.status WHEN 'overdue' THEN 0 ELSE 1 END, l.due_at ASC"
        )->fetchAll();

        View::render('loans/index', compact('loans'));
    }

    public function mine(): void
    {
        $user = Auth::requireLogin();
        $pdo = Database::pdo();
        Alert::refreshOverdue($pdo);

        $loans = Loan::listMine($pdo, (string) ($user['id'] ?? ''));

        View::render('loans/mine', compact('user', 'loans'));
    }

    public function returnLoan(): void
    {
        $user = Auth::requireLogin();
        $after = self::returnToRoute();
        if (!Auth::canReturn($user)) {
            App::flash('error', '반납 권한이 없습니다.');
            App::redirect($after);
        }
        Csrf::requirePost();
        $loanId = (string) ($_POST['loan_id'] ?? '');
        try {
            $assetId = Loan::checkin(Database::pdo(), $user, $loanId);
            App::flash('ok', '반납 처리되었습니다.');
            if ($after === 'loans/mine') {
                App::redirect('loans/mine');
            }
            if ($assetId) {
                App::redirect('assets/show', ['id' => $assetId]);
            }
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '반납을 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect($after);
    }

    /** Allowlisted post-return landing. Default stays the school-wide list. */
    private static function returnToRoute(): string
    {
        return (string) ($_POST['return_to'] ?? '') === 'mine' ? 'loans/mine' : 'loans';
    }
}
