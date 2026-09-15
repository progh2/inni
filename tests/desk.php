<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\Csrf;
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

function idsOf(array $rows): array
{
    return array_values(array_map(static fn(array $row): string => (string) $row['id'], $rows));
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
    foreach (['ast-1' => 'available', 'ast-2' => 'available', 'ast-repair' => 'repair'] as $id => $status) {
        $pdo->prepare(
            'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        )->execute([$id, 'eq', $id, $id, $status, 'room', 'AST:' . $id, 't', 't']);
    }
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, asset_id, status, borrower_user_id, created_by FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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

function seedFileDb(string $dbPath): void
{
    global $root;
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
    seedDesk($pdo);
}

/**
 * @param array<string, mixed> $get
 * @param array<string, mixed> $post
 * @return array{status: int, location: string, flash: list<array<string, mixed>>, body: string}
 */
function invokeDeskCtrl(
    string $dbPath,
    string $class,
    string $method,
    string $httpMethod,
    array $get,
    array $post,
    string $userId,
    ?string $csrf,
): array {
    global $root;
    $tmp = sys_get_temp_dir() . '/inni-desk-ctrl-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $runner = $tmp . '/run.php';
    $resultFile = $tmp . '/result.json';
    file_put_contents($tmp . '/payload.json', json_encode([
        'root' => $root,
        'db' => $dbPath,
        'class' => $class,
        'method' => $method,
        'http' => $httpMethod,
        'get' => $get,
        'post' => $post,
        'user' => $userId,
        'csrf' => $csrf,
        'result' => $resultFile,
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
$_GET = $p['get'];
$_POST = $p['post'];
$_SESSION = ['user_id' => $p['user']];
if (is_string($p['csrf'])) {
    $_SESSION[Csrf::SESSION_KEY] = $p['csrf'];
}

$GLOBALS['inni_headers'] = [];
header_register_callback(static function (): void {
    $GLOBALS['inni_headers'] = headers_list();
});
register_shutdown_function(static function () use ($p): void {
    $headers = $GLOBALS['inni_headers'] ?? headers_list();
    $location = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    file_put_contents($p['result'], json_encode([
        'status' => http_response_code(),
        'location' => $location,
        'flash' => $_SESSION['_flash'] ?? [],
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
    $decoded = json_decode($raw, true);
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
    if (!is_array($decoded)) {
        throw new RuntimeException('Desk harness failed: ' . $raw . $stderr);
    }
    return $decoded;
}

$owner = actor('owner', 'owner');
$teacher = actor('teacher', 'teacher');
$teacherB = actor('teacher-b', 'teacher');
$student = actor('student', 'student');

$pdo = memoryDb();
seedDesk($pdo);

$morning = new DateTimeImmutable('2026-09-15 10:00:00');
$evening = new DateTimeImmutable('2026-09-15 16:00:00');
check(Desk::defaultDueLocal($morning) === '2026-09-15T16:00', 'morning defaults to today 16:00');
check(Desk::defaultDueLocal($evening) === '2026-09-16T16:00', 'at/after 16:00 defaults to next day 16:00');
check(preg_match('/^\d{4}-\d{2}-\d{2}T16:00$/', Desk::defaultDueLocal()) === 1, 'live default due is datetime-local 16:00');

$teachers = Desk::teachers($pdo);
$teacherIds = array_map(static fn(array $row): string => $row['id'], $teachers);
check($teacherIds === ['manager', 'owner', 'teacher', 'teacher-b'], 'teacher picker is active staff only, sorted by name');
check(!in_array('student', $teacherIds, true) && !in_array('pending', $teacherIds, true), 'students and pending are not desk counterparties');

$byQr = Desk::resolveAsset($pdo, 'AST:ast-1');
$byNumber = Desk::resolveAsset($pdo, 'ast-1');
check(is_array($byQr) && ($byQr['id'] ?? '') === 'ast-1', 'QR payload resolves to the asset card');
check(is_array($byNumber) && ($byNumber['id'] ?? '') === 'ast-1', 'management number resolves to the asset card');
check(Desk::resolveAsset($pdo, 'CAT:eq') === null, 'catalog codes do not open the desk card');
check(Desk::resolveAsset($pdo, 'LOC:room') === null, 'location codes do not open the desk card');
check(Desk::resolveAsset($pdo, 'missing') === null, 'unknown codes stay on the desk');
check(Scan::lookup($pdo, 'AST:ast-1') === ['kind' => 'asset', 'id' => 'ast-1'], 'Scan::lookup stays the shared resolver');

$hits = Desk::searchAssets($pdo, 'ast-1');
check(count($hits) === 1 && ($hits[0]['id'] ?? '') === 'ast-1', 'exact code search returns the single asset');
$named = Desk::searchAssets($pdo, 'ast-');
check(count($named) >= 2, 'partial name search lists matching assets');

$due = date('c', strtotime(Desk::defaultDueLocal($morning)));
$loanId = Loan::checkout($pdo, $teacher, 'ast-1', '', null, '데스크', $due, 'teacher-b');
$row = $pdo->prepare('SELECT borrower_user_id, borrower_name, created_by, due_at, status FROM loans WHERE id = ?');
$row->execute([$loanId]);
$loan = $row->fetch(PDO::FETCH_ASSOC);
check(($loan['borrower_user_id'] ?? '') === 'teacher-b', 'desk checkout stores the selected teacher as borrower');
check(($loan['borrower_name'] ?? '') === 'teacher-b', 'empty name falls back to selected teacher');
check(($loan['created_by'] ?? '') === 'teacher', 'created_by stays the desk actor');
check(($loan['status'] ?? '') === 'active', 'desk checkout opens an active loan');
check(($pdo->query("SELECT status FROM assets WHERE id = 'ast-1'")->fetchColumn() === 'on_loan'), 'desk checkout marks the asset on_loan');
$open = Desk::openLoan($pdo, 'ast-1');
check(is_array($open) && ($open['id'] ?? '') === $loanId, 'open loan is visible on the desk card');
check(idsOf(Loan::inboxForUser($pdo, 'teacher-b')) === [$loanId], 'counterparty teacher sees the desk loan in inbox');
check(Loan::inboxForUser($pdo, 'teacher') === [], 'desk actor is not the inbox borrower');

$returned = Loan::checkin($pdo, $teacher, $loanId);
check($returned === 'ast-1', 'desk checkin returns the asset id');
check($pdo->query("SELECT status FROM assets WHERE id = 'ast-1'")->fetchColumn() === 'available', 'desk checkin frees the asset');
check(Desk::openLoan($pdo, 'ast-1') === null, 'returned loan leaves the desk card lendable');

reject(static function () use ($pdo, $student): void {
    Loan::checkout($pdo, $student, 'ast-2', '학생', null, '신청', null, 'teacher');
}, $pdo, 'Student desk checkout');
reject(static function () use ($pdo, $teacher): void {
    Loan::checkout($pdo, $teacher, 'ast-2', '학생', null, '신청', null, 'student');
}, $pdo, 'Student as desk borrower');
reject(static function () use ($pdo, $teacher): void {
    Loan::checkout($pdo, $teacher, 'ast-2', '없음', null, '신청', null, 'missing-user');
}, $pdo, 'Missing desk borrower');

$_SESSION = [];
Desk::rememberBorrower('teacher-b');
check(Desk::rememberedBorrower() === 'teacher-b', 'desk remembers the last teacher');
Desk::rememberBorrower('');
check(Desk::rememberedBorrower() === null, 'empty remember clears the last teacher');

$router = (string) file_get_contents($root . '/app/Router.php');
$deskCtl = (string) file_get_contents($root . '/app/Controllers/DeskController.php');
$assetCtl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
$loanCtl = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
$scanCtl = (string) file_get_contents($root . '/app/Controllers/ScanController.php');
$deskTpl = (string) file_get_contents($root . '/templates/loans/desk.php');
$homeTpl = (string) file_get_contents($root . '/templates/home/index.php');
$moreTpl = (string) file_get_contents($root . '/templates/more/index.php');
$scanTpl = (string) file_get_contents($root . '/templates/scan/index.php');
$layout = (string) file_get_contents($root . '/templates/layouts/app.php');

check(str_contains($router, "'loans/desk' => [DeskController::class, 'index']"), 'desk GET route is registered');
check(str_contains($router, "'loans/desk/resolve' => [DeskController::class, 'resolve']"), 'desk resolve POST route is registered');
check(str_contains($deskCtl, 'Auth::canLoan($user)') && str_contains($deskCtl, 'Csrf::requirePost()'), 'desk resolve is canLoan + POST+CSRF');
check(substr_count($deskCtl, 'Auth::canLoan($user)') >= 2, 'desk index and resolve both require canLoan');
check(str_contains($deskCtl, 'Desk::resolveAsset') && str_contains($deskCtl, 'Scan::lookup') === false, 'resolve reuses Desk/Scan, not a new lookup');
check(str_contains($deskCtl, "App::redirect('loans/desk', ['id' => \$asset['id']])"), 'successful resolve keeps the scanned asset on the desk');
check(str_contains($deskCtl, 'Desk::teachers') && str_contains($deskCtl, 'Desk::defaultDueLocal'), 'desk loads teacher picker and default due');

check(str_contains($assetCtl, "(\$_POST['return_to'] ?? '') === 'desk'"), 'loan POST can land back on the desk');
check(str_contains($assetCtl, 'borrower_user_id') && str_contains($assetCtl, 'Loan::checkout'), 'loan POST passes selected teacher into checkout');
check(str_contains($loanCtl, "'desk' => 'loans/desk'") && str_contains($loanCtl, 'return_to'), 'return POST allowlists desk');
check(!str_contains($loanCtl, 'http://') && !str_contains($loanCtl, "\$_GET['url']"), 'desk return_to is not an open redirect');
check(str_contains($scanCtl, "App::redirect('assets/show'") && str_contains($scanCtl, "App::url('scan')") === false, 'plain scan/resolve still goes to asset detail');
check(str_contains($scanCtl, "App::redirect('items/show'") && str_contains($scanCtl, "App::redirect('rooms/show'"), 'plain scan still opens catalog and rooms');

check(str_contains($deskTpl, '<h1>대여 데스크</h1>'), 'desk keeps the Korean title');
check(str_contains($deskTpl, '빌려주기') && str_contains($deskTpl, '받아주기'), 'desk has lend and take-back actions');
check(str_contains($deskTpl, 'name="borrower_user_id"') && str_contains($deskTpl, '빌리는 교사'), 'desk picks a counterparty teacher');
check(str_contains($deskTpl, 'name="due_at"') && str_contains($deskTpl, '$defaultDue'), 'desk prefills the default due date');
check(str_contains($deskTpl, "App::url('loans/desk/resolve')") && str_contains($deskTpl, 'Csrf::field()'), 'desk scan posts to desk/resolve with CSRF');
check(str_contains($deskTpl, "App::url('assets/loan')") && str_contains($deskTpl, 'value="desk"'), 'lend posts to assets/loan and stays on desk');
check(str_contains($deskTpl, "App::url('loans/return')") && str_contains($deskTpl, '받아주기'), 'take-back posts to loans/return');
check(str_contains($deskTpl, 'Auth::canReturn($user, $loan)'), 'take-back uses canReturn');
check(!str_contains($deskTpl, 'name="purpose"') && !str_contains($deskTpl, 'name="borrower_note"'), 'desk skips extra fields so loan is one tap after scan');
check(!str_contains($deskTpl, 'new Vue') && !str_contains($deskTpl, 'createApp'), 'desk stays SSR, no Composer SPA');
check(!str_contains($deskTpl, 'retire') && !str_contains($deskTpl, '파기'), 'desk does not add destroy/retire');

check(str_contains($homeTpl, "App::url('loans/desk')") && str_contains($homeTpl, '대여 데스크'), 'home links staff to the desk');
check(str_contains($moreTpl, "App::url('loans/desk')") && str_contains($moreTpl, '대여 데스크'), 'more menu links staff to the desk');
check(str_contains($scanTpl, "App::url('loans/desk')") && str_contains($scanTpl, "App::url('scan/resolve')"), 'scan tab still resolves to detail and points staff at the desk');
check(preg_match("/\\\$tabs = \\[\\s*\\['home', '홈'\\],\\s*\\['search', '찾기'\\],\\s*\\['scan', '스캔'\\],\\s*\\['rooms', '실'\\],\\s*\\['more', '더보기'\\],\\s*\\];/", $layout) === 1, 'bottom nav stays 홈/찾기/스캔/실/더보기');
check(str_contains($layout, "\$current === 'loans' || str_starts_with(\$current, 'loans/')"), 'desk keeps the 더보기 tab active');

$raceDir = sys_get_temp_dir() . '/inni-desk-db-' . bin2hex(random_bytes(4));
mkdir($raceDir, 0700);
$dbPath = $raceDir . '/inni.sqlite';
seedFileDb($dbPath);
$sessionToken = bin2hex(random_bytes(32));

$denied = invokeDeskCtrl($dbPath, 'Inni\\Controllers\\DeskController', 'resolve', 'POST', [], [
    'csrf_token' => $sessionToken,
    'code' => 'AST:ast-1',
], 'student', $sessionToken);
check(($denied['status'] ?? 0) === 302, 'student desk resolve redirects away');
check(($denied['flash'][0]['message'] ?? '') === '대여 데스크 권한이 없습니다.', 'student sees the desk permission flash');

$beforeLoans = (int) (new PDO('sqlite:' . $dbPath))->query('SELECT COUNT(*) FROM loans')->fetchColumn();
$studentLoan = invokeDeskCtrl($dbPath, 'Inni\\Controllers\\AssetController', 'loan', 'POST', [], [
    'csrf_token' => $sessionToken,
    'asset_id' => 'ast-1',
    'borrower_user_id' => 'teacher',
    'due_at' => '2026-09-15T16:00',
    'return_to' => 'desk',
], 'student', $sessionToken);
$afterStudent = (int) (new PDO('sqlite:' . $dbPath))->query('SELECT COUNT(*) FROM loans')->fetchColumn();
check($afterStudent === $beforeLoans, 'student cannot create a desk loan');
check(($studentLoan['flash'][0]['message'] ?? '') === '대여 권한이 없습니다.', 'student loan POST is rejected');

$resolved = invokeDeskCtrl($dbPath, 'Inni\\Controllers\\DeskController', 'resolve', 'POST', [], [
    'csrf_token' => $sessionToken,
    'code' => 'AST:ast-1',
], 'teacher', $sessionToken);
check(($resolved['status'] ?? 0) === 302, 'staff resolve redirects to the desk card');
check(($resolved['flash'] ?? []) === [], 'staff resolve of a known asset has no error flash');

$badCsrf = invokeDeskCtrl($dbPath, 'Inni\\Controllers\\DeskController', 'resolve', 'POST', [], [
    'csrf_token' => 'wrong',
    'code' => 'AST:ast-2',
], 'teacher', $sessionToken);
check(($badCsrf['status'] ?? 0) === 403, 'desk resolve rejects a bad CSRF token');

$lent = invokeDeskCtrl($dbPath, 'Inni\\Controllers\\AssetController', 'loan', 'POST', [], [
    'csrf_token' => $sessionToken,
    'asset_id' => 'ast-1',
    'borrower_user_id' => 'teacher-b',
    'due_at' => '2026-09-15T16:00',
    'return_to' => 'desk',
], 'teacher', $sessionToken);
$harness = new PDO('sqlite:' . $dbPath);
$openLoan = $harness->query("SELECT id, borrower_user_id, status FROM loans WHERE asset_id = 'ast-1' AND status IN ('active','overdue')")->fetch(PDO::FETCH_ASSOC);
check(is_array($openLoan) && ($openLoan['borrower_user_id'] ?? '') === 'teacher-b', 'resolve→loan happy path names the selected teacher');
check(($harness->query("SELECT status FROM assets WHERE id = 'ast-1'")->fetchColumn() === 'on_loan'), 'resolve→loan marks the asset on_loan');
check(($lent['flash'][0]['message'] ?? '') === '대여 처리되었습니다.', 'desk loan flashes success');
check(($lent['status'] ?? 0) === 302, 'desk loan redirects back to the desk');

$taken = invokeDeskCtrl($dbPath, 'Inni\\Controllers\\LoanController', 'returnLoan', 'POST', [], [
    'csrf_token' => $sessionToken,
    'loan_id' => (string) $openLoan['id'],
    'return_to' => 'desk',
], 'teacher', $sessionToken);
$harness = new PDO('sqlite:' . $dbPath);
check($harness->query('SELECT status FROM loans WHERE id = ' . $harness->quote((string) $openLoan['id']))->fetchColumn() === 'returned', 'desk take-back closes the loan');
check($harness->query("SELECT status FROM assets WHERE id = 'ast-1'")->fetchColumn() === 'available', 'desk take-back frees the asset');
check(($taken['flash'][0]['message'] ?? '') === '반납 처리되었습니다.', 'desk return flashes success');
check(($taken['status'] ?? 0) === 302, 'desk return redirects back to the desk');

foreach (glob($raceDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($raceDir);

echo "PASS: {$checks} desk checks\n";
