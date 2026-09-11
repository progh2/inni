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
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
    return $pdo;
}

function seedDemoUsers(PDO $pdo): void
{
    $t = '2026-01-01T00:00:00+00:00';
    $pdo->prepare(
        'INSERT INTO users(id,email,display_name,role,status,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?), (?,?,?,?,?,?,?)'
    )->execute([
        Seed::DEMO_OWNER_ID, 'owner@demo.inni', '김담당', 'owner', 'active', $t, $t,
        Seed::DEMO_TEACHER_ID, 'teacher@demo.inni', '이수업', 'teacher', 'active', $t, $t,
    ]);
}

function insertUser(PDO $pdo, string $id, string $email, string $role, string $status, ?string $sub = null): void
{
    $t = '2026-01-01T00:00:00+00:00';
    $pdo->prepare(
        'INSERT INTO users(id,email,display_name,role,status,google_sub,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?)'
    )->execute([$id, $email, $id, $role, $status, $sub, $t, $t]);
}

$ref = new ReflectionClass(App::class);
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);

$configProp->setValue(null, ['demo_login' => true]);
check(Auth::demoLoginUserId('owner') === Seed::DEMO_OWNER_ID, 'demo_login owner id');
check(Auth::demoLoginUserId('teacher') === Seed::DEMO_TEACHER_ID, 'demo_login teacher id');

$configProp->setValue(null, ['demo_login' => false]);
check(Auth::demoLoginUserId('owner') === null, 'demo_login off must hide owner shortcut');
check(Auth::demoLoginUserId('teacher') === null, 'demo_login off must hide teacher shortcut');

$empty = memoryDb();
check(Auth::countNonDemoUsers($empty) === 0, 'empty DB has no real users');
check(Auth::hasRealOwner($empty) === false, 'empty DB has no real owner');
$first = Auth::provisionGoogleUser($empty, 'admin@school.go.kr', 'sub-admin', '관리자', null);
check($first['created'] && $first['allowed'], 'first Google user on empty DB must be allowed');
check($first['user']['role'] === 'owner' && $first['user']['status'] === 'active', 'first Google user on empty DB is owner');
$second = Auth::provisionGoogleUser($empty, 'next@school.go.kr', 'sub-next', '다음', null);
check($second['created'] && !$second['allowed'], 'second Google user must stay pending');
check($second['user']['role'] === 'teacher' && $second['user']['status'] === 'pending', 'second Google user is pending teacher');

$demoOnly = memoryDb();
seedDemoUsers($demoOnly);
check(Auth::countNonDemoUsers($demoOnly) === 0, 'demo-seed users must not count as real users');
check(Auth::hasRealOwner($demoOnly) === false, 'demo-owner must not count as a real owner');
$prodFirst = Auth::provisionGoogleUser($demoOnly, 'first@school.go.kr', 'sub-first', '첫교사', 'https://pic');
check($prodFirst['created'] && $prodFirst['allowed'], 'first Google user must ignore demo seed');
check($prodFirst['user']['role'] === 'owner' && $prodFirst['user']['status'] === 'active', 'first Google user with demo seed is owner');
check($prodFirst['user']['google_sub'] === 'sub-first', 'google_sub stored on first owner');
$prodSecond = Auth::provisionGoogleUser($demoOnly, 'later@school.go.kr', 'sub-later', '나중', null);
check($prodSecond['created'] && !$prodSecond['allowed'], 'later Google user after real owner is pending');
check($prodSecond['user']['status'] === 'pending', 'later Google user status pending');

$legacy = memoryDb();
seedDemoUsers($legacy);
insertUser($legacy, 'usr_stuck', 'stuck@school.go.kr', 'teacher', 'pending', 'sub-stuck');
check(Auth::countNonDemoUsers($legacy) === 1, 'legacy pending Google user is a real user');
$healed = Auth::provisionGoogleUser($legacy, 'stuck@school.go.kr', 'sub-stuck', '복구', null);
check($healed['allowed'] && !$healed['created'], 'legacy pending user is bootstrapped');
check($healed['user']['role'] === 'owner' && $healed['user']['status'] === 'active', 'legacy pending user becomes owner');

$withOwner = memoryDb();
seedDemoUsers($withOwner);
insertUser($withOwner, 'usr_owner', 'owner@school.go.kr', 'owner', 'active', 'sub-owner');
insertUser($withOwner, 'usr_wait', 'wait@school.go.kr', 'teacher', 'pending', 'sub-wait');
$stillPending = Auth::provisionGoogleUser($withOwner, 'wait@school.go.kr', 'sub-wait', '대기', null);
check(!$stillPending['allowed'], 'pending user stays pending when a real owner exists');
check($stillPending['user']['status'] === 'pending', 'pending status unchanged when owner exists');

$disabledDb = memoryDb();
insertUser($disabledDb, 'usr_off', 'off@school.go.kr', 'teacher', 'disabled', 'sub-off');
$disabled = Auth::provisionGoogleUser($disabledDb, 'off@school.go.kr', 'sub-off', '중지', null);
check(!$disabled['allowed'], 'disabled account must not be bootstrapped to owner');
check($disabled['user']['status'] === 'disabled', 'disabled status stays disabled');

$refresh = memoryDb();
insertUser($refresh, 'usr_real', 'real@school.go.kr', 'owner', 'active', 'sub-old');
$again = Auth::provisionGoogleUser($refresh, 'real@school.go.kr', 'sub-new', '새이름', 'https://n');
check($again['allowed'] && $again['user']['google_sub'] === 'sub-new', 'returning owner can refresh google_sub');
check($again['user']['display_name'] === '새이름', 'returning owner display name updates');

$source = (string) file_get_contents(dirname(__DIR__) . '/app/Auth.php');
check(
    str_contains($source, 'countNonDemoUsers') && str_contains($source, 'provisionGoogleUser'),
    'OAuth callback must use the non-demo first-owner helper'
);
check(
    !preg_match("/SELECT COUNT\\(\\*\\) FROM users'/", $source),
    'OAuth must not treat raw user COUNT(*) as first-owner'
);

$loginSource = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/AuthController.php');
check(str_contains($loginSource, 'Auth::loginDemo'), 'demo login route must stay wired');
$loginTpl = (string) file_get_contents(dirname(__DIR__) . '/templates/auth/login.php');
check(
    str_contains($loginTpl, "App::url('auth/demo', ['as' => 'owner'])"),
    'login template must keep demo owner button'
);

echo "PASS: {$checks} bootstrap-owner checks\n";
