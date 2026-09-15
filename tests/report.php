<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\Database;
use Inni\Loan;
use Inni\Report;
use Inni\Support;

$root = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
seedReportFixture($pdo);

$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');
$student = actor('student', 'student');
$disabled = actor('disabled', 'teacher', 'disabled');
$pending = actor('pending', 'teacher', 'pending');
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

function seedReportFixture(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
        $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '@test', $id, $role, 'active', 't', 't']);
    }
    foreach (['ast-1', 'ast-2', 'ast-3', 'ast-loan'] as $id) {
        $pdo->prepare(
            'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        )->execute([$id, 'eq', $id, $id, 'available', 'room', 'AST:' . $id, 't', 't']);
    }
    $pdo->prepare("UPDATE assets SET status = 'on_loan' WHERE id = 'ast-loan'")->execute();
    $pdo->prepare(
        "INSERT INTO loans(id,kind,asset_id,quantity,borrower_user_id,borrower_name,from_location_id,status,purpose,created_at,created_by)
         VALUES('loan-open','asset','ast-loan',1,'teacher','teacher','room','active','수업','t','teacher')"
    )->execute();
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status FROM reports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_id FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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

function assetStatus(PDO $pdo, string $id): string
{
    $stmt = $pdo->prepare('SELECT status FROM assets WHERE id = ?');
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
}

function reportStatus(PDO $pdo, string $id): string
{
    $stmt = $pdo->prepare('SELECT status FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
}

check(Report::canCreate($teacher) && Report::canCreate($owner) && Report::canCreate($manager), 'teacher/owner/manager may create reports');
check(!Report::canCreate($student) && !Report::canCreate($disabled) && !Report::canCreate($pending) && !Report::canCreate(null), 'student/pending/disabled cannot create');
check(Report::canTransition($owner) && Report::canTransition($manager), 'owner/manager may change status');
check(!Report::canTransition($teacher) && !Report::canTransition($student) && !Auth::canWrite($teacher), 'teacher cannot change status');
check(Support::statusLabel('open') === '접수' && Support::statusLabel('in_progress') === '수리중', 'Korean labels for 접수/수리중');
check(Support::statusLabel('done') === '완료' && Support::statusLabel('impossible') === '불가', 'Korean labels for 완료/불가');

reject(static function () use ($pdo, $student): void {
    Report::create($pdo, $student, 'asset', 'ast-1', '고장', '안 켜짐');
}, $pdo, 'Student create');
reject(static function () use ($pdo, $disabled): void {
    Report::create($pdo, $disabled, 'asset', 'ast-1', '고장', '안 켜짐');
}, $pdo, 'Disabled create');
reject(static function () use ($pdo, $teacher): void {
    Report::create($pdo, $teacher, 'asset', 'ast-1', '', '증상만');
}, $pdo, 'Empty title');
reject(static function () use ($pdo, $teacher): void {
    Report::create($pdo, $teacher, 'asset', 'missing', '고장', '없는 장비');
}, $pdo, 'Missing asset');

$openId = Report::create($pdo, $teacher, 'asset', 'ast-1', '전원 안 켜짐', '어제부터 꺼집니다.');
check(reportStatus($pdo, $openId) === Report::STATUS_OPEN, 'Create starts at open(접수)');
check(assetStatus($pdo, 'ast-1') === 'repair', 'Available asset becomes repair on open report');
$createLog = $pdo->query(
    'SELECT action, summary, meta_json FROM activity_logs WHERE entity_id = ' . $pdo->quote($openId) . " AND action = 'report'"
)->fetch(PDO::FETCH_ASSOC);
check(($createLog['action'] ?? '') === 'report' && str_contains((string) $createLog['summary'], '접수'), 'Create writes history');

reject(static function () use ($pdo, $teacher, $openId): void {
    Report::transition($pdo, $teacher, $openId, Report::STATUS_IN_PROGRESS);
}, $pdo, 'Teacher transition');
reject(static function () use ($pdo, $student, $openId): void {
    Report::transition($pdo, $student, $openId, Report::STATUS_IN_PROGRESS);
}, $pdo, 'Student transition');
check(reportStatus($pdo, $openId) === Report::STATUS_OPEN && assetStatus($pdo, 'ast-1') === 'repair', 'Rejected transitions leave 접수 + repair');

reject(static function () use ($pdo, $manager, $openId): void {
    Report::transition($pdo, $manager, $openId, 'lost');
}, $pdo, 'Invented status');
reject(static function () use ($pdo, $manager, $openId): void {
    Report::transition($pdo, $manager, $openId, Report::STATUS_OPEN);
}, $pdo, 'Same-status transition');

Report::transition($pdo, $manager, $openId, Report::STATUS_IN_PROGRESS, '입고 후 점검');
check(reportStatus($pdo, $openId) === Report::STATUS_IN_PROGRESS, 'Manager open → in_progress');
check(assetStatus($pdo, 'ast-1') === 'repair', 'In-progress keeps repair');
$progressLog = $pdo->query(
    "SELECT summary, meta_json FROM activity_logs WHERE entity_id = " . $pdo->quote($openId) . " AND action = 'report_status' ORDER BY rowid DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
check(str_contains((string) $progressLog['summary'], '수리중') && str_contains((string) $progressLog['summary'], '입고 후 점검'), 'Transition history records 수리중 + note');
$meta = json_decode((string) $progressLog['meta_json'], true);
check(is_array($meta) && ($meta['from'] ?? '') === 'open' && ($meta['to'] ?? '') === 'in_progress', 'History meta stores from/to');

reject(static function () use ($pdo, $manager, $openId): void {
    Report::transition($pdo, $manager, $openId, Report::STATUS_OPEN);
}, $pdo, 'Backward in_progress → open');

Report::transition($pdo, $owner, $openId, Report::STATUS_DONE);
check(reportStatus($pdo, $openId) === Report::STATUS_DONE, 'Owner in_progress → done');
check(assetStatus($pdo, 'ast-1') === 'available', 'Last closed report returns available');
reject(static function () use ($pdo, $manager, $openId): void {
    Report::transition($pdo, $manager, $openId, Report::STATUS_IN_PROGRESS);
}, $pdo, 'Terminal done');

$second = Report::create($pdo, $teacher, 'asset', 'ast-1', '팬 소음', '냉각팬이 거칠습니다.');
$third = Report::create($pdo, $owner, 'asset', 'ast-1', '화면 깜빡임', '간헐적으로 꺼집니다.');
check(assetStatus($pdo, 'ast-1') === 'repair', 'Second open report keeps repair');
Report::transition($pdo, $manager, $second, Report::STATUS_IMPOSSIBLE, '부품 단종');
check(reportStatus($pdo, $second) === Report::STATUS_IMPOSSIBLE, 'open → impossible allowed');
check(assetStatus($pdo, 'ast-1') === 'repair', 'Sibling open report keeps repair');
Report::transition($pdo, $manager, $third, Report::STATUS_DONE);
check(assetStatus($pdo, 'ast-1') === 'available', 'Last sibling close restores available');

$onLoan = Report::create($pdo, $teacher, 'asset', 'ast-loan', '대여 중 파손', '렌즈가 깨졌습니다.');
check(assetStatus($pdo, 'ast-loan') === 'on_loan', 'Open report does not steal on_loan');
Loan::checkin($pdo, $teacher, 'loan-open');
check(assetStatus($pdo, 'ast-loan') === 'repair', 'Return with open report lands on repair, not available');
Report::transition($pdo, $manager, $onLoan, Report::STATUS_DONE);
check(assetStatus($pdo, 'ast-loan') === 'available', 'Closing after return restores available');

$pdo->prepare("UPDATE assets SET status = 'lost' WHERE id = 'ast-2'")->execute();
$lostReport = Report::create($pdo, $teacher, 'asset', 'ast-2', '분실 추정', '실에서 안 보입니다.');
check(assetStatus($pdo, 'ast-2') === 'lost', 'Lost asset stays lost on report');
Report::transition($pdo, $manager, $lostReport, Report::STATUS_DONE);
check(assetStatus($pdo, 'ast-2') === 'lost', 'Closing does not invent available from lost');

$directDone = Report::create($pdo, $teacher, 'asset', 'ast-3', '즉시 완료 대상', '테스트');
Report::transition($pdo, $manager, $directDone, Report::STATUS_DONE);
check(reportStatus($pdo, $directDone) === Report::STATUS_DONE && assetStatus($pdo, 'ast-3') === 'available', 'open → done is allowed');

$history = Report::history($pdo, $openId);
$actions = array_map(static fn(array $row): string => (string) $row['action'], $history);
check($actions === ['report', 'report_status', 'report_status'], 'History is create + two transitions');
check(count(Report::queue($pdo, 'queue')) === 0, 'Queue default hides closed rows');
check(count(Report::queue($pdo, 'done')) >= 2, 'Done filter lists completed reports');
check(count(Report::queue($pdo, 'impossible')) === 1, 'Impossible filter lists 불가');
check(Report::openCount($pdo) === 0, 'No open/in_progress after closing all');

$legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy->exec('PRAGMA foreign_keys = ON');
$legacy->exec("CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$legacy->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$legacy->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$legacy->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id), name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$legacy->exec("CREATE TABLE loans (id TEXT PRIMARY KEY, kind TEXT NOT NULL, asset_id TEXT, status TEXT NOT NULL DEFAULT 'active')");
$legacy->exec(
    "CREATE TABLE reports (
      id TEXT PRIMARY KEY,
      target_type TEXT NOT NULL CHECK(target_type IN ('room','asset')),
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
$legacy->exec("INSERT INTO reports VALUES('rep-old','asset','ast-1',NULL,'교사','구요청','본문',NULL,'open','t','t')");
Database::migrate($legacy);
$ddl = (string) $legacy->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='reports'")->fetchColumn();
check(str_contains($ddl, 'impossible'), 'Migrate adds impossible to reports CHECK');
check($legacy->query("SELECT status FROM reports WHERE id='rep-old'")->fetchColumn() === 'open', 'Legacy rows survive rebuild');
$legacy->prepare("UPDATE reports SET status = 'impossible' WHERE id = 'rep-old'")->execute();
check($legacy->query("SELECT status FROM reports WHERE id='rep-old'")->fetchColumn() === 'impossible', 'Migrated table accepts 불가');
Database::migrate($legacy);
check(str_contains((string) $legacy->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='reports'")->fetchColumn(), 'impossible'), 'Report migrate is idempotent');

$router = (string) file_get_contents($root . '/app/Router.php');
$assetCtl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
$reportCtl = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
$assetTpl = (string) file_get_contents($root . '/templates/assets/show.php');
$showTpl = (string) file_get_contents($root . '/templates/reports/show.php');
$indexTpl = (string) file_get_contents($root . '/templates/reports/index.php');
$homeTpl = (string) file_get_contents($root . '/templates/home/index.php');
$moreTpl = (string) file_get_contents($root . '/templates/more/index.php');
$schema = (string) file_get_contents($root . '/sql/schema.sql');

check(str_contains($schema, "'open','in_progress','done','impossible'"), 'Schema CHECK includes 불가');
check(str_contains($router, "'reports' => [ReportController::class, 'index']"), 'reports queue route');
check(str_contains($router, "'reports/status' => [ReportController::class, 'updateStatus']"), 'reports status POST route');
check(str_contains($assetCtl, 'Report::create') && str_contains($assetCtl, 'Report::canCreate'), 'Asset report uses Report::create and canCreate');
check(str_contains($assetCtl, 'Csrf::requirePost()'), 'Asset report keeps POST+CSRF');
check(str_contains($reportCtl, 'Csrf::requirePost()') && str_contains($reportCtl, 'Report::canTransition'), 'Status change is POST+CSRF and canWrite');
check(str_contains($assetTpl, '수리 요청') && str_contains($assetTpl, '증상') && str_contains($assetTpl, 'assets/report'), 'Teacher form stays on asset detail');
check(str_contains($assetTpl, 'Report::canCreate($user)') && str_contains($assetTpl, 'Csrf::field()'), 'Teacher form gated and CSRF');
check(str_contains($showTpl, 'Csrf::field()') && str_contains($showTpl, 'reports/status'), 'Manager status form posts with CSRF');
check(str_contains($showTpl, '처리 이력') && str_contains($indexTpl, '수리 요청'), 'Queue and history screens exist');
check(!str_contains($showTpl, 'new Vue') && !str_contains($indexTpl, 'createApp'), 'Repair UI stays SSR');
check(str_contains($homeTpl, "App::url('reports')") && str_contains($moreTpl, "App::url('reports')"), 'Home and more link to the queue');
check(!str_contains($indexTpl, 'edufine') && !str_contains($showTpl, '수리비') && !str_contains($reportCtl, 'retired'), 'Scoped away from Edufine, costs, retire');

$fresh = Report::create($pdo, $teacher, 'asset', 'ast-3', '롤백용', '이력 실패');
$pdo->exec("CREATE TRIGGER fail_report_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test log failure'); END");
try {
    Report::transition($pdo, $manager, $fresh, Report::STATUS_IN_PROGRESS);
    throw new RuntimeException('Expected log failure');
} catch (PDOException) {
    check(reportStatus($pdo, $fresh) === Report::STATUS_OPEN, 'Log failure rolls back status');
    check(assetStatus($pdo, 'ast-3') === 'repair', 'Log failure keeps the open-report asset link');
}
$pdo->exec('DROP TRIGGER fail_report_log');
check(count(Report::history($pdo, $fresh)) === 1, 'Failed transition does not append history');

echo "PASS: {$checks} report checks\n";
