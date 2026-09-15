<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\Database;
use Inni\Loan;
use Inni\Report;
use Inni\Support;

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

function seedRepair(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
        $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '@test', $id, $role, 'active', 't', 't']);
    }
    foreach (['ast-1' => 'available', 'ast-2' => 'available', 'ast-loan' => 'available', 'ast-lost' => 'lost', 'ast-ret' => 'retired'] as $id => $status) {
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
        $pdo->query('SELECT id, status FROM reports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_type, entity_id FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
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

$teacher = actor('teacher', 'teacher');
$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$student = actor('student', 'student');
$pending = actor('pending-teacher', 'teacher', 'pending');

$pdo = memoryDb();
seedRepair($pdo);

check(Report::openCount($pdo) === 0 && Report::queue($pdo) === [], 'empty queue before any request');
check(Report::nextStatuses('open') === ['in_progress', 'rejected'], 'open may go to in_progress or rejected');
check(Report::nextStatuses('in_progress') === ['done', 'rejected'], 'in_progress may go to done or rejected');
check(Report::nextStatuses('done') === [] && Report::nextStatuses('rejected') === [], 'done and rejected are terminal');
check(Support::statusLabel('in_progress') === '수리중' && Support::statusLabel('rejected') === '불가', 'report status labels are 수리중/불가');
check(Report::titleFromSymptom("전원 안 켜짐\n깜빡임") === '전원 안 켜짐 깜빡임', 'title is derived from symptom');

reject(static function () use ($pdo, $student): void {
    Report::file($pdo, $student, 'ast-1', '안 켜짐');
}, $pdo, 'Student repair request');
reject(static function () use ($pdo, $pending): void {
    Report::file($pdo, $pending, 'ast-1', '안 켜짐');
}, $pdo, 'Pending teacher repair request');
reject(static function () use ($pdo, $teacher): void {
    Report::file($pdo, $teacher, 'ast-lost', '없어짐');
}, $pdo, 'Lost asset repair request');
reject(static function () use ($pdo, $teacher): void {
    Report::file($pdo, $teacher, 'ast-ret', '버림');
}, $pdo, 'Retired asset repair request');
reject(static function () use ($pdo, $teacher): void {
    Report::file($pdo, $teacher, 'ast-1', '   ');
}, $pdo, 'Empty symptom');

$rep1 = Report::file($pdo, $teacher, 'ast-1', '전원 안 켜짐, 팬 소음');
$asset1 = $pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn();
$report1 = $pdo->query("SELECT title, body, status, reporter_user_id FROM reports WHERE id=" . $pdo->quote($rep1))->fetch(PDO::FETCH_ASSOC);
check($asset1 === 'repair', 'available asset becomes repair on request');
check($report1['status'] === 'open', 'new report starts open');
check($report1['body'] === '전원 안 켜짐, 팬 소음', 'symptom is stored as body');
check($report1['title'] === '전원 안 켜짐, 팬 소음', 'title is derived when omitted');
check($report1['reporter_user_id'] === 'teacher', 'reporter is the teacher');
check(Report::openCount($pdo) === 1, 'open count includes the new request');

$logs = $pdo->query("SELECT action, entity_type, summary FROM activity_logs WHERE entity_id=" . $pdo->quote($rep1))->fetchAll(PDO::FETCH_ASSOC);
check($logs !== [], 'filing writes report activity history');
check(str_contains((string) ($logs[0]['summary'] ?? ''), '수리 요청'), 'request log mentions 수리 요청');

reject(static function () use ($pdo, $teacher, $rep1): void {
    Report::transition($pdo, $teacher, $rep1, 'in_progress');
}, $pdo, 'Teacher status change');
reject(static function () use ($pdo, $student, $rep1): void {
    Report::transition($pdo, $student, $rep1, 'in_progress');
}, $pdo, 'Student status change');
reject(static function () use ($pdo, $owner, $rep1): void {
    Report::transition($pdo, $owner, $rep1, 'done');
}, $pdo, 'Skip in_progress to done');

Report::transition($pdo, $manager, $rep1, 'in_progress');
check(
    $pdo->query("SELECT status FROM reports WHERE id=" . $pdo->quote($rep1))->fetchColumn() === 'in_progress',
    'manager advances open to in_progress'
);
check($pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn() === 'repair', 'asset stays repair while in progress');

$queue = Report::queue($pdo);
check(count($queue) === 1 && ($queue[0]['id'] ?? '') === $rep1, 'default queue lists open work');
check(($queue[0]['asset_name'] ?? '') === 'ast-1', 'queue joins asset name');

Report::transition($pdo, $owner, $rep1, 'done');
check(
    $pdo->query("SELECT status FROM reports WHERE id=" . $pdo->quote($rep1))->fetchColumn() === 'done',
    'owner completes in_progress to done'
);
check($pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn() === 'available', 'idle asset returns to available on done');
check(Report::openCount($pdo) === 0, 'completed report leaves the open count');
check(Report::queue($pdo) === [], 'completed report leaves the default queue');
$doneQueue = Report::queue($pdo, ['status' => 'done']);
check(count($doneQueue) === 1 && ($doneQueue[0]['id'] ?? '') === $rep1, 'done filter lists completed reports');

$hist = Report::logs($pdo, $rep1);
check(count($hist) >= 3, 'request + two transitions leave activity history');
$summaries = implode(' ', array_map(static fn (array $row): string => (string) $row['summary'], $hist));
check(str_contains($summaries, '접수') && str_contains($summaries, '수리중') && str_contains($summaries, '완료'), 'history names each status');

$repReject = Report::file($pdo, $teacher, 'ast-2', '보드 탄 냄새', '탄 냄새');
check($pdo->query("SELECT status FROM assets WHERE id='ast-2'")->fetchColumn() === 'repair', 'second available asset also goes to repair');
Report::transition($pdo, $owner, $repReject, 'rejected');
check(
    $pdo->query("SELECT status FROM reports WHERE id=" . $pdo->quote($repReject))->fetchColumn() === 'rejected',
    'open may go directly to 불가'
);
check($pdo->query("SELECT status FROM assets WHERE id='ast-2'")->fetchColumn() === 'repair', '불가 keeps the asset in repair');
reject(static function () use ($pdo, $owner, $repReject): void {
    Report::transition($pdo, $owner, $repReject, 'open');
}, $pdo, 'Rejected report reopen');

$due = gmdate('c', time() + 3600);
$loanId = Loan::checkout($pdo, $teacher, 'ast-loan', 'teacher', null, '수업', $due);
check($pdo->query("SELECT status FROM assets WHERE id='ast-loan'")->fetchColumn() === 'on_loan', 'checkout still claims available assets');
$repLoan = Report::file($pdo, $teacher, 'ast-loan', '대여 중 파손');
check($pdo->query("SELECT status FROM assets WHERE id='ast-loan'")->fetchColumn() === 'on_loan', 'on-loan asset is not flipped to repair');
check(
    $pdo->query("SELECT status FROM reports WHERE id=" . $pdo->quote($repLoan))->fetchColumn() === 'open',
    'repair can still be filed while on loan'
);
Loan::checkin($pdo, $teacher, $loanId);
check($pdo->query("SELECT status FROM assets WHERE id='ast-loan'")->fetchColumn() === 'available', 'return still frees on_loan assets');
Report::transition($pdo, $owner, $repLoan, 'in_progress');
check($pdo->query("SELECT status FROM assets WHERE id='ast-loan'")->fetchColumn() === 'repair', 'starting repair after return marks the asset');

$repKeep = Report::file($pdo, $teacher, 'ast-1', '또 안 켜짐');
$repKeep2 = Report::file($pdo, $teacher, 'ast-1', '케이블 불량');
check($pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn() === 'repair', 'second request on available asset sets repair');
Report::transition($pdo, $owner, $repKeep, 'in_progress');
Report::transition($pdo, $owner, $repKeep, 'done');
check($pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn() === 'repair', 'done does not free the asset while another report is open');
Report::transition($pdo, $owner, $repKeep2, 'in_progress');
Report::transition($pdo, $owner, $repKeep2, 'done');
check($pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn() === 'available', 'last open report done restores available');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('PRAGMA foreign_keys = ON');
$old->exec("CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$old->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id), name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec("CREATE TABLE loans (id TEXT PRIMARY KEY, kind TEXT NOT NULL, asset_id TEXT, status TEXT NOT NULL DEFAULT 'active')");
$old->exec(
    "CREATE TABLE reports (
      id TEXT PRIMARY KEY,
      target_type TEXT NOT NULL,
      target_id TEXT NOT NULL,
      reporter_user_id TEXT,
      reporter_name TEXT NOT NULL,
      title TEXT NOT NULL,
      body TEXT NOT NULL,
      image_path TEXT,
      status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','done')),
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    )"
);
$old->exec("INSERT INTO reports(id,target_type,target_id,reporter_name,title,body,status,created_at,updated_at) VALUES('rep-old','asset','ast-1','교사','송급','끊김','open','t','t')");
Database::migrate($old);
$oldSql = (string) $old->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='reports'")->fetchColumn();
check(str_contains($oldSql, "'rejected'"), 'migrate rebuilds reports CHECK to allow rejected');
check($old->query("SELECT status FROM reports WHERE id='rep-old'")->fetchColumn() === 'open', 'legacy open report survives migrate');
$old->prepare("UPDATE reports SET status='rejected' WHERE id='rep-old'")->execute();
check($old->query("SELECT status FROM reports WHERE id='rep-old'")->fetchColumn() === 'rejected', 'migrated table accepts rejected');
Database::migrate($old);
check($old->query("SELECT status FROM reports WHERE id='rep-old'")->fetchColumn() === 'rejected', 'migrate is idempotent for reports');

$router = (string) file_get_contents($root . '/app/Router.php');
$assetCtl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
$reportCtl = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
$homeCtl = (string) file_get_contents($root . '/app/Controllers/HomeController.php');
$loanCtl = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
$loanPhp = (string) file_get_contents($root . '/app/Loan.php');
$showTpl = (string) file_get_contents($root . '/templates/assets/show.php');
$queueTpl = (string) file_get_contents($root . '/templates/reports/index.php');
$detailTpl = (string) file_get_contents($root . '/templates/reports/show.php');
$partial = (string) file_get_contents($root . '/templates/partials/report_status.php');
$homeTpl = (string) file_get_contents($root . '/templates/home/index.php');
$moreTpl = (string) file_get_contents($root . '/templates/more/index.php');
$mineTpl = (string) file_get_contents($root . '/templates/loans/mine.php');
$layout = (string) file_get_contents($root . '/templates/layouts/app.php');
$schema = (string) file_get_contents($root . '/sql/schema.sql');

check(str_contains($router, "'reports' => [ReportController::class, 'index']"), 'reports GET queue is registered');
check(str_contains($router, "'reports/show' => [ReportController::class, 'show']"), 'reports/show is registered');
check(str_contains($router, "'reports/status' => [ReportController::class, 'updateStatus']"), 'reports/status POST is registered');
check(str_contains($router, "'loans/mine' => [LoanController::class, 'mine']"), 'loans/mine stays registered');

check(str_contains($assetCtl, 'Auth::canLoan($user)') && str_contains($assetCtl, 'function report'), 'asset report requires canLoan');
check(str_contains($assetCtl, 'Report::file') && str_contains($assetCtl, 'Csrf::requirePost()'), 'asset report uses Report::file and POST+CSRF');
check(str_contains($reportCtl, 'Auth::canWrite($user)') && str_contains($reportCtl, 'function updateStatus'), 'status change requires canWrite');
check(str_contains($reportCtl, 'Csrf::requirePost()'), 'status change is POST+CSRF');
check(str_contains($reportCtl, "'reports/show'") && str_contains($reportCtl, "'assets/show'"), 'return_to is allowlisted');
check(!str_contains($reportCtl, 'http://') && !str_contains($reportCtl, '$_GET[\'url\']'), 'return_to is not an open redirect');

check(str_contains($showTpl, '수리 요청') && str_contains($showTpl, 'name="body"'), 'asset detail has a symptom field');
check(str_contains($showTpl, 'Auth::canLoan($user)') && str_contains($showTpl, "App::url('assets/report')"), 'request form is gated on canLoan');
check(!str_contains($showTpl, 'name="title"'), 'teacher form does not ask for a separate title');
check(str_contains($showTpl, '사진 (선택)'), 'photo is optional');
check(str_contains($showTpl, 'partials/report_status.php'), 'asset detail reuses manager status actions');
check(!str_contains($showTpl, 'new Vue') && !str_contains($queueTpl, 'createApp'), 'repair UI stays SSR');

check(str_contains($queueTpl, '<h1>수리 대기</h1>'), 'queue keeps the Korean title');
check(str_contains($queueTpl, "App::url('reports/show'") && str_contains($detailTpl, '이력'), 'queue links to detail with history');
check(str_contains($partial, 'Csrf::field()') && str_contains($partial, "App::url('reports/status')"), 'status actions post with CSRF');
check(str_contains($partial, 'Auth::canWrite($user)'), 'status buttons are canWrite-only');

check(str_contains($homeCtl, 'Report::openCount'), 'home counts open repair work');
check(str_contains($homeTpl, "App::url('reports')") && str_contains($homeTpl, '수리'), 'home links managers to the queue');
check(str_contains($homeTpl, 'Auth::canWrite($user)'), 'home repair entry is gated on canWrite');
check(str_contains($moreTpl, "App::url('reports')") && str_contains($moreTpl, '수리 대기'), 'more menu links managers to the queue');
check(str_contains($moreTpl, '내 대여함') && str_contains($moreTpl, "App::url('loans/mine')"), 'more still links teachers to 내 대여함');
check(str_contains($layout, "\$current === 'reports' || str_starts_with(\$current, 'reports/')"), 'queue keeps the 더보기 tab active');
check(preg_match("/\\\$tabs = \\[\\s*\\['home', '홈'\\],\\s*\\['search', '찾기'\\],\\s*\\['scan', '스캔'\\],\\s*\\['rooms', '실'\\],\\s*\\['more', '더보기'\\],\\s*\\];/", $layout) === 1, 'bottom nav stays 홈/찾기/스캔/실/더보기');

check(str_contains($mineTpl, '<h1>내 대여함</h1>') && str_contains($loanCtl, 'function mine'), 'my-loans inbox is unchanged');
check(str_contains($loanPhp, "WHERE id = ? AND status = 'available'") && str_contains($loanPhp, "status = 'on_loan'"), 'loan checkout/checkin conditions are unchanged');
check(str_contains($schema, "'rejected'"), 'schema allows rejected reports');

echo "PASS: {$checks} repair checks\n";
