<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
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

        $pdo = Database::pdo();

        if (preg_match('/^(AST|CAT|LOC):(.+)$/i', $code, $m)) {
            $kind = strtoupper($m[1]);
            $id = $m[2];
            if ($kind === 'AST') {
                App::redirect('assets/show', ['id' => $id]);
            }
            if ($kind === 'CAT') {
                App::redirect('items/show', ['id' => $id]);
            }
            App::redirect('rooms/show', ['id' => $id]);
        }

        $stmt = $pdo->prepare('SELECT id FROM assets WHERE management_number = ? OR qr_code = ? LIMIT 1');
        $stmt->execute([$code, $code]);
        if ($id = $stmt->fetchColumn()) {
            App::redirect('assets/show', ['id' => $id]);
        }

        $stmt = $pdo->prepare('SELECT id FROM locations WHERE code = ? OR qr_code = ? LIMIT 1');
        $stmt->execute([$code, $code]);
        if ($id = $stmt->fetchColumn()) {
            App::redirect('rooms/show', ['id' => $id]);
        }

        $stmt = $pdo->prepare('SELECT id FROM catalog_items WHERE qr_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if ($id = $stmt->fetchColumn()) {
            App::redirect('items/show', ['id' => $id]);
        }

        App::flash('error', '코드를 찾지 못했습니다: ' . $code);
        App::redirect('scan');
    }
}
