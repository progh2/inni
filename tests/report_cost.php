<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\Database;
use Inni\Report;
use Inni\ReportCost;

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

function seedCost(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
        $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '@test', $id, $role, 'active', 't', 't']);
    }
    $pdo->prepare(
        'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?)'
    )->execute(['ast-1', 'eq', '스코프 #1', '전장-1', 'available', 'room', 'AST:ast-1', 't', 't']);
}

/**
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
 */
function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status, cost_amount, cost_vendor, cost_budget_line, cost_at FROM reports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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
$pending = actor('pending-manager', 'manager', 'pending');

$pdo = memoryDb();
seedCost($pdo);

$cols = array_column($pdo->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['cost_amount', 'cost_vendor', 'cost_budget_line', 'cost_at'] as $col) {
    check(in_array($col, $cols, true), 'schema has reports.' . $col);
}

check(ReportCost::parseAmount('') === null, 'empty amount is null');
check(ReportCost::parseAmount('12,000') === 12000.0, 'comma amount parses');
check(ReportCost::parseAmount('85000원') === 85000.0, 'won suffix parses');
check(ReportCost::formatAmount(85000.0) === '85,000원', 'amount formats with comma and 원');
check(ReportCost::parseDate('2026-03-05') === '2026-03-05', 'date parses');
check(ReportCost::filtersFromRequest(['year' => '2026', 'month' => '3']) === ['year' => 2026, 'month' => 3], 'request keeps year/month');
check(ReportCost::filtersFromRequest(['year' => '26', 'month' => '13']) === ['year' => null, 'month' => null], 'invalid year/month ignored');

$rep1 = Report::file($pdo, $teacher, 'ast-1', '전원 안 켜짐');
$beforeStatus = $pdo->query('SELECT status FROM reports WHERE id=' . $pdo->quote($rep1))->fetchColumn();
$beforeAsset = $pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn();

reject(static function () use ($pdo, $teacher, $rep1): void {
    ReportCost::save($pdo, $teacher, $rep1, '12000', '업체', '시설유지비', '2026-03-01');
}, $pdo, 'Teacher cost save');
reject(static function () use ($pdo, $student, $rep1): void {
    ReportCost::save($pdo, $student, $rep1, '12000', '업체', '시설유지비', '2026-03-01');
}, $pdo, 'Student cost save');
reject(static function () use ($pdo, $pending, $rep1): void {
    ReportCost::save($pdo, $pending, $rep1, '12000', '업체', '시설유지비', '2026-03-01');
}, $pdo, 'Pending manager cost save');
reject(static function () use ($pdo, $owner, $rep1): void {
    ReportCost::save($pdo, $owner, $rep1, '-1', '업체', '시설유지비', '2026-03-01');
}, $pdo, 'Negative amount');
reject(static function () use ($pdo, $owner, $rep1): void {
    ReportCost::save($pdo, $owner, $rep1, 'abc', '업체', '시설유지비', '2026-03-01');
}, $pdo, 'Non-numeric amount');
reject(static function () use ($pdo, $owner, $rep1): void {
    ReportCost::save($pdo, $owner, $rep1, '12000', '업체', '시설유지비', '2026-13-40');
}, $pdo, 'Invalid cost date');
reject(static function () use ($pdo, $owner): void {
    ReportCost::save($pdo, $owner, 'missing', '12000', '업체', '시설유지비', '2026-03-01');
}, $pdo, 'Missing report');

$saved = ReportCost::save($pdo, $manager, $rep1, '12,000', '대한용접', '시설유지비', '2026-03-05');
check((float) $saved['cost_amount'] === 12000.0, 'manager saves amount');
check($saved['cost_vendor'] === '대한용접', 'manager saves vendor');
check($saved['cost_budget_line'] === '시설유지비', 'manager saves budget line');
check($saved['cost_at'] === '2026-03-05', 'manager saves cost date');
check($saved['status'] === $beforeStatus, 'cost save does not change report status');
check(
    $pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn() === $beforeAsset,
    'cost save does not change asset status'
);

$ownerSave = ReportCost::save($pdo, $owner, $rep1, '15000', '서울계측', '기자재유지비', '2026-03-20');
check((float) $ownerSave['cost_amount'] === 15000.0, 'owner can overwrite cost');
check($ownerSave['cost_vendor'] === '서울계측', 'owner overwrites vendor');

$logs = Report::logs($pdo, $rep1);
$summaries = implode(' ', array_map(static fn (array $row): string => (string) $row['summary'], $logs));
check(str_contains($summaries, '수리비 기록'), 'cost save writes activity history');
check(
    $pdo->query("SELECT action FROM activity_logs WHERE entity_id=" . $pdo->quote($rep1) . " AND action='report_cost' LIMIT 1")->fetchColumn() === 'report_cost',
    'cost log uses report_cost action'
);

$rep2 = Report::file($pdo, $teacher, 'ast-1', '팬 소음');
ReportCost::save($pdo, $owner, $rep2, 30000, '대한용접', '시설유지비', '2026-04-02');
$rep3 = Report::file($pdo, $teacher, 'ast-1', '케이블');
ReportCost::save($pdo, $owner, $rep3, 5000, null, '시설유지비', '2025-12-15');

$march = ReportCost::totals($pdo, ['year' => 2026, 'month' => 3]);
check($march['count'] === 1 && $march['total_amount'] === 15000.0, 'March 2026 totals the overwritten cost');
$year2026 = ReportCost::totals($pdo, ['year' => 2026]);
check($year2026['count'] === 2 && $year2026['total_amount'] === 45000.0, '2026 yearly total is March + April');
$year2025 = ReportCost::totals($pdo, ['year' => 2025]);
check($year2025['count'] === 1 && $year2025['total_amount'] === 5000.0, '2025 yearly total is the December case');

$months = ReportCost::monthly($pdo, 2026);
check(count($months) === 12, 'monthly returns 12 months');
check($months[2]['month'] === 3 && $months[2]['total_amount'] === 15000.0 && $months[2]['count'] === 1, 'March row matches');
check($months[3]['month'] === 4 && $months[3]['total_amount'] === 30000.0, 'April row matches');
check($months[0]['total_amount'] === 0.0 && $months[0]['count'] === 0, 'empty January stays zero');

$years = ReportCost::yearly($pdo);
$byYear = [];
foreach ($years as $row) {
    $byYear[$row['year']] = $row;
}
check(isset($byYear[2026], $byYear[2025]), 'yearly lists both years');
check($byYear[2026]['total_amount'] === 45000.0 && $byYear[2026]['count'] === 2, 'yearly 2026 aggregate');
check($byYear[2025]['total_amount'] === 5000.0 && $byYear[2025]['count'] === 1, 'yearly 2025 aggregate');

$marchCases = ReportCost::cases($pdo, ['year' => 2026, 'month' => 3]);
check(count($marchCases) === 1 && ($marchCases[0]['id'] ?? '') === $rep1, 'March cases list the overwritten report');
$yearCases = ReportCost::cases($pdo, ['year' => 2026]);
check(count($yearCases) === 2, '2026 cases are March and April');

$cleared = ReportCost::save($pdo, $owner, $rep3, '', '', '', '');
check($cleared['cost_amount'] === null && $cleared['cost_vendor'] === null, 'empty fields clear the cost');
$year2025After = ReportCost::totals($pdo, ['year' => 2025]);
check($year2025After['count'] === 0 && $year2025After['total_amount'] === 0.0, 'cleared cost leaves the year total');

$rep4 = Report::file($pdo, $teacher, 'ast-1', '기본 비용일');
$defaulted = ReportCost::save($pdo, $owner, $rep4, 1000, '현장', null, '');
check($defaulted['cost_at'] === ReportCost::today(), 'amount without date defaults to today');
check($defaulted['status'] === 'open', 'default-date save still leaves status open');

$beforeRollback = snapshot($pdo);
$pdo->exec("CREATE TRIGGER fail_cost_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test log failure'); END");
try {
    ReportCost::save($pdo, $owner, $rep1, 1, '실패', '시설유지비', '2026-03-01');
    throw new RuntimeException('Expected cost log failure');
} catch (PDOException) {
    check(snapshot($pdo) === $beforeRollback && !$pdo->inTransaction(), 'Log failure rolls back cost save');
}
$pdo->exec('DROP TRIGGER fail_cost_log');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('PRAGMA foreign_keys = ON');
$old->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
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
      status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','done','rejected')),
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    )"
);
$old->exec("INSERT INTO reports(id,target_type,target_id,reporter_name,title,body,status,created_at,updated_at) VALUES('rep-old','asset','ast-1','교사','송급','끊김','open','t','t')");
Database::migrate($old);
$oldCols = array_column($old->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('cost_amount', $oldCols, true) && in_array('cost_vendor', $oldCols, true), 'migrate adds cost columns');
check(in_array('cost_budget_line', $oldCols, true) && in_array('cost_at', $oldCols, true), 'migrate adds budget line and cost date');
check($old->query("SELECT status FROM reports WHERE id='rep-old'")->fetchColumn() === 'open', 'legacy report survives cost migrate');
Database::migrate($old);
check(true, 'cost migrate is idempotent');

check(Auth::canWrite($owner) && Auth::canWrite($manager) && !Auth::canWrite($teacher), 'canWrite is owner/manager only');

$router = (string) file_get_contents($root . '/app/Router.php');
$reportCtl = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
$reportPhp = (string) file_get_contents($root . '/app/Report.php');
$showTpl = (string) file_get_contents($root . '/templates/reports/show.php');
$costsTpl = (string) file_get_contents($root . '/templates/reports/costs.php');
$queueTpl = (string) file_get_contents($root . '/templates/reports/index.php');
$moreTpl = (string) file_get_contents($root . '/templates/more/index.php');
$schema = (string) file_get_contents($root . '/sql/schema.sql');

check(str_contains($router, "'reports/costs' => [ReportController::class, 'costs']"), 'reports/costs GET is registered');
check(str_contains($router, "'reports/cost' => [ReportController::class, 'updateCost']"), 'reports/cost POST is registered');
check(str_contains($router, "'reports/status' => [ReportController::class, 'updateStatus']"), 'status route stays registered');

check(str_contains($reportCtl, 'function costs') && str_contains($reportCtl, 'function updateCost'), 'controller has costs + updateCost');
check(str_contains($reportCtl, 'Auth::canWrite($user)') && str_contains($reportCtl, 'Csrf::requirePost()'), 'cost write is canWrite + CSRF');
check(str_contains($reportCtl, 'ReportCost::save'), 'controller uses ReportCost::save');
check(!str_contains($reportCtl, 'http://'), 'cost return is not an open redirect');

check(str_contains($showTpl, 'name="cost_amount"') && str_contains($showTpl, 'name="cost_vendor"'), 'detail has amount and vendor');
check(str_contains($showTpl, 'name="cost_budget_line"') && str_contains($showTpl, 'name="cost_at"'), 'detail has budget line and date');
check(str_contains($showTpl, 'Csrf::field()') && str_contains($showTpl, "App::url('reports/cost')"), 'detail posts cost with CSRF');
check(str_contains($showTpl, 'Auth::canWrite($user)'), 'cost form is canWrite-gated');
check(str_contains($showTpl, '<h2 class="section-title" style="margin-top:0">수리비</h2>'), 'detail keeps a 수리비 section');
check(!str_contains($showTpl, 'new Vue') && !str_contains($costsTpl, 'createApp'), 'cost UI stays SSR');

check(str_contains($costsTpl, '<h1>수리비 합계</h1>'), 'totals view title');
check(str_contains($costsTpl, 'name="year"') && str_contains($costsTpl, 'name="month"'), 'totals has year/month filters');
check(str_contains($costsTpl, '월별') && str_contains($costsTpl, 'method="get"'), 'totals is a GET month/year view');
check(str_contains($queueTpl, "App::url('reports/costs')"), 'queue links to totals');
check(str_contains($moreTpl, '수리비 합계') && str_contains($moreTpl, "App::url('reports/costs')"), 'more menu links to totals');

check(str_contains($reportPhp, 'function transition') && str_contains($reportPhp, 'function nextStatuses'), 'repair state machine stays on Report');
check(!str_contains($reportPhp, 'cost_amount'), 'Report.php does not take over cost columns');
check(str_contains($schema, 'cost_amount') && str_contains($schema, 'cost_vendor') && str_contains($schema, 'cost_budget_line'), 'schema documents cost fields');
check(!str_contains($schema, 'edufine_sync') && !str_contains($costsTpl, '감가상각 계산'), 'Edufine/depreciation stay out of scope');

echo "PASS: {$checks} report-cost checks\n";
