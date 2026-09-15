<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Alert;
use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Desk;
use Inni\View;

final class DeskController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canLoan($user)) {
            App::flash('error', '대여 데스크 권한이 없습니다.');
            App::redirect('home');
        }

        $pdo = Database::pdo();
        Alert::refreshOverdue($pdo);

        $q = trim((string) ($_GET['q'] ?? ''));
        $id = trim((string) ($_GET['id'] ?? ''));
        $asset = null;
        $loan = null;
        $hits = [];

        if ($id !== '') {
            $asset = Desk::assetCard($pdo, $id);
            if ($asset === null) {
                App::flash('error', '장비를 찾지 못했습니다.');
                App::redirect('loans/desk');
            }
            if (($asset['status'] ?? '') === 'on_loan') {
                $loan = Desk::openLoan($pdo, (string) $asset['id']);
            }
        } elseif ($q !== '') {
            $hits = Desk::searchAssets($pdo, $q);
            if (count($hits) === 1) {
                App::redirect('loans/desk', ['id' => $hits[0]['id']]);
            }
        }

        $teachers = Desk::teachers($pdo);
        $defaultDue = Desk::defaultDueLocal();
        $selectedBorrower = Desk::rememberedBorrower() ?? (string) ($user['id'] ?? '');

        View::render('loans/desk', compact(
            'user',
            'asset',
            'loan',
            'hits',
            'q',
            'teachers',
            'defaultDue',
            'selectedBorrower',
        ));
    }

    public function resolve(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canLoan($user)) {
            App::flash('error', '대여 데스크 권한이 없습니다.');
            App::redirect('home');
        }
        Csrf::requirePost();

        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '') {
            App::flash('error', '코드를 입력하세요.');
            App::redirect('loans/desk');
        }

        $asset = Desk::resolveAsset(Database::pdo(), $code);
        if ($asset === null) {
            App::flash('error', '장비 코드를 찾지 못했습니다: ' . $code);
            App::redirect('loans/desk');
        }

        App::redirect('loans/desk', ['id' => $asset['id']]);
    }
}
