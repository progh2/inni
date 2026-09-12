<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\App;
use Inni\Auth;
use Inni\Seed;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function memoryDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
    return $pdo;
}

function appConfig(array $config): void
{
    $ref = new ReflectionClass(App::class);
    $prop = $ref->getProperty('config');
    $prop->setAccessible(true);
    $prop->setValue(null, $config);
}

function googleConfig(array $google, string $baseUrl = 'https://school.example/inni/public'): void
{
    appConfig([
        'base_url' => $baseUrl,
        'demo_login' => false,
        'google' => $google,
    ]);
}

googleConfig(['client_id' => '', 'client_secret' => '']);
check(Auth::googleConfigStatus() === Auth::GOOGLE_STATUS_EMPTY, 'empty client is empty');
check(Auth::googleEnabled() === false, 'empty client is not enabled');

googleConfig(['client_id' => '  ', 'client_secret' => "\t"]);
check(Auth::googleConfigStatus() === Auth::GOOGLE_STATUS_EMPTY, 'whitespace-only client is empty');

googleConfig(['client_id' => 'id-only', 'client_secret' => '']);
check(Auth::googleConfigStatus() === Auth::GOOGLE_STATUS_PARTIAL, 'id without secret is partial');
check(Auth::googleEnabled() === false, 'partial client is not enabled');

googleConfig(['client_id' => '', 'client_secret' => 'secret-only']);
check(Auth::googleConfigStatus() === Auth::GOOGLE_STATUS_PARTIAL, 'secret without id is partial');

googleConfig(['client_id' => '  demo-client  ', 'client_secret' => ' demo-secret ']);
check(Auth::googleConfigStatus() === Auth::GOOGLE_STATUS_READY, 'trimmed id+secret is ready');
check(Auth::googleEnabled() === true, 'ready client is enabled');
check(Auth::googleClientId() === 'demo-client', 'client id is trimmed');

$expectedUri = 'https://school.example/inni/public/index.php?r=auth/google/callback';
check(Auth::googleRedirectUri() === $expectedUri, 'redirect URI uses base_url + index.php?r=');
check(
    !str_contains(Auth::googleRedirectUri(), 'auth%2Fgoogle%2Fcallback'),
    'callback route slashes must not be percent-encoded'
);

$_SERVER['HTTP_HOST'] = 'evil.example:9999';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SCRIPT_NAME'] = '/other/index.php';
check(Auth::googleRedirectUri() === $expectedUri, 'configured base_url must ignore request host');

googleConfig(['client_id' => 'id', 'client_secret' => 'secret'], 'https://school.example/inni/public/index.php');
check(
    Auth::googleRedirectUri() === $expectedUri,
    'base_url ending in /index.php must not double the script name'
);

googleConfig(
    ['client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => ' https://console.example/index.php?r=auth/google/callback '],
    'https://ignored.example'
);
check(
    Auth::googleRedirectUri() === 'https://console.example/index.php?r=auth/google/callback',
    'google.redirect_uri override wins'
);

googleConfig(['client_id' => 'id', 'client_secret' => 'secret']);
$_SESSION = [];
$authUrl = Auth::googleAuthUrl();
$parts = parse_url($authUrl);
parse_str((string) ($parts['query'] ?? ''), $authQuery);
check(($authQuery['redirect_uri'] ?? '') === $expectedUri, 'auth URL redirect_uri matches helper');
check(($authQuery['client_id'] ?? '') === 'id', 'auth URL uses trimmed client id');
check(isset($_SESSION['oauth_state']) && $_SESSION['oauth_state'] !== '', 'auth URL stores oauth state');

check(Auth::emailDomain('Teacher@School.GO.KR') === 'school.go.kr', 'email domain is lowercased');
check(Auth::emailDomain('no-at') === '', 'missing @ has no domain');
check(Auth::emailDomain('a@b@c.example') === 'c.example', 'domain is after the last @');

check(Auth::isEmailDomainAllowed('anyone@gmail.com', []) === true, 'empty allowlist allows any domain');
check(Auth::isEmailDomainAllowed('anyone@gmail.com', null) === true, 'null allowlist reads empty config');
check(Auth::isEmailDomainAllowed('nodomain', []) === false, 'empty allowlist still requires a domain');
check(Auth::isEmailDomainAllowed('user@', ['school.go.kr']) === false, 'empty domain fails closed');

check(Auth::isEmailDomainAllowed('a@school.go.kr', ['school.go.kr']) === true, 'listed domain allowed');
check(Auth::isEmailDomainAllowed('A@School.GO.KR', ['SCHOOL.go.kr']) === true, 'domain match is case-insensitive');
check(Auth::isEmailDomainAllowed('a@gmail.com', ['school.go.kr']) === false, 'unlisted domain denied');
check(
    Auth::isEmailDomainAllowed('a@mail.school.go.kr', ['school.go.kr']) === false,
    'subdomain does not inherit parent allowlist'
);
check(
    Auth::isEmailDomainAllowed('a@school.go.kr', ['@School.go.kr.', '', '  ']) === true,
    'allowlist entries are trimmed and @/dot-normalized'
);
check(
    Auth::normalizedAllowedDomains('School.go.kr, other.ac.kr') === ['school.go.kr', 'other.ac.kr'],
    'comma-separated allowlist string is accepted'
);
check(
    Auth::isEmailDomainAllowed('a@other.ac.kr', 'School.go.kr, other.ac.kr') === true,
    'string allowlist is enforced'
);
check(
    Auth::isEmailDomainAllowed('a@gmail.com', 'School.go.kr, other.ac.kr') === false,
    'string allowlist fails closed'
);

googleConfig(['client_id' => 'id', 'client_secret' => 'secret', 'allowed_domains' => ['school.go.kr']]);
check(Auth::isEmailDomainAllowed('a@school.go.kr') === true, 'config allowlist used when argument omitted');
check(Auth::isEmailDomainAllowed('a@gmail.com') === false, 'config allowlist denies outsiders');

check(Auth::isGoogleEmailVerified(['email_verified' => true]) === true, 'bool true is verified');
check(Auth::isGoogleEmailVerified(['email_verified' => 'true']) === true, 'string true is verified');
check(Auth::isGoogleEmailVerified(['email_verified' => '1']) === true, 'string 1 is verified');
check(Auth::isGoogleEmailVerified([]) === false, 'missing email_verified fails closed');
check(Auth::isGoogleEmailVerified(['email_verified' => false]) === false, 'false is not verified');

$okProfile = Auth::evaluateGoogleProfile([
    'email' => 'A@School.go.kr',
    'sub' => 'sub-1',
    'email_verified' => true,
]);
check($okProfile['ok'] === true && $okProfile['email'] === 'a@school.go.kr', 'verified school email accepted');

$unverified = Auth::evaluateGoogleProfile([
    'email' => 'a@school.go.kr',
    'sub' => 'sub-1',
    'email_verified' => false,
]);
check($unverified['ok'] === false && str_contains((string) $unverified['error'], '인증되지 않은'), 'unverified email rejected');

$outsider = Auth::evaluateGoogleProfile([
    'email' => 'a@gmail.com',
    'sub' => 'sub-1',
    'email_verified' => true,
]);
check($outsider['ok'] === false && str_contains((string) $outsider['error'], '도메인'), 'outsider rejected when allowlist set');

$incomplete = Auth::evaluateGoogleProfile(['email' => '', 'sub' => '']);
check($incomplete['ok'] === false, 'missing email/sub rejected');

googleConfig(['client_id' => '', 'client_secret' => '']);
$session = ['oauth_state' => 'abc'];
$disabled = Auth::processGoogleCallback(['code' => 'x', 'state' => 'abc'], $session);
check($disabled['ok'] === false && str_contains($disabled['message'], '설정되지 않았습니다'), 'empty client callback rejected');

googleConfig(['client_id' => 'id', 'client_secret' => '']);
$partial = Auth::processGoogleCallback(['code' => 'x', 'state' => 'abc'], $session);
check($partial['ok'] === false && str_contains($partial['message'], '불완전'), 'partial client callback rejected');

googleConfig(['client_id' => 'id', 'client_secret' => 'secret', 'allowed_domains' => ['school.go.kr']]);
$session = ['oauth_state' => 'good-state'];
$badState = Auth::processGoogleCallback(['code' => 'x', 'state' => 'other'], $session);
check($badState['ok'] === false && str_contains($badState['message'], 'state'), 'state mismatch rejected');

$session = ['oauth_state' => 'good-state'];
$missingCode = Auth::processGoogleCallback(['state' => 'good-state'], $session);
check($missingCode['ok'] === false && str_contains($missingCode['message'], '인증 코드'), 'missing code rejected');
check(!isset($session['oauth_state']), 'state is consumed after validation');

$captured = [];
Auth::setGoogleHttpHandlers(
    static function (string $url, array $fields) use (&$captured): array {
        $captured['token_url'] = $url;
        $captured['fields'] = $fields;
        return [];
    },
    static function (): array {
        throw new RuntimeException('userinfo must not run when token exchange fails');
    }
);
$session = ['oauth_state' => 'st'];
$tokenFail = Auth::processGoogleCallback(['state' => 'st', 'code' => 'auth-code'], $session);
check($tokenFail['ok'] === false && str_contains($tokenFail['message'], '토큰'), 'token failure is surfaced');
check(($captured['token_url'] ?? '') === 'https://oauth2.googleapis.com/token', 'token URL is Google token endpoint');
check(($captured['fields']['redirect_uri'] ?? '') === $expectedUri, 'token exchange uses the same redirect URI');
check(($captured['fields']['code'] ?? '') === 'auth-code', 'auth code is posted to token endpoint');
check(($captured['fields']['client_id'] ?? '') === 'id', 'token exchange sends client_id');
check(($captured['fields']['grant_type'] ?? '') === 'authorization_code', 'grant_type is authorization_code');

Auth::setGoogleHttpHandlers(
    static fn(): array => ['access_token' => 'tok'],
    static fn(): array => [
        'email' => 'teacher@school.go.kr',
        'email_verified' => true,
        'sub' => 'sub-teacher',
        'name' => '교사',
    ]
);
$pdo = memoryDb();
$session = ['oauth_state' => 'st'];
$ok = Auth::processGoogleCallback(['state' => 'st', 'code' => 'ok'], $session, $pdo);
check($ok['ok'] === true && is_string($ok['user_id']) && $ok['user_id'] !== '', 'stubbed callback provisions a user');
check($ok['user_id'] !== Seed::DEMO_OWNER_ID, 'provisioned user is not a demo seed id');
$user = $pdo->query('SELECT * FROM users WHERE id = ' . $pdo->quote((string) $ok['user_id']))->fetch(PDO::FETCH_ASSOC);
check(is_array($user) && $user['role'] === 'owner' && $user['email'] === 'teacher@school.go.kr', 'first Google user is owner');

Auth::setGoogleHttpHandlers(
    static fn(): array => ['access_token' => 'tok'],
    static fn(): array => [
        'email' => 'outsider@gmail.com',
        'email_verified' => true,
        'sub' => 'sub-out',
        'name' => '외부',
    ]
);
$session = ['oauth_state' => 'st'];
$denied = Auth::processGoogleCallback(['state' => 'st', 'code' => 'ok'], $session, $pdo);
check($denied['ok'] === false && str_contains($denied['message'], '도메인'), 'stubbed callback enforces allowlist');
check(
    (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'outsider@gmail.com'")->fetchColumn() === 0,
    'rejected domain must not create a user'
);

Auth::setGoogleHttpHandlers(
    static fn(): array => ['access_token' => 'tok'],
    static fn(): array => [
        'email' => 'unverified@school.go.kr',
        'email_verified' => false,
        'sub' => 'sub-uv',
        'name' => '미인증',
    ]
);
$session = ['oauth_state' => 'st'];
$uv = Auth::processGoogleCallback(['state' => 'st', 'code' => 'ok'], $session, $pdo);
check($uv['ok'] === false && str_contains($uv['message'], '인증되지 않은'), 'stubbed callback rejects unverified email');

Auth::setGoogleHttpHandlers(null, null);

$router = (string) file_get_contents(dirname(__DIR__) . '/app/Router.php');
check(
    str_contains($router, "'auth/google/callback' => [AuthController::class, 'googleCallback']"),
    'router still maps auth/google/callback'
);
check(
    str_contains((string) file_get_contents(dirname(__DIR__) . '/app/Auth.php'), 'googleRedirectUri()'),
    'callback uses the shared redirect helper'
);

$loginTpl = (string) file_get_contents(dirname(__DIR__) . '/templates/auth/login.php');
check(str_contains($loginTpl, 'redirect-uri'), 'login shows the Console redirect URI');
check(str_contains($loginTpl, 'GOOGLE_STATUS_PARTIAL'), 'login distinguishes partial client config');
check(
    str_contains($loginTpl, "App::url('auth/demo', ['as' => 'owner'])"),
    'login template must keep demo owner button'
);

$settingsTpl = (string) file_get_contents(dirname(__DIR__) . '/templates/settings/index.php');
check(str_contains($settingsTpl, 'googleRedirectUri'), 'settings always shows redirect URI');
check(str_contains($settingsTpl, 'allowed_domains'), 'settings explains allowed_domains');
check(str_contains($settingsTpl, 'demo_login'), 'settings warns about demo_login');

$example = (string) file_get_contents(dirname(__DIR__) . '/config.example.php');
check(!preg_match('/client_id\'\s*=>\s*\'(?!\')[^\'].+\'/', $example), 'example config must not ship a real client_id');
check(str_contains($example, "'client_id' => ''"), 'example client_id stays empty');
check(str_contains($example, "'client_secret' => ''"), 'example client_secret stays empty');
check(str_contains($example, "'demo_login' => true"), 'local example keeps demo_login true');

$production = (string) file_get_contents(dirname(__DIR__) . '/config.production.example.php');
check(!preg_match('/client_id\'\s*=>\s*\'(?!\')[^\'].+\'/', $production), 'production example must not ship a real client_id');
check(str_contains($production, "'client_id' => ''"), 'production example client_id stays empty');
check(str_contains($production, "'client_secret' => ''"), 'production example client_secret stays empty');
check(str_contains($production, "'demo_login' => false"), 'production example shows demo_login false');

echo "PASS: {$checks} google-oauth checks\n";
