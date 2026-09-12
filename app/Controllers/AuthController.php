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
            'googleStatus' => Auth::googleConfigStatus(),
            'googleRedirectUri' => Auth::googleRedirectUri(),
            'demo' => Auth::isDemoLoginEnabled(),
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
            $message = Auth::googleConfigStatus() === Auth::GOOGLE_STATUS_PARTIAL
                ? 'Google 클라이언트가 불완전합니다. client_id와 client_secret을 모두 설정하세요.'
                : 'Google 클라이언트 ID/시크릿이 비어 있습니다. config.php에 넣어 주세요.';
            App::flash('error', $message);
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
