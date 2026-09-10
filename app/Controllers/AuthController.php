<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\View;

final class AuthController
{
    public function login(): void
    {
        if (Auth::user()) {
            App::redirect('home');
        }
        View::render('auth/login', [
            'google' => Auth::googleEnabled(),
            'demo' => (bool) App::config('demo_login', true),
        ], 'layouts/bare');
    }

    public function logout(): void
    {
        Auth::logout();
        App::redirect('login');
    }

    public function demo(): void
    {
        $which = $_GET['as'] ?? 'owner';
        Auth::loginDemo($which === 'teacher' ? 'teacher' : 'owner');
    }

    public function googleStart(): void
    {
        if (!Auth::googleEnabled()) {
            App::flash('error', 'Google 클라이언트 ID/시크릿을 config.php에 넣어 주세요.');
            App::redirect('login');
        }
        header('Location: ' . Auth::googleAuthUrl());
        exit;
    }

    public function googleCallback(): void
    {
        Auth::handleGoogleCallback();
    }
}
