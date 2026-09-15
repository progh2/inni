<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Alert;
use Inni\Auth;
use Inni\Loan;

$root = dirname(__DIR__);
$checks = 0;

function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

function memoryDb(): PDO
{
    global $root;
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
    return $pdo;
}

function seedInbox(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    foreach (['owner' => 'owner', 'teacher' => 'teacher', 'teacher-b' => 'teacher', 'student' => 'student'] as $id => $role) {
        $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '@test', $id, $role, 'active', 't', 't']);
    }
    foreach (['ast-1', 'ast-2', 'ast-3', 'ast-4'] as $id) {
        $pdo->prepare(
            'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        )->execute([$id, 'eq', $id, $id, 'available', 'room', 'AST:' . $id, 't', 't']);
    }
}

function ids(array $rows): array
{
    return array_values(array_map(static fn(array $row): string => (string) $row['id'], $rows));
}

$teacher = actor('teacher', 'teacher');
$teacherB = actor('teacher-b', 'teacher');
$student = actor('student', 'student');
$owner = actor('owner', 'owner');

$pdo = memoryDb();
seedInbox($pdo);

check(Loan::inboxForUser($pdo, '') === [] && Loan::inboxForUser($pdo, 'teacher') === [], 'empty inbox before any checkout');
check(Loan::recentReturnsForUser($pdo, 'teacher') === [], 'empty recent returns before any checkout');

$dueSoon = gmdate('c', time() + 4 * 3600);
$duePast = gmdate('c', time() - 26 * 3600);
$mineActive = Loan::checkout($pdo, $teacher, 'ast-1', 'teacher', null, '수업', $dueSoon);
$otherOpen = Loan::checkout($pdo, $teacherB, 'ast-2', 'teacher-b', null, '다른교사', $dueSoon);
$studentNamed = Loan::checkout($pdo, $owner, 'ast-3', '박학생', null, '학생대여', $duePast);
$pdo->prepare('UPDATE loans SET borrower_user_id = NULL WHERE id = ?')->execute([$studentNamed]);

$mine = Loan::inboxForUser($pdo, 'teacher');
check(ids($mine) === [$mineActive], 'inbox lists only borrower_user_id = current user');
check(($mine[0]['status'] ?? '') === 'active', 'on-time checkout stays active until refresh');

Alert::refreshOverdue($pdo);
$pdo->prepare("UPDATE loans SET due_at = ?, status = 'active' WHERE id = ?")->execute([$duePast, $mineActive]);
Alert::refreshOverdue($pdo);
$mine = Loan::inboxForUser($pdo, 'teacher');
check(($mine[0]['status'] ?? '') === 'overdue', 'past-due own loan is overdue after refreshOverdue');

$mineSecond = Loan::checkout($pdo, $teacher, 'ast-4', 'teacher', null, '추가', $dueSoon);
$mine = Loan::inboxForUser($pdo, 'teacher');
check(ids($mine) === [$mineActive, $mineSecond], 'overdue own loan sorts before active');
check(($mine[0]['status'] ?? '') === 'overdue' && ($mine[1]['status'] ?? '') === 'active', 'inbox order is overdue then due date');

$other = Loan::inboxForUser($pdo, 'teacher-b');
check(ids($other) === [$otherOpen], 'other teacher inbox stays isolated');
check(Loan::inboxForUser($pdo, 'student') === [], 'student with no borrower_user_id loans sees empty inbox');

Loan::checkin($pdo, $teacher, $mineSecond);
$recent = Loan::recentReturnsForUser($pdo, 'teacher');
check(ids($recent) === [$mineSecond], 'recent returns lists own closed loan');
check(($recent[0]['status'] ?? '') === 'returned', 'recent return row is returned');
check(ids(Loan::inboxForUser($pdo, 'teacher')) === [$mineActive], 'returned loan leaves the open inbox');
check(Loan::recentReturnsForUser($pdo, 'teacher-b') === [], 'other teacher does not see my returns');

check(Auth::canReturn($teacher, $mine[0] ?? ['id' => $mineActive, 'status' => 'overdue', 'borrower_user_id' => 'teacher']), 'teacher can return own overdue inbox row');

$router = (string) file_get_contents($root . '/app/Router.php');
$ctl = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
$homeCtl = (string) file_get_contents($root . '/app/Controllers/HomeController.php');
$mineTpl = (string) file_get_contents($root . '/templates/loans/mine.php');
$homeTpl = (string) file_get_contents($root . '/templates/home/index.php');
$moreTpl = (string) file_get_contents($root . '/templates/more/index.php');
$indexTpl = (string) file_get_contents($root . '/templates/loans/index.php');
$layout = (string) file_get_contents($root . '/templates/layouts/app.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');

check(str_contains($router, "'loans/mine' => [LoanController::class, 'mine']"), 'loans/mine GET route is registered');
check(str_contains($ctl, 'function mine') && str_contains($ctl, 'Loan::inboxForUser') && str_contains($ctl, 'Alert::refreshOverdue'), 'mine refreshes overdue and uses inboxForUser');
check(str_contains($ctl, 'Loan::recentReturnsForUser'), 'mine also loads recent returns');
check(str_contains($ctl, 'Csrf::requirePost()') && str_contains($ctl, 'Auth::canReturn'), 'return path keeps POST+CSRF and canReturn');
check(str_contains($ctl, "return_to") && str_contains($ctl, "'loans/mine'"), 'return from inbox lands back on mine');
check(!str_contains($ctl, 'http://') && !str_contains($ctl, '$_GET[\'url\']'), 'return_to is allowlisted, not an open redirect');

check(str_contains($mineTpl, '<h1>내 대여함</h1>'), 'inbox keeps the Korean title');
check(str_contains($mineTpl, 'Auth::canReturn($user, $loan)'), 'inbox return button uses canReturn');
check(str_contains($mineTpl, 'Csrf::field()') && str_contains($mineTpl, "App::url('loans/return')"), 'inbox posts to loans/return with CSRF');
check(str_contains($mineTpl, 'name="return_to"') && str_contains($mineTpl, 'value="mine"'), 'inbox return posts return_to=mine');
check(str_contains($mineTpl, 'is-overdue'), 'inbox marks overdue rows');
check(str_contains($mineTpl, '스캔해서 반납') && str_contains($mineTpl, "App::url('scan')"), 'inbox links to scan for return');
check(str_contains($mineTpl, '최근 반납'), 'inbox shows recent returns');
check(!str_contains($mineTpl, 'new Vue') && !str_contains($mineTpl, 'createApp'), 'inbox stays SSR, no Composer SPA');

check(str_contains($indexTpl, '대여 현황') && !str_contains($indexTpl, 'borrower_user_id'), 'school-wide loans list stays unfiltered');

check(str_contains($homeCtl, 'Loan::inboxForUser') && str_contains($homeCtl, 'Auth::canLoan'), 'home loads personal inbox for canLoan roles');
check(str_contains($homeTpl, "App::url('loans/mine')") && str_contains($homeTpl, '내 대여함'), 'home links teachers to inbox');
check(str_contains($homeTpl, 'Auth::canLoan($user)'), 'home inbox entry is gated on canLoan');
check(str_contains($moreTpl, "App::url('loans/mine')") && str_contains($moreTpl, '내 대여함'), 'more menu links teachers to inbox');
check(str_contains($moreTpl, 'Auth::canLoan($user)'), 'more inbox entry is gated on canLoan');
check(str_contains($layout, "\$current === 'loans' || str_starts_with(\$current, 'loans/')"), 'inbox keeps the 더보기 tab active');
check(preg_match("/\\\$tabs = \\[\\s*\\['home', '홈'\\],\\s*\\['search', '찾기'\\],\\s*\\['scan', '스캔'\\],\\s*\\['rooms', '실'\\],\\s*\\['more', '더보기'\\],\\s*\\];/", $layout) === 1, 'bottom nav stays 홈/찾기/스캔/실/더보기');
check(str_contains($css, '.list-row.is-overdue'), 'CSS emphasizes overdue inbox rows');

echo "PASS: {$checks} loan-inbox checks\n";
