<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Alert;
use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Desk;
use Inni\Loan;
use Inni\Scan;
use Inni\View;

final class DeskController
{
    public function index(): void
    {
        $user = self::requireDeskUser();
        $pdo = Database::pdo();
        Alert::refreshOverdue($pdo);

        $q = trim((string) ($_GET['q'] ?? ''));
        $assetId = trim((string) ($_GET['id'] ?? ''));
        $asset = $assetId !== '' ? Desk::findAsset($pdo, $assetId) : null;
        $loan = $asset ? Desk::openLoanForAsset($pdo, (string) $asset['id']) : null;
        $hits = $q !== '' ? Desk::searchAssets($pdo, $q) : [];
        $borrowers = Desk::borrowers($pdo, $user);
        $dueLocal = Desk::defaultDueLocal();

        View::render('desk/index', compact(
            'user',
            'q',
            'asset',
            'loan',
            'hits',
            'borrowers',
            'dueLocal'
        ));
    }

    public function resolve(): void
    {
        self::requireDeskUser();
        Csrf::requirePost();
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '') {
            App::flash('error', '코드를 입력하세요.');
            App::redirect('loans/desk');
        }

        $hit = Scan::lookup(Database::pdo(), $code);
        if ($hit !== null && $hit['kind'] === 'asset') {
            App::redirect('loans/desk', ['id' => $hit['id']]);
        }
        if ($hit !== null) {
            App::flash('error', '대여 데스크는 장비 QR만 처리합니다. 품목·실은 스캔 탭에서 여세요.');
            App::redirect('loans/desk');
        }

        App::flash('error', '코드를 찾지 못했습니다: ' . $code);
        App::redirect('loans/desk');
    }

    public function loan(): void
    {
        $user = self::requireDeskUser();
        Csrf::requirePost();

        $assetId = trim((string) ($_POST['asset_id'] ?? ''));
        $borrowerUserId = trim((string) ($_POST['borrower_user_id'] ?? ''));
        $dueIso = Desk::dueIsoFromLocal((string) ($_POST['due_at'] ?? ''));

        try {
            Loan::checkout(
                Database::pdo(),
                $user,
                $assetId,
                '',
                null,
                null,
                $dueIso,
                $borrowerUserId !== '' ? $borrowerUserId : null,
            );
            App::flash('ok', '빌려주었습니다. 다음 장비를 스캔하세요.');
            App::redirect('loans/desk');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            App::flash('error', '대여를 저장하지 못했습니다. 잠시 후 다시 시도하세요.');
        }
        App::redirect('loans/desk', $assetId !== '' ? ['id' => $assetId] : []);
    }

    /** @return array<string, mixed> */
    private static function requireDeskUser(): array
    {
        $user = Auth::requireLogin();
        if (!Auth::canLoan($user)) {
            App::flash('error', '대여 데스크는 대여 권한이 있는 교사만 사용할 수 있습니다.');
            App::redirect('home');
        }
        return $user;
    }
}
