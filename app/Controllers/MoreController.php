<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Auth;
use Inni\View;

final class MoreController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        View::render('more/index', compact('user'));
    }
}
