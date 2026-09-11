<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Loan;
use Inni\Support;
use Inni\View;

final class LoanController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pdo = Database::pdo();
        // mark overdue (ISO-8601 lexical compare)
        $pdo->prepare(
            "UPDATE loans SET status = 'overdue'
             WHERE status = 'active' AND due_at IS NOT NULL AND due_at < ?"
        )->execute([Support::now()]);

        $loans = $pdo->query(
            "SELECT l.*, a.name AS asset_name, a.management_number
             FROM loans l
             LEFT JOIN assets a ON a.id = l.asset_id
             WHERE l.status IN ('active','overdue')
             ORDER BY CASE l.status WHEN 'overdue' THEN 0 ELSE 1 END, l.due_at ASC"
        )->fetchAll();

        View::render('loans/index', compact('loans'));
    }

    public function returnLoan(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canReturn($user)) {
            App::flash('error', '반납 권한이 없습니다.');
            App::redirect('loans');
        }
        Csrf::requirePost();
        $loanId = (string) ($_POST['loan_id'] ?? '');
        try {
            $assetId = Loan::checkin(Database::pdo(), $user, $loanId);
            App::flash('ok', '반납 처리되었습니다.');
            if ($assetId) {
                App::redirect('assets/show', ['id' => $assetId]);
            }
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '반납을 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect('loans');
    }
}
