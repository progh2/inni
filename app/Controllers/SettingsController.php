<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Support;
use Inni\View;

final class SettingsController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::isOwner($user)) {
            App::flash('error', '관리자만 설정할 수 있습니다.');
            App::redirect('more');
        }
        $school = View::schoolName();
        $google = Auth::googleEnabled();
        $googleStatus = Auth::googleConfigStatus();
        $googleRedirectUri = Auth::googleRedirectUri();
        $allowedDomains = Auth::normalizedAllowedDomains();
        $baseUrlConfigured = App::normalizeConfiguredBaseUrl((string) App::config('base_url', '')) !== '';
        $redirectUriOverride = trim((string) App::config('google.redirect_uri', '')) !== '';
        $demoLogin = Auth::isDemoLoginEnabled();
        View::render('settings/index', compact(
            'user',
            'school',
            'google',
            'googleStatus',
            'googleRedirectUri',
            'allowedDomains',
            'baseUrlConfigured',
            'redirectUriOverride',
            'demoLogin'
        ));
    }

    public function save(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::isOwner($user)) {
            App::redirect('more');
        }
        Csrf::requirePost();
        $name = trim((string) ($_POST['school_name'] ?? ''));
        if ($name !== '') {
            $pdo = Database::pdo();
            $pdo->prepare(
                'INSERT INTO settings(key,value) VALUES(?,?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value'
            )->execute(['school_name', $name]);
        }
        App::flash('ok', '설정을 저장했습니다.');
        App::redirect('settings');
    }

    public function users(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::isOwner($user)) {
            App::redirect('more');
        }
        $users = Database::pdo()->query(
            'SELECT * FROM users ORDER BY status, display_name'
        )->fetchAll();
        View::render('settings/users', compact('users'));
    }

    public function approve(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::isOwner($user)) {
            App::redirect('more');
        }
        Csrf::requirePost();
        $id = (string) ($_POST['user_id'] ?? '');
        $status = (string) ($_POST['status'] ?? 'active');
        $role = (string) ($_POST['role'] ?? 'teacher');
        if (!in_array($status, ['active', 'disabled', 'pending'], true)) {
            $status = 'active';
        }
        if (!in_array($role, ['owner', 'manager', 'teacher', 'student'], true)) {
            $role = 'teacher';
        }
        Database::pdo()->prepare(
            'UPDATE users SET status = ?, role = ?, updated_at = ? WHERE id = ?'
        )->execute([$status, $role, Support::now(), $id]);
        App::flash('ok', '사용자 상태를 변경했습니다.');
        App::redirect('settings/users');
    }
}
