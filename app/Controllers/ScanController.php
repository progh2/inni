<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Scan;
use Inni\View;

final class ScanController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('scan/index');
    }

    public function resolve(): void
    {
        Auth::requireLogin();
        Csrf::requirePost();
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '') {
            App::flash('error', '코드를 입력하세요.');
            App::redirect('scan');
        }

        $hit = Scan::lookup(Database::pdo(), $code);
        if ($hit !== null) {
            if ($hit['kind'] === 'asset') {
                App::redirect('assets/show', ['id' => $hit['id']]);
            }
            if ($hit['kind'] === 'catalog') {
                App::redirect('items/show', ['id' => $hit['id']]);
            }
            App::redirect('rooms/show', ['id' => $hit['id']]);
        }

        App::flash('error', '코드를 찾지 못했습니다: ' . $code);
        App::redirect('scan');
    }
}
