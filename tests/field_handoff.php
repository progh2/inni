<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Asset;
use Inni\Auth;
use Inni\Database;
use Inni\Desk;
use Inni\Inventory;
use Inni\InventoryAdjust;
use Inni\InventoryBudget;
use Inni\Loan;
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

function seed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','LOC:room','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
        $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '@test', $id, $role, 'active', 't', 't']);
    }
    $pdo->prepare(
        'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,budget_program,budget_year,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?)'
    )->execute(['ast-1', 'eq', '스코프 #1', '전장-1', 'available', 'room', 'AST:ast-1', '방과후', 2026, 't', 't']);
    $pdo->prepare(
        'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?,?)'
    )->execute(['ast-2', 'eq', '스코프 #2', '전장-2', 'available', 'room', 'AST:ast-2', 't', 't']);
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status, purpose, borrower_note, return_condition FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status, discovered_at, urgency, wish FROM reports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status, witness_name, confirm_teacher FROM inventory_checks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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

$teacher = actor('teacher', 'teacher');
$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$student = actor('student', 'student');

$pdo = memoryDb();
seed($pdo);

$cols = array_column($pdo->query('PRAGMA table_info(loans)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('purpose', $cols, true) && in_array('return_condition', $cols, true), 'schema has loan purpose and return_condition');
$repCols = array_column($pdo->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['discovered_at', 'urgency', 'wish', 'cost_estimate', 'cost_evidence', 'cost_budget_program'] as $col) {
    check(in_array($col, $repCols, true), 'schema has reports.' . $col);
}
$invCols = array_column($pdo->query('PRAGMA table_info(inventory_checks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('witness_name', $invCols, true) && in_array('confirm_teacher', $invCols, true), 'schema has 실사조서 teachers');
$astCols = array_column($pdo->query('PRAGMA table_info(assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('retire_kind', $astCols, true), 'schema has assets.retire_kind');

$due = Desk::defaultDueIso(strtotime('2026-09-15 09:30:00'));
$loanId = Loan::checkout($pdo, $teacher, 'ast-1', '', '3-2 프로젝트', '5교시 실습', $due, 'teacher');
$row = $pdo->query('SELECT purpose, borrower_note FROM loans WHERE id=' . $pdo->quote($loanId))->fetch(PDO::FETCH_ASSOC);
check(($row['purpose'] ?? '') === '5교시 실습' && ($row['borrower_note'] ?? '') === '3-2 프로젝트', 'desk checkout stores 용도 and 학번/비고');

reject(static function () use ($pdo, $teacher, $loanId): void {
    Loan::checkin($pdo, $teacher, $loanId, 'issue', '');
}, $pdo, 'Issue return without note');
reject(static function () use ($pdo, $student, $loanId): void {
    Loan::checkin($pdo, $student, $loanId, 'ok', null);
}, $pdo, 'Student return of someone else');

$assetId = Loan::checkin($pdo, $teacher, $loanId, 'issue', '화면 깨짐');
check($assetId === 'ast-1', 'issue return still frees the asset');
$ret = $pdo->query('SELECT return_condition, return_note, status FROM loans WHERE id=' . $pdo->quote($loanId))->fetch(PDO::FETCH_ASSOC);
check(($ret['return_condition'] ?? '') === 'issue' && ($ret['return_note'] ?? '') === '화면 깨짐', 'return stores 이상유무');
check(($ret['status'] ?? '') === 'returned', 'issue return closes the loan');
$retLog = $pdo->query("SELECT summary FROM activity_logs WHERE action='return' ORDER BY rowid DESC LIMIT 1")->fetchColumn();
check(str_contains((string) $retLog, '이상있음'), 'return log names 이상있음');

$loanOk = Loan::checkout($pdo, $teacher, 'ast-1', '', null, '수업', $due, 'teacher');
Loan::checkin($pdo, $owner, $loanOk);
$okRow = $pdo->query('SELECT return_condition FROM loans WHERE id=' . $pdo->quote($loanOk))->fetchColumn();
check($okRow === 'ok', 'omitted condition defaults to 이상없음');

$rep = Report::file($pdo, $teacher, 'ast-2', '전원 안 켜짐', null, null, '2026-09-14', 'urgent', 'outsource');
$filed = $pdo->query('SELECT discovered_at, urgency, wish, status FROM reports WHERE id=' . $pdo->quote($rep))->fetch(PDO::FETCH_ASSOC);
check(($filed['discovered_at'] ?? '') === '2026-09-14', 'repair stores 발견일');
check(($filed['urgency'] ?? '') === 'urgent', 'repair stores 긴급도');
check(($filed['wish'] ?? '') === 'outsource', 'repair stores 외주 희망');

reject(static function () use ($pdo, $student): void {
    Report::file($pdo, $student, 'ast-1', '안 켜짐', null, null, '2026-09-14', 'urgent', 'replace');
}, $pdo, 'Student repair with extra fields');
reject(static function () use ($pdo, $teacher): void {
    Report::file($pdo, $teacher, 'ast-1', '안 켜짐', null, null, '2026-13-40', 'urgent', null);
}, $pdo, 'Invalid discovered_at');
reject(static function () use ($pdo, $teacher): void {
    Report::file($pdo, $teacher, 'ast-1', '안 켜짐', null, null, '2026-09-14', 'critical', null);
}, $pdo, 'Invalid urgency');

Report::transition($pdo, $owner, $rep, 'rejected');
check($pdo->query('SELECT status FROM reports WHERE id=' . $pdo->quote($rep))->fetchColumn() === 'rejected', 'open may go to 불가');
check($pdo->query("SELECT status FROM assets WHERE id='ast-2'")->fetchColumn() === 'repair', '불가 keeps the asset in repair so 파기 can follow');

Asset::retire($pdo, $manager, 'ast-2', '수리불가', '2026-09-16', '폐기조서 1', null, Asset::RETIRE_UNREPAIRABLE);
$retired = $pdo->query("SELECT status, retire_kind, retire_reason FROM assets WHERE id='ast-2'")->fetch(PDO::FETCH_ASSOC);
check(
    ($retired['status'] ?? '') === 'retired'
    && ($retired['retire_kind'] ?? '') === 'unrepairable'
    && ($retired['retire_reason'] ?? '') === '수리불가',
    'retire from 수리불가 stores kind'
);

$repCost = Report::file($pdo, $teacher, 'ast-1', '팬 소음', null, null, '2026-09-15', 'normal', 'inhouse');
reject(static function () use ($pdo, $teacher, $repCost): void {
    ReportCost::save($pdo, $teacher, $repCost, '12000', '업체', '시설유지비', '2026-03-01', '15000', '견적 1', '방과후', 2026);
}, $pdo, 'Teacher cost save with estimate');

$saved = ReportCost::save($pdo, $manager, $repCost, '12,000', '대한용접', '시설유지비', '2026-03-05', '15,000', '견적서 3호', '방과후', 2026);
check((float) $saved['cost_amount'] === 12000.0, 'cost amount still saves');
check((float) ($saved['cost_estimate'] ?? 0) === 15000.0, '견적 saves');
check(($saved['cost_evidence'] ?? '') === '견적서 3호', '증빙 memo saves');
check(($saved['cost_budget_program'] ?? '') === '방과후' && (int) ($saved['cost_budget_year'] ?? 0) === 2026, '사업예산 연결 saves');
check(($saved['status'] ?? '') === 'open', 'cost save does not change report status');

$check = Inventory::start($pdo, $owner, 'room');
$done = Inventory::finish($pdo, $owner, (string) $check['id'], '김입회', '이확인');
check(($done['status'] ?? '') === 'done', 'finish still closes the session');
check(($done['witness_name'] ?? '') === '김입회' && ($done['confirm_teacher'] ?? '') === '이확인', 'finish stores 입회·확인 교사');

$attested = Inventory::attest($pdo, $manager, (string) $done['id'], '박입회', '최확인');
check(($attested['witness_name'] ?? '') === '박입회' && ($attested['confirm_teacher'] ?? '') === '최확인', 'attest updates 실사조서 teachers');

reject(static function () use ($pdo, $teacher, $done): void {
    Inventory::attest($pdo, $teacher, (string) $done['id'], '교사', '교사');
}, $pdo, 'Teacher attest');
reject(static function () use ($pdo, $owner, $done): void {
    Inventory::attest($pdo, $owner, (string) $done['id'], '', '확인만');
}, $pdo, 'Empty witness');

$lines = InventoryBudget::lines($pdo, ['check_id' => $done['id']]);
check($lines !== [] && ($lines[0]['witness_name'] ?? '') === '박입회', 'budget report lines carry 입회 교사');
$csv = InventoryBudget::csv($pdo, ['check_id' => $done['id']]);
check(str_contains($csv, '입회교사') && str_contains($csv, '확인교사'), 'csv has 실사조서 teacher headers');
check(str_contains($csv, '박입회') && str_contains($csv, '최확인'), 'csv includes teacher names');

$missing = null;
foreach (Inventory::unchecked($pdo, (string) $done['id']) as $line) {
    if (($line['kind'] ?? '') === 'asset') {
        $missing = $line;
        break;
    }
}
check(is_array($missing), 'finished session has a missing asset for adjust/retire handoff');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('PRAGMA foreign_keys = ON');
$old->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id), name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec("CREATE TABLE loans (id TEXT PRIMARY KEY, kind TEXT NOT NULL, asset_id TEXT, status TEXT NOT NULL DEFAULT 'active')");
$old->exec("CREATE TABLE reports (id TEXT PRIMARY KEY, target_type TEXT NOT NULL, target_id TEXT NOT NULL, reporter_name TEXT NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','done','rejected')), created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$old->exec("CREATE TABLE inventory_checks (id TEXT PRIMARY KEY, location_id TEXT NOT NULL, location_name TEXT NOT NULL, status TEXT NOT NULL, started_by TEXT NOT NULL, started_at TEXT NOT NULL)");
Database::migrate($old);
$oldLoan = array_column($old->query('PRAGMA table_info(loans)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$oldRep = array_column($old->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$oldInv = array_column($old->query('PRAGMA table_info(inventory_checks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$oldAst = array_column($old->query('PRAGMA table_info(assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('return_condition', $oldLoan, true) && in_array('return_note', $oldLoan, true), 'migrate adds loan return fields');
check(in_array('discovered_at', $oldRep, true) && in_array('cost_estimate', $oldRep, true), 'migrate adds report field/cost extras');
check(in_array('witness_name', $oldInv, true) && in_array('confirm_teacher', $oldInv, true), 'migrate adds 실사조서 teachers');
check(in_array('retire_kind', $oldAst, true), 'migrate adds retire_kind');
Database::migrate($old);
check(true, 'field handoff migrate is idempotent');

check(Auth::canWrite($owner) && !Auth::canWrite($teacher), 'canWrite stays owner/manager');
check(Auth::canLoan($teacher) && !Auth::canLoan($student), 'canLoan stays teacher+');
check(Auth::canInventory($owner) && !Auth::canInventory($teacher), 'canInventory stays manager+');

$deskTpl = (string) file_get_contents($root . '/templates/desk/index.php');
$assetTpl = (string) file_get_contents($root . '/templates/assets/show.php');
$showTpl = (string) file_get_contents($root . '/templates/reports/show.php');
$agingTpl = (string) file_get_contents($root . '/templates/assets/aging.php');
$invShow = (string) file_get_contents($root . '/templates/inventory/show.php');
$invResult = (string) file_get_contents($root . '/templates/inventory/result.php');
$invReport = (string) file_get_contents($root . '/templates/inventory/report.php');
$adjust = (string) file_get_contents($root . '/templates/partials/inventory_adjust.php');
$home = (string) file_get_contents($root . '/templates/home/index.php');
$more = (string) file_get_contents($root . '/templates/more/index.php');
$router = (string) file_get_contents($root . '/app/Router.php');
$deskCtl = (string) file_get_contents($root . '/app/Controllers/DeskController.php');
$loanCtl = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
$invCtl = (string) file_get_contents($root . '/app/Controllers/InventoryController.php');
$reportCtl = (string) file_get_contents($root . '/app/Controllers/ReportController.php');

check(str_contains($deskTpl, 'name="purpose"') && str_contains($deskTpl, 'name="borrower_note"'), 'desk loan has 용도·학번/비고');
check(str_contains($deskTpl, 'return_condition.php') && str_contains($deskTpl, '받아주기'), 'desk return asks 이상유무');
check(!str_contains($deskTpl, '차용자'), 'desk still avoids 차용자');
check(str_contains($deskCtl, 'borrower_note') && str_contains($deskCtl, 'purpose'), 'desk loan posts purpose/note');

check(str_contains($loanCtl, 'return_condition') && str_contains($loanCtl, 'Csrf::requirePost()'), 'return keeps CSRF and stores condition');
check(str_contains($loanCtl, "'focus' => 'repair'"), '이상있음 return hands off to 수리 요청');

check(str_contains($assetTpl, 'name="discovered_at"') && str_contains($assetTpl, 'name="urgency"') && str_contains($assetTpl, 'name="wish"'), 'repair form has 발견일·긴급도·희망');
check(str_contains($assetTpl, 'name="retire_kind"') && str_contains($assetTpl, '불용·파기로 이어가기'), 'retire form has 불용 구분 and rejected handoff');
check(!str_contains($assetTpl, 'name="title"'), 'teacher repair still has no separate title');

check(str_contains($showTpl, '불용·파기로 이어가기') && str_contains($showTpl, 'name="cost_estimate"'), 'report detail has 파기 handoff and 견적');
check(str_contains($showTpl, 'name="cost_budget_program"') && str_contains($showTpl, 'name="cost_evidence"'), 'report detail has 사업예산 and 증빙');
check(str_contains($showTpl, '에듀파인·감가상각은 없습니다'), 'cost UI still says no Edufine');
check(str_contains($reportCtl, 'cost_estimate') && str_contains($reportCtl, 'Csrf::requirePost()'), 'cost write stays CSRF');

check(str_contains($agingTpl, '파기 (연한초과)') && str_contains($agingTpl, 'RETIRE_LIFE_EXCEEDED'), 'aging exceeded hands off to 파기');
check(str_contains($agingTpl, '이 장비를 쓸 수 있는 햇수'), '#76 aging 내용연한 gloss stays');

check(str_contains($invShow, 'name="witness_name"') && str_contains($invShow, 'name="confirm_teacher"'), 'finish form has 입회·확인 교사');
check(str_contains($invResult, "App::url('inventory/attest')") && str_contains($invResult, 'Csrf::field()'), 'result can update 실사조서 with CSRF');
check(str_contains($invReport, '입회') && str_contains($invReport, '확인'), 'budget report shows 실사조서 teachers');
check(str_contains($adjust, '파기로 이어가기'), 'adjust form hands missing assets to 파기');
check(str_contains($invCtl, 'function attest') && str_contains($invCtl, 'Csrf::requirePost()'), 'attest is POST+CSRF');
check(str_contains($router, "'inventory/attest'"), 'attest route is registered');

check(str_contains($home, '수리 요청') && str_contains($home, '내 대여함'), '#76 home CTAs stay');
check(str_contains($home, '대여 데스크는 장비를 빌려주고 받아주는 창구'), '#76 desk gloss stays');
check(str_contains($more, '장부 보정') && str_contains($more, '사업예산 실사'), '#76 more M7 entries stay');
check(!preg_match('/#[0-9]{2,}/', $home) && !preg_match('/#[0-9]{2,}/', $agingTpl), 'UI still has no raw issue numbers');
check(!str_contains($router, 'edufine') && !str_contains((string) file_get_contents($root . '/sql/schema.sql'), 'edufine_sync'), 'no invented Edufine sync');

echo "PASS: {$checks} field-handoff checks\n";
