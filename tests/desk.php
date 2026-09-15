<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\Desk;
use Inni\Loan;
use Inni\Scan;

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

function seedDesk(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','LOC:room','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'teacher-b' => 'teacher', 'student' => 'student'] as $id => $role) {
        $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '@test', $id, $role, 'active', 't', 't']);
    }
    $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(['pending', 'pending@test', 'pending', 'teacher', 'pending', 't', 't']);
    $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(['disabled', 'disabled@test', 'disabled', 'teacher', 'disabled', 't', 't']);
    foreach (['ast-1' => 'available', 'ast-2' => 'available', 'ast-repair' => 'repair'] as $id => $status) {
        $pdo->prepare(
            'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        )->execute([$id, 'eq', $id, '전장-' . $id, $status, 'room', 'AST:' . $id, 't', 't']);
    }
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, asset_id, status, borrower_user_id, borrower_name, created_by FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_id FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function reject(callable $fn, PDO $pdo, string $message): void
{
    $before = snapshot($pdo);
    try {
        $fn();
        throw new RuntimeException($message . ' was accepted');
    } catch (InvalidArgumentException) {
        check(snapshot($pdo) === $before, $message . ' changed state');
    }
}

function ids(array $rows): array
{
    return array_values(array_map(static fn(array $row): string => (string) $row['id'], $rows));
}

$teacher = actor('teacher', 'teacher');
$teacherB = actor('teacher-b', 'teacher');
$owner = actor('owner', 'owner');
$student = actor('student', 'student');

$pdo = memoryDb();
seedDesk($pdo);

$morning = strtotime('2026-09-15 09:30:00');
$evening = strtotime('2026-09-15 17:00:00');
$night = strtotime('2026-09-15 21:15:00');
check(Desk::defaultDueLocal($morning) === '2026-09-15T17:00', 'before 17:00 defaults to today 17:00');
check(Desk::defaultDueLocal($evening) === '2026-09-16T17:00', 'at 17:00 rolls to next day 17:00');
check(Desk::defaultDueLocal($night) === '2026-09-16T17:00', 'after 17:00 rolls to next day 17:00');
check(str_contains(Desk::defaultDueIso($morning), '2026-09-15'), 'default due ISO keeps the same calendar day in the morning');
check(str_contains(Desk::dueIsoFromLocal('', $morning), '2026-09-15'), 'empty due_at uses the default');
check(str_contains(Desk::dueIsoFromLocal('2026-09-16T09:00', $morning), '2026-09-16T09:00'), 'posted due_at is kept');

$borrowers = Desk::borrowers($pdo, $teacher);
check(ids($borrowers)[0] === 'teacher', 'current teacher is the first quick pick');
check(!in_array('student', ids($borrowers), true), 'students are not quick-pick borrowers');
check(!in_array('pending', ids($borrowers), true) && !in_array('disabled', ids($borrowers), true), 'pending/disabled stay off the pick list');
check(in_array('owner', ids($borrowers), true) && in_array('teacher-b', ids($borrowers), true), 'other active staff stay on the pick list');

$found = Desk::findAsset($pdo, 'ast-1');
check(is_array($found) && ($found['management_number'] ?? '') === '전장-ast-1', 'desk loads an asset by id');
check(Desk::findAsset($pdo, 'missing') === null && Desk::openLoanForAsset($pdo, 'ast-1') === null, 'missing asset/loan stay empty');

$hits = Desk::searchAssets($pdo, '전장-ast-1');
check(ids($hits) === ['ast-1'], 'desk search matches management number');
$hits = Desk::searchAssets($pdo, 'ast-repair');
check(ids($hits) === ['ast-repair'], 'desk search matches name');
check(Desk::searchAssets($pdo, '') === [], 'empty desk search does not list everything');

$scanAsset = Scan::lookup($pdo, '전장-ast-1');
check(($scanAsset['kind'] ?? '') === 'asset' && ($scanAsset['id'] ?? '') === 'ast-1', 'management number still resolves as an asset');
$scanQr = Scan::lookup($pdo, 'AST:ast-1');
check(($scanQr['kind'] ?? '') === 'asset' && ($scanQr['id'] ?? '') === 'ast-1', 'AST: QR still resolves as an asset');
check((Scan::lookup($pdo, 'CAT:eq')['kind'] ?? '') === 'catalog', 'catalog QR stays catalog for the scan tab');
check((Scan::lookup($pdo, 'LOC:room')['kind'] ?? '') === 'location', 'location QR stays location for the scan tab');

$due = Desk::defaultDueIso($morning);
$loanOther = Loan::checkout($pdo, $teacher, 'ast-1', '', null, null, $due, 'teacher-b');
$row = $pdo->query('SELECT borrower_user_id, borrower_name, created_by, due_at FROM loans WHERE id = ' . $pdo->quote($loanOther))->fetch(PDO::FETCH_ASSOC);
check(($row['borrower_user_id'] ?? '') === 'teacher-b' && ($row['borrower_name'] ?? '') === 'teacher-b', 'desk checkout stores the picked teacher as borrower');
check(($row['created_by'] ?? '') === 'teacher', 'created_by stays the staff who processed the desk loan');
check(($row['due_at'] ?? '') === $due, 'desk checkout keeps the default due date');
check(ids(Loan::inboxForUser($pdo, 'teacher-b')) === [$loanOther], 'picked teacher inbox receives the desk loan');
check(Loan::inboxForUser($pdo, 'teacher') === [], 'staff inbox does not claim a loan made for someone else');
check(Desk::openLoanForAsset($pdo, 'ast-1')['id'] === $loanOther, 'open loan is attached to the scanned asset');

$returned = Loan::checkin($pdo, $teacher, $loanOther);
check($returned === 'ast-1', 'desk return closes the open loan');
check(Desk::openLoanForAsset($pdo, 'ast-1') === null, 'returned asset has no open desk loan');

$selfLoan = Loan::checkout($pdo, $teacher, 'ast-1', '', null, null, $due, null);
check($pdo->query('SELECT borrower_user_id FROM loans WHERE id = ' . $pdo->quote($selfLoan))->fetchColumn() === 'teacher', 'omitted borrower_user_id still defaults to the actor');
Loan::checkin($pdo, $owner, $selfLoan);

reject(static function () use ($pdo, $teacher): void {
    Loan::checkout($pdo, $teacher, 'ast-2', '', null, null, null, 'missing-user');
}, $pdo, 'Unknown borrower');
reject(static function () use ($pdo, $teacher): void {
    Loan::checkout($pdo, $teacher, 'ast-2', '', null, null, null, 'disabled');
}, $pdo, 'Disabled borrower');
reject(static function () use ($pdo, $student): void {
    Loan::checkout($pdo, $student, 'ast-2', '학생', null, null, null, 'teacher');
}, $pdo, 'Student desk checkout');
reject(static function () use ($pdo, $teacher): void {
    Loan::checkout($pdo, $teacher, 'ast-repair', '', null, null, null, 'teacher');
}, $pdo, 'Repair desk checkout');

$router = (string) file_get_contents($root . '/app/Router.php');
$deskCtl = (string) file_get_contents($root . '/app/Controllers/DeskController.php');
$loanCtl = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
$scanCtl = (string) file_get_contents($root . '/app/Controllers/ScanController.php');
$assetCtl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
$deskTpl = (string) file_get_contents($root . '/templates/desk/index.php');
$scanTpl = (string) file_get_contents($root . '/templates/scan/index.php');
$homeTpl = (string) file_get_contents($root . '/templates/home/index.php');
$moreTpl = (string) file_get_contents($root . '/templates/more/index.php');
$mineTpl = (string) file_get_contents($root . '/templates/loans/mine.php');
$assetTpl = (string) file_get_contents($root . '/templates/assets/show.php');
$layout = (string) file_get_contents($root . '/templates/layouts/app.php');

check(str_contains($router, "'loans/desk' => [DeskController::class, 'index']"), 'desk GET route is registered');
check(str_contains($router, "'loans/desk/resolve' => [DeskController::class, 'resolve']"), 'desk scan POST route is registered');
check(str_contains($router, "'loans/desk/loan' => [DeskController::class, 'loan']"), 'desk loan POST route is registered');
check(str_contains($router, "'scan/resolve' => [ScanController::class, 'resolve']"), 'scan tab still posts to scan/resolve');
check(str_contains($router, "'assets/loan' => [AssetController::class, 'loan']"), 'asset detail loan route stays');
check(str_contains($router, "'loans/return' => [LoanController::class, 'returnLoan']"), 'shared return route stays');
check(str_contains($router, "'loans/mine' => [LoanController::class, 'mine']"), 'inbox route stays');

check(str_contains($deskCtl, 'Auth::canLoan($user)'), 'desk is gated on canLoan');
check(substr_count($deskCtl, 'Csrf::requirePost()') >= 2, 'desk resolve and loan require POST+CSRF');
check(str_contains($deskCtl, 'Scan::lookup') && str_contains($deskCtl, "App::redirect('loans/desk'"), 'desk scan stays on the desk');
check(str_contains($deskCtl, 'Loan::checkout') && str_contains($deskCtl, 'borrower_user_id'), 'desk loan uses checkout with the picked borrower');
check(str_contains($deskCtl, 'Desk::dueIsoFromLocal'), 'desk loan applies the default due date');
check(!str_contains($scanCtl, 'loans/desk'), 'scan tab does not start routing to the desk');
check(str_contains($scanCtl, "App::redirect('assets/show'"), 'scan tab still opens asset detail');
check(str_contains($assetCtl, "App::redirect('assets/show'"), 'assets/loan still lands on asset detail');

check(str_contains($loanCtl, "'desk' => 'loans/desk'"), 'return_to=desk is allowlisted');
check(str_contains($loanCtl, 'Auth::canReturn($user)') && str_contains($loanCtl, 'Csrf::requirePost()'), 'shared return keeps canReturn and POST+CSRF');
check(!str_contains($loanCtl, '$_GET[\'url\']'), 'return_to stays allowlisted');

check(str_contains($deskTpl, '<h1>대여 데스크</h1>'), 'desk keeps the Korean title');
check(str_contains($deskTpl, "App::url('loans/desk/resolve')"), 'desk camera/code posts to desk resolve');
check(str_contains($deskTpl, "App::url('loans/desk/loan')") && str_contains($deskTpl, 'Csrf::field()'), 'desk loan form is POST+CSRF');
check(str_contains($deskTpl, 'borrower_user_id') && str_contains($deskTpl, 'pick-chip'), 'desk shows a borrower quick pick');
check(str_contains($deskTpl, 'due_at') && str_contains($deskTpl, '$dueLocal'), 'desk pre-fills the default due date');
check(str_contains($deskTpl, '빌려주기') && str_contains($deskTpl, '받아주기'), 'desk CTAs are 빌려주기 / 받아주기');
check(str_contains($deskTpl, "App::url('loans/return')") && str_contains($deskTpl, 'value="desk"'), 'desk return posts to loans/return with return_to=desk');
check(str_contains($deskTpl, 'Auth::canLoan($user)') && str_contains($deskTpl, 'Auth::canReturn($user, $loan)'), 'desk buttons use canLoan/canReturn');
check(str_contains($deskTpl, 'name="q"') && str_contains($deskTpl, "App::url('loans/desk'"), 'desk search stays on the desk');
check(!str_contains($deskTpl, 'new Vue') && !str_contains($deskTpl, 'createApp'), 'desk stays SSR');

check(str_contains($scanTpl, "App::url('scan/resolve')"), 'scan page still posts to scan/resolve');
check(str_contains($assetTpl, "App::url('assets/loan')") && str_contains($assetTpl, '대여 확정'), 'asset detail loan form is unchanged');
check(str_contains($mineTpl, '<h1>내 대여함</h1>') && str_contains($mineTpl, "App::url('scan')"), 'inbox still offers scan-to-detail return');

check(str_contains($homeTpl, "App::url('loans/desk')") && str_contains($homeTpl, '대여 데스크'), 'home links canLoan users to the desk');
check(str_contains($homeTpl, 'Auth::canLoan($user)'), 'home desk entry is gated on canLoan');
check(str_contains($moreTpl, "App::url('loans/desk')") && str_contains($moreTpl, '대여 데스크'), 'more menu links canLoan users to the desk');
check(str_contains($moreTpl, 'Auth::canLoan($user)'), 'more desk entry is gated on canLoan');
check(substr_count($moreTpl, "App::url('loans/desk')") === 1, 'more must not duplicate the desk link');

check(preg_match("/\\\$tabs = \\[\\s*\\['home', '홈'\\],\\s*\\['search', '찾기'\\],\\s*\\['scan', '스캔'\\],\\s*\\['rooms', '실'\\],\\s*\\['more', '더보기'\\],\\s*\\];/", $layout) === 1, 'bottom nav stays 홈/찾기/스캔/실/더보기');
check(str_contains($layout, "\$current === 'loans' || str_starts_with(\$current, 'loans/')"), 'desk keeps the 더보기 tab active');
check(!str_contains($layout, "['desk'"), 'desk is not a new mobile tab');

function invokeController(
    string $dbPath,
    string $class,
    string $method,
    string $httpMethod,
    array $post,
    array $get,
    string $userId,
    ?string $sessionToken,
): array {
    global $root;
    $tmp = sys_get_temp_dir() . '/inni-desk-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $runner = $tmp . '/run.php';
    $resultFile = $tmp . '/result.json';
    $redirectFile = $tmp . '/redirect.txt';
    file_put_contents($tmp . '/payload.json', json_encode([
        'root' => $root,
        'db' => $dbPath,
        'class' => $class,
        'method' => $method,
        'http' => $httpMethod,
        'post' => $post,
        'get' => $get,
        'user' => $userId,
        'csrf' => $sessionToken,
        'result' => $resultFile,
        'redirect' => $redirectFile,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$p = json_decode(file_get_contents(__DIR__ . '/payload.json'), true, 512, JSON_THROW_ON_ERROR);
require $p['root'] . '/app/bootstrap.php';

use Inni\App;
use Inni\Csrf;

$ref = new ReflectionClass(App::class);
$rootProp = $ref->getProperty('root');
$rootProp->setAccessible(true);
$rootProp->setValue(null, $p['root']);
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, [
    'app_name' => 'inni',
    'school_name' => 'desk-test',
    'timezone' => 'Asia/Seoul',
    'demo_login' => true,
    'session_name' => 'inni_desk_test',
    'base_url' => 'http://localhost',
    'db_path' => $p['db'],
]);

$_SERVER['REQUEST_METHOD'] = $p['http'];
$_POST = $p['post'];
$_GET = $p['get'];
$_SESSION = ['user_id' => $p['user']];
if (is_string($p['csrf'])) {
    $_SESSION[Csrf::SESSION_KEY] = $p['csrf'];
}

putenv('INNI_TEST_REDIRECT=' . $p['redirect']);

register_shutdown_function(static function () use ($p): void {
    $asset = null;
    $loan = null;
    if (is_file($p['db'])) {
        $pdo = new PDO('sqlite:' . $p['db']);
        $asset = $pdo->query("SELECT status FROM assets WHERE id = 'ast-dmm-1'")->fetchColumn() ?: null;
        $loan = $pdo->query(
            "SELECT id, status, borrower_user_id FROM loans WHERE asset_id = 'ast-dmm-1' ORDER BY created_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $location = is_file($p['redirect']) ? rawurldecode(trim((string) file_get_contents($p['redirect']))) : null;
    file_put_contents($p['result'], json_encode([
        'status' => http_response_code(),
        'flash' => $_SESSION['_flash'] ?? [],
        'location' => $location,
        'asset' => $asset,
        'loan' => $loan,
        'body' => (string) ob_get_contents(),
    ], JSON_UNESCAPED_UNICODE));
});

ob_start();
$controller = new $p['class']();
$controller->{$p['method']}();
PHP);

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>' . escapeshellarg($tmp . '/stderr.txt'));
    $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
    $stderr = is_file($tmp . '/stderr.txt') ? (string) file_get_contents($tmp . '/stderr.txt') : '';
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Desk harness failed: ' . $raw . $stderr);
    }
    return $decoded;
}

$smokeDir = sys_get_temp_dir() . '/inni-desk-db-' . bin2hex(random_bytes(4));
mkdir($smokeDir, 0700);
$smokeDb = $smokeDir . '/inni.sqlite';
$sessionToken = bin2hex(random_bytes(32));

$scanTab = invokeController(
    $smokeDb,
    'Inni\\Controllers\\ScanController',
    'resolve',
    'POST',
    ['csrf_token' => $sessionToken, 'code' => '전장-2024-017'],
    [],
    'demo-owner',
    $sessionToken
);
check(
    $scanTab['status'] === 302 && str_contains((string) $scanTab['location'], 'assets/show') && str_contains((string) $scanTab['location'], 'ast-dmm-1'),
    'scan tab still opens asset detail'
);

$deskMissing = invokeController($smokeDb, 'Inni\\Controllers\\DeskController', 'resolve', 'POST', [
    'code' => '전장-2024-017',
], [], 'demo-owner', $sessionToken);
check($deskMissing['status'] === 403 && ($deskMissing['asset'] ?? null) === 'available', 'desk resolve without CSRF must be 403');

$deskGet = invokeController($smokeDb, 'Inni\\Controllers\\DeskController', 'resolve', 'GET', [
    'csrf_token' => $sessionToken,
    'code' => '전장-2024-017',
], [], 'demo-owner', $sessionToken);
check($deskGet['status'] === 405 && ($deskGet['asset'] ?? null) === 'available', 'desk resolve GET must be 405');

$deskScan = invokeController($smokeDb, 'Inni\\Controllers\\DeskController', 'resolve', 'POST', [
    'csrf_token' => $sessionToken,
    'code' => '전장-2024-017',
], [], 'demo-owner', $sessionToken);
check(
    $deskScan['status'] === 302
    && str_contains((string) $deskScan['location'], 'loans/desk')
    && str_contains((string) $deskScan['location'], 'ast-dmm-1')
    && !str_contains((string) $deskScan['location'], 'assets/show'),
    'desk scan stays on the desk with the asset id'
);

$catalogScan = invokeController($smokeDb, 'Inni\\Controllers\\DeskController', 'resolve', 'POST', [
    'csrf_token' => $sessionToken,
    'code' => 'CAT:ci-solder',
], [], 'demo-owner', $sessionToken);
$catalogFlash = json_encode($catalogScan['flash'] ?? [], JSON_UNESCAPED_UNICODE);
check(
    $catalogScan['status'] === 302
    && str_contains((string) $catalogScan['location'], 'loans/desk')
    && str_contains((string) $catalogFlash, '장비 QR만'),
    'catalog QR on the desk does not become a loan'
);

$loanBad = invokeController($smokeDb, 'Inni\\Controllers\\DeskController', 'loan', 'POST', [
    'csrf_token' => 'wrong-token',
    'asset_id' => 'ast-dmm-1',
    'borrower_user_id' => 'demo-teacher',
], [], 'demo-owner', $sessionToken);
check($loanBad['status'] === 403 && ($loanBad['asset'] ?? null) === 'available', 'desk loan with a bad CSRF token must be 403');

$deskLoan = invokeController($smokeDb, 'Inni\\Controllers\\DeskController', 'loan', 'POST', [
    'csrf_token' => $sessionToken,
    'asset_id' => 'ast-dmm-1',
    'borrower_user_id' => 'demo-teacher',
], [], 'demo-owner', $sessionToken);
check(
    $deskLoan['status'] === 302
    && str_contains((string) $deskLoan['location'], 'loans/desk')
    && ($deskLoan['asset'] ?? null) === 'on_loan'
    && ($deskLoan['loan']['status'] ?? '') === 'active'
    && ($deskLoan['loan']['borrower_user_id'] ?? '') === 'demo-teacher',
    'desk loan marks the asset on_loan for the picked teacher'
);

$rescan = invokeController($smokeDb, 'Inni\\Controllers\\DeskController', 'resolve', 'POST', [
    'csrf_token' => $sessionToken,
    'code' => 'AST:ast-dmm-1',
], [], 'demo-owner', $sessionToken);
check(
    $rescan['status'] === 302
    && str_contains((string) $rescan['location'], 'loans/desk')
    && str_contains((string) $rescan['location'], 'ast-dmm-1'),
    'second desk scan of the same asset stays on the desk'
);

$loanId = (string) ($deskLoan['loan']['id'] ?? '');
$deskReturn = invokeController($smokeDb, 'Inni\\Controllers\\LoanController', 'returnLoan', 'POST', [
    'csrf_token' => $sessionToken,
    'loan_id' => $loanId,
    'return_to' => 'desk',
], [], 'demo-owner', $sessionToken);
check(
    $deskReturn['status'] === 302
    && str_contains((string) $deskReturn['location'], 'loans/desk')
    && !str_contains((string) $deskReturn['location'], 'assets/show')
    && ($deskReturn['asset'] ?? null) === 'available'
    && ($deskReturn['loan']['status'] ?? '') === 'returned',
    'desk return lands back on the desk and frees the asset'
);

$smokePdo = new PDO('sqlite:' . $smokeDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$smokePdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
    ->execute(['demo-student', 'student@demo.inni', '학생', 'student', 'active', 't', 't']);
$smokePdo = null;

$studentDesk = invokeController($smokeDb, 'Inni\\Controllers\\DeskController', 'loan', 'POST', [
    'csrf_token' => $sessionToken,
    'asset_id' => 'ast-drill-1',
    'borrower_user_id' => 'demo-teacher',
], [], 'demo-student', $sessionToken);
check(
    $studentDesk['status'] === 302
    && str_contains((string) $studentDesk['location'], 'home')
    && ($studentDesk['asset'] ?? null) === 'available',
    'student cannot loan from the desk'
);

foreach (glob($smokeDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($smokeDir);

echo "PASS: {$checks} desk checks\n";
