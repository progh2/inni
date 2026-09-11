<?php

declare(strict_types=1);

namespace Inni;

use PDO;

final class Auth
{
    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? AND status = ?');
        $stmt->execute([$id, 'active']);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            App::redirect('login');
        }
        return $user;
    }

    public static function login(string $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function canWrite(?array $user): bool
    {
        return $user && in_array($user['role'], ['owner', 'manager'], true);
    }

    public static function canLoan(?array $user): bool
    {
        return $user && in_array($user['role'], ['owner', 'manager', 'teacher'], true);
    }

    public static function isOwner(?array $user): bool
    {
        return $user && $user['role'] === 'owner';
    }

    public static function loginDemo(string $which = 'owner'): void
    {
        $id = self::demoLoginUserId($which);
        if ($id === null) {
            App::flash('error', '데모 로그인이 비활성화되어 있습니다.');
            App::redirect('login');
        }
        self::login($id);
        App::flash('ok', '데모 계정으로 로그인했습니다.');
        App::redirect('home');
    }

    /** Demo-seed id when demo_login is on; null when the shortcut is disabled. */
    public static function demoLoginUserId(string $which = 'owner'): ?string
    {
        if (!App::config('demo_login', true)) {
            return null;
        }
        return $which === 'teacher' ? Seed::DEMO_TEACHER_ID : Seed::DEMO_OWNER_ID;
    }

    /** Users created only by Seed::run — ignored for first-Google-owner. */
    public static function countNonDemoUsers(PDO $pdo): int
    {
        $ids = Seed::demoUserIds();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id NOT IN ($placeholders)");
        $stmt->execute($ids);
        return (int) $stmt->fetchColumn();
    }

    public static function hasRealOwner(PDO $pdo): bool
    {
        $ids = Seed::demoUserIds();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'owner' AND status = 'active' AND id NOT IN ($placeholders)"
        );
        $stmt->execute($ids);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Create or refresh a Google account.
     * First non-demo user becomes owner; later signups stay pending until an admin approves.
     * A leftover pending account is promoted if no real (non-demo) owner exists yet.
     *
     * @return array{user: array, created: bool, allowed: bool}
     */
    public static function provisionGoogleUser(
        PDO $pdo,
        string $email,
        string $sub,
        string $name,
        ?string $photoUrl
    ): array {
        $email = strtolower($email);
        $t = Support::now();

        $stmt = $pdo->prepare('SELECT * FROM users WHERE google_sub = ? OR email = ? LIMIT 1');
        $stmt->execute([$sub, $email]);
        $user = $stmt->fetch();

        if (!$user) {
            $isFirst = self::countNonDemoUsers($pdo) === 0;
            $role = $isFirst ? 'owner' : 'teacher';
            $status = $isFirst ? 'active' : 'pending';
            $id = Support::id('usr');
            $pdo->prepare(
                'INSERT INTO users(id,email,display_name,photo_url,role,status,google_sub,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id, $email, $name !== '' ? $name : $email, $photoUrl,
                $role, $status, $sub, $t, $t,
            ]);
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            return [
                'user' => is_array($user) ? $user : [],
                'created' => true,
                'allowed' => $status === 'active',
            ];
        }

        if ($user['status'] !== 'active') {
            if (
                $user['status'] === 'pending'
                && !Seed::isDemoUserId((string) $user['id'])
                && !self::hasRealOwner($pdo)
            ) {
                $pdo->prepare(
                    'UPDATE users SET role = ?, status = ?, google_sub = ?, display_name = ?, photo_url = ?, updated_at = ? WHERE id = ?'
                )->execute([
                    'owner',
                    'active',
                    $sub,
                    $name !== '' ? $name : $user['display_name'],
                    $photoUrl ?? $user['photo_url'],
                    $t,
                    $user['id'],
                ]);
            } else {
                return ['user' => $user, 'created' => false, 'allowed' => false];
            }
        } else {
            $pdo->prepare(
                'UPDATE users SET google_sub = ?, display_name = ?, photo_url = ?, updated_at = ? WHERE id = ?'
            )->execute([
                $sub,
                $name !== '' ? $name : $user['display_name'],
                $photoUrl ?? $user['photo_url'],
                $t,
                $user['id'],
            ]);
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $fresh = $stmt->fetch();
        $user = is_array($fresh) ? $fresh : $user;
        return [
            'user' => $user,
            'created' => false,
            'allowed' => ($user['status'] ?? '') === 'active',
        ];
    }

    public static function googleEnabled(): bool
    {
        $g = App::config('google', []);
        return !empty($g['client_id']) && !empty($g['client_secret']);
    }

    public static function googleAuthUrl(): string
    {
        $params = [
            'client_id' => App::config('google.client_id'),
            'redirect_uri' => App::url('auth/google/callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account',
            'state' => bin2hex(random_bytes(16)),
        ];
        $_SESSION['oauth_state'] = $params['state'];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public static function handleGoogleCallback(): void
    {
        if (!self::googleEnabled()) {
            App::flash('error', 'Google 로그인이 설정되지 않았습니다.');
            App::redirect('login');
        }
        $state = $_GET['state'] ?? '';
        if (!$state || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
            App::flash('error', 'OAuth state 검증 실패');
            App::redirect('login');
        }
        unset($_SESSION['oauth_state']);
        $code = $_GET['code'] ?? '';
        if ($code === '') {
            App::flash('error', '인증 코드가 없습니다.');
            App::redirect('login');
        }

        $token = self::httpPost('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => App::config('google.client_id'),
            'client_secret' => App::config('google.client_secret'),
            'redirect_uri' => App::url('auth/google/callback'),
            'grant_type' => 'authorization_code',
        ]);
        if (empty($token['access_token'])) {
            App::flash('error', '토큰 교환 실패');
            App::redirect('login');
        }

        $info = self::httpGet(
            'https://openidconnect.googleapis.com/v1/userinfo',
            $token['access_token']
        );
        $email = strtolower((string) ($info['email'] ?? ''));
        $sub = (string) ($info['sub'] ?? '');
        if ($email === '' || $sub === '') {
            App::flash('error', 'Google 프로필을 가져오지 못했습니다.');
            App::redirect('login');
        }

        $domains = App::config('google.allowed_domains', []) ?: [];
        if ($domains) {
            $domain = substr(strrchr($email, '@') ?: '', 1);
            if (!in_array($domain, $domains, true)) {
                App::flash('error', '허용되지 않은 이메일 도메인입니다.');
                App::redirect('login');
            }
        }

        $result = self::provisionGoogleUser(
            Database::pdo(),
            $email,
            $sub,
            (string) ($info['name'] ?? $email),
            isset($info['picture']) ? (string) $info['picture'] : null
        );
        if (!$result['allowed']) {
            $message = $result['created']
                ? '가입 요청이 접수되었습니다. 관리자 승인 후 이용할 수 있습니다.'
                : '계정이 아직 승인되지 않았거나 비활성 상태입니다.';
            App::flash($result['created'] ? 'ok' : 'error', $message);
            App::redirect('login');
        }
        self::login((string) $result['user']['id']);

        App::flash('ok', '로그인되었습니다.');
        App::redirect('home');
    }

    private static function httpPost(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : [];
    }

    private static function httpGet(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : [];
    }
}
