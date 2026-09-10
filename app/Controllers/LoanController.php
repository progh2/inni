<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Database;
use Inni\Logger;
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
        if (!Auth::canLoan($user)) {
            App::flash('error', '권한이 없습니다.');
            App::redirect('loans');
        }
        $loanId = (string) ($_POST['loan_id'] ?? '');
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id = ?');
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();
        if (!$loan || $loan['status'] === 'returned') {
            App::redirect('loans');
        }

        $t = Support::now();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE loans SET status = ?, returned_at = ? WHERE id = ?')
                ->execute(['returned', $t, $loanId]);
            if ($loan['asset_id']) {
                $pdo->prepare('UPDATE assets SET status = ?, updated_at = ? WHERE id = ?')
                    ->execute(['available', $t, $loan['asset_id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            App::flash('error', $e->getMessage());
            App::redirect('loans');
        }

        Logger::write('return', 'loan', $loanId, '대여 반납');
        App::flash('ok', '반납 처리되었습니다.');
        if ($loan['asset_id']) {
            App::redirect('assets/show', ['id' => $loan['asset_id']]);
        }
        App::redirect('loans');
    }
}
