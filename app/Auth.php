<?php

declare(strict_types=1);

namespace Inni;

use PDO;

final class Auth
{
    public const GOOGLE_CALLBACK_ROUTE = 'auth/google/callback';

    public const GOOGLE_STATUS_READY = 'ready';
    public const GOOGLE_STATUS_EMPTY = 'empty';
    public const GOOGLE_STATUS_PARTIAL = 'partial';

    /** @var null|callable(string, array<string, mixed>): array */
    private static $httpPostHandler = null;

    /** @var null|callable(string, string): array */
    private static $httpGetHandler = null;

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
        return self::isActiveRole($user, ['owner', 'manager']);
    }

    /** 실사(가벼운 MVP): owner/manager만. 이어하기·텔레그램·엑셀은 범위 밖. */
    public static function canInventory(?array $user): bool
    {
        return self::isActiveRole($user, ['owner', 'manager']);
    }

    public static function canLoan(?array $user): bool
    {
        return self::isActiveRole($user, ['owner', 'manager', 'teacher']);
    }

    /**
     * 반납 권한은 대여(canLoan)와 분리한다.
     * owner/manager/teacher: 진행 중(active/overdue) 대여 전부.
     * student: borrower_user_id가 본인인 대여만.
     * $loan이 없으면 역할만 검사하고, 있으면 건별 범위까지 적용한다.
     */
    public static function canReturn(?array $user, ?array $loan = null): bool
    {
        if (!self::isActiveRole($user, ['owner', 'manager', 'teacher', 'student'])) {
            return false;
        }
        if ($loan === null) {
            return true;
        }
        if (!in_array((string) ($loan['status'] ?? ''), ['active', 'overdue'], true)) {
            return false;
        }
        if (($user['role'] ?? '') === 'student') {
            $uid = $user['id'] ?? null;
            return is_string($uid) && $uid !== '' && ($loan['borrower_user_id'] ?? null) === $uid;
        }
        return true;
    }

    /**
     * @param list<string> $roles
     */
    private static function isActiveRole(?array $user, array $roles): bool
    {
        if (!$user) {
            return false;
        }
        $status = $user['status'] ?? 'active';
        if ($status !== 'active') {
            return false;
        }
        return in_array((string) ($user['role'] ?? ''), $roles, true);
    }

    public static function isOwner(?array $user): bool
    {
        return self::isActiveRole($user, ['owner']);
    }

    /** Local/Docker smoke only. Missing key is off (production-safe). */
    public static function isDemoLoginEnabled(): bool
    {
        return (bool) App::config('demo_login', false);
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
        if (!self::isDemoLoginEnabled()) {
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

    public static function googleClientId(): string
    {
        return trim((string) App::config('google.client_id', ''));
    }

    public static function googleClientSecret(): string
    {
        return trim((string) App::config('google.client_secret', ''));
    }

    /** @return self::GOOGLE_STATUS_* */
    public static function googleConfigStatus(): string
    {
        $id = self::googleClientId();
        $secret = self::googleClientSecret();
        if ($id !== '' && $secret !== '') {
            return self::GOOGLE_STATUS_READY;
        }
        if ($id === '' && $secret === '') {
            return self::GOOGLE_STATUS_EMPTY;
        }
        return self::GOOGLE_STATUS_PARTIAL;
    }

    public static function googleEnabled(): bool
    {
        return self::googleConfigStatus() === self::GOOGLE_STATUS_READY;
    }

    /**
     * Exact Authorized redirect URI for Google Cloud Console.
     * Same string is sent as redirect_uri on /auth and /token.
     * Pretty paths like /auth/google/callback are not routes — r= query is required.
     */
    public static function googleRedirectUri(): string
    {
        $override = trim((string) App::config('google.redirect_uri', ''));
        if ($override !== '') {
            return $override;
        }
        return rtrim(App::baseUrl(), '/') . '/index.php?r=' . self::GOOGLE_CALLBACK_ROUTE;
    }

    /**
     * @return list<string> lowercase exact domains; empty = any Google-verified domain
     */
    public static function normalizedAllowedDomains(mixed $domains = null): array
    {
        if ($domains === null) {
            $domains = App::config('google.allowed_domains', []);
        }
        if (is_string($domains)) {
            $domains = preg_split('/[\s,]+/', $domains) ?: [];
        }
        if (!is_array($domains)) {
            return [];
        }
        $out = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            $domain = ltrim($domain, '@');
            $domain = rtrim($domain, '.');
            if ($domain === '') {
                continue;
            }
            $out[$domain] = $domain;
        }
        return array_values($out);
    }

    public static function emailDomain(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }
        return substr($email, $at + 1);
    }

    /**
     * Empty allowlist: any address with a domain part.
     * Non-empty: fail closed, exact domain match (case-insensitive). Subdomains do not inherit.
     */
    public static function isEmailDomainAllowed(string $email, mixed $allowedDomains = null): bool
    {
        $domain = self::emailDomain($email);
        if ($domain === '') {
            return false;
        }
        $allowed = self::normalizedAllowedDomains($allowedDomains);
        if ($allowed === []) {
            return true;
        }
        return in_array($domain, $allowed, true);
    }

    public static function isGoogleEmailVerified(array $userinfo): bool
    {
        $verified = $userinfo['email_verified'] ?? false;
        return $verified === true || $verified === 1 || $verified === '1' || $verified === 'true';
    }

    /**
     * @return array{ok: bool, error: ?string, email: string, sub: string}
     */
    public static function evaluateGoogleProfile(array $info): array
    {
        $email = strtolower(trim((string) ($info['email'] ?? '')));
        $sub = trim((string) ($info['sub'] ?? ''));
        if ($email === '' || $sub === '') {
            return ['ok' => false, 'error' => 'Google 프로필을 가져오지 못했습니다.', 'email' => $email, 'sub' => $sub];
        }
        if (!self::isGoogleEmailVerified($info)) {
            return [
                'ok' => false,
                'error' => '인증되지 않은 Google 이메일은 사용할 수 없습니다.',
                'email' => $email,
                'sub' => $sub,
            ];
        }
        if (!self::isEmailDomainAllowed($email)) {
            return [
                'ok' => false,
                'error' => '허용되지 않은 이메일 도메인입니다.',
                'email' => $email,
                'sub' => $sub,
            ];
        }
        return ['ok' => true, 'error' => null, 'email' => $email, 'sub' => $sub];
    }

    public static function googleAuthUrl(): string
    {
        $params = [
            'client_id' => self::googleClientId(),
            'redirect_uri' => self::googleRedirectUri(),
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
        $result = self::processGoogleCallback($_GET, $_SESSION);
        if (($result['user_id'] ?? '') !== '') {
            self::login((string) $result['user_id']);
        }
        App::flash((string) $result['flash_type'], (string) $result['message']);
        App::redirect(($result['user_id'] ?? '') !== '' ? 'home' : 'login');
    }

    /**
     * Token exchange + domain policy. HTTP can be stubbed in CLI tests.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $session
     * @return array{ok: bool, flash_type: string, message: string, user_id: ?string}
     */
    public static function processGoogleCallback(array $query, array &$session, ?PDO $pdo = null): array
    {
        if (!self::googleEnabled()) {
            $message = self::googleConfigStatus() === self::GOOGLE_STATUS_PARTIAL
                ? 'Google 클라이언트가 불완전합니다. client_id와 client_secret을 모두 설정하세요.'
                : 'Google 로그인이 설정되지 않았습니다.';
            return ['ok' => false, 'flash_type' => 'error', 'message' => $message, 'user_id' => null];
        }
        $state = (string) ($query['state'] ?? '');
        $expected = (string) ($session['oauth_state'] ?? '');
        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            return ['ok' => false, 'flash_type' => 'error', 'message' => 'OAuth state 검증 실패', 'user_id' => null];
        }
        unset($session['oauth_state']);
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            return ['ok' => false, 'flash_type' => 'error', 'message' => '인증 코드가 없습니다.', 'user_id' => null];
        }

        $redirectUri = self::googleRedirectUri();
        $token = self::httpPost('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => self::googleClientId(),
            'client_secret' => self::googleClientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        if (empty($token['access_token'])) {
            return ['ok' => false, 'flash_type' => 'error', 'message' => '토큰 교환 실패', 'user_id' => null];
        }

        $info = self::httpGet(
            'https://openidconnect.googleapis.com/v1/userinfo',
            (string) $token['access_token']
        );
        $profile = self::evaluateGoogleProfile(is_array($info) ? $info : []);
        if (!$profile['ok']) {
            return [
                'ok' => false,
                'flash_type' => 'error',
                'message' => (string) $profile['error'],
                'user_id' => null,
            ];
        }

        $result = self::provisionGoogleUser(
            $pdo ?? Database::pdo(),
            $profile['email'],
            $profile['sub'],
            (string) ($info['name'] ?? $profile['email']),
            isset($info['picture']) ? (string) $info['picture'] : null
        );
        if (!$result['allowed']) {
            $message = $result['created']
                ? '가입 요청이 접수되었습니다. 관리자 승인 후 이용할 수 있습니다.'
                : '계정이 아직 승인되지 않았거나 비활성 상태입니다.';
            return [
                'ok' => false,
                'flash_type' => $result['created'] ? 'ok' : 'error',
                'message' => $message,
                'user_id' => null,
            ];
        }

        return [
            'ok' => true,
            'flash_type' => 'ok',
            'message' => '로그인되었습니다.',
            'user_id' => (string) $result['user']['id'],
        ];
    }

    /** @internal CLI tests stub token/userinfo — never used in production. */
    public static function setGoogleHttpHandlers(?callable $post, ?callable $get): void
    {
        self::$httpPostHandler = $post;
        self::$httpGetHandler = $get;
    }

    private static function httpPost(string $url, array $fields): array
    {
        if (self::$httpPostHandler !== null) {
            $data = (self::$httpPostHandler)($url, $fields);
            return is_array($data) ? $data : [];
        }
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
        if (self::$httpGetHandler !== null) {
            $data = (self::$httpGetHandler)($url, $accessToken);
            return is_array($data) ? $data : [];
        }
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
