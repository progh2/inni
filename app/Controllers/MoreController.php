<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Ai;
use Inni\Auth;
use Inni\View;

final class MoreController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $aiReady = Auth::canConfigureAlerts($user) && Ai::isReady();
        View::render('more/index', compact('user', 'aiReady'));
    }
}
