<?php

declare(strict_types=1);

namespace Inni\Controllers;

use Inni\Alert;
use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Database;
use Inni\Support;
use Inni\Telegram;
use Inni\View;

final class SettingsController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canConfigureAlerts($user)) {
            App::flash('error', '담당교사만 설정할 수 있습니다.');
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
        $canEditSchool = Auth::isOwner($user);
        $telegramReady = Telegram::isReady();
        $telegramSettings = Alert::settings(Database::pdo());
        $telegramChatId = $telegramSettings['chat_id'];
        $telegramEvents = $telegramSettings['events'];
        $telegramDefaultChat = Telegram::defaultChatId();
        View::render('settings/index', compact(
            'user',
            'school',
            'google',
            'googleStatus',
            'googleRedirectUri',
            'allowedDomains',
            'baseUrlConfigured',
            'redirectUriOverride',
            'demoLogin',
            'canEditSchool',
            'telegramReady',
            'telegramChatId',
            'telegramEvents',
            'telegramDefaultChat'
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

    public function saveTelegram(): void
    {
        $user = Auth::requireLogin();
        if (!Auth::canConfigureAlerts($user)) {
            App::flash('error', '알림 설정 권한이 없습니다.');
            App::redirect('more');
        }
        Csrf::requirePost();
        $chatId = is_string($_POST['chat_id'] ?? null) ? $_POST['chat_id'] : '';
        $events = [
            Alert::EVENT_LOW_STOCK => !empty($_POST['event_low_stock']),
            Alert::EVENT_OVERDUE_LOAN => !empty($_POST['event_overdue_loan']),
        ];
        try {
            Alert::saveSettings(Database::pdo(), $user, $chatId, $events);
            App::flash('ok', '텔레그램 알림 설정을 저장했습니다.');
        } catch (\InvalidArgumentException $e) {
            App::flash('error', $e->getMessage());
        }
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
