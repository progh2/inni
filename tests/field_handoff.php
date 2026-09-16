<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Asset;
use Inni\Auth;
use Inni\Database;
use Inni\Desk;
use Inni\Inventory;
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

function seedHandoff(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','LOC:room','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
        $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '@test', $id, $role, 'active', 't', 't']);
    }
    foreach (['ast-1' => 'available', 'ast-2' => 'available', 'ast-3' => 'available'] as $id => $status) {
        $pdo->prepare(
            'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,budget_program,budget_year,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$id, 'eq', $id, '전장-' . $id, $status, 'room', 'AST:' . $id, '방과후', 2026, 't', 't']);
    }
}

/**
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>}
 */
function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status FROM reports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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
seedHandoff($pdo);

$cols = array_column($pdo->query('PRAGMA table_info(loans)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('return_condition', $cols, true) && in_array('return_note', $cols, true), 'schema has loan return check columns');
$repCols = array_column($pdo->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['discovered_at', 'urgency', 'prefer', 'cost_estimate', 'cost_evidence', 'cost_budget_program'] as $col) {
    check(in_array($col, $repCols, true), 'schema has reports.' . $col);
}
$astCols = array_column($pdo->query('PRAGMA table_info(assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('retire_source', $astCols, true) && in_array('retire_report_id', $astCols, true), 'schema has retire handoff columns');
$invCols = array_column($pdo->query('PRAGMA table_info(inventory_checks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(
    in_array('attending_teacher_name', $invCols, true) && in_array('confirming_teacher_name', $invCols, true),
    'schema has 실사조서 teacher columns'
);

check(Loan::returnConditionLabel('ok') === '정상' && Loan::returnConditionLabel('abnormal') === '이상', 'return condition labels');
check(Loan::parseReturnCondition('이상') === Loan::RETURN_ABNORMAL, 'Korean 이상 maps to abnormal');
check(Report::urgencyLabel('urgent') === '긴급' && Report::preferLabel('outsource') === '외주', 'repair intake labels');
check(Asset::retireReasonForSource(Asset::RETIRE_SOURCE_REPAIR) === '수리불가', 'repair-impossible default reason');
check(Asset::retireReasonForSource(Asset::RETIRE_SOURCE_LIFE) === '내용연한 초과', 'useful-life default reason');

$due = Desk::defaultDueIso(strtotime('2026-09-16 09:00:00'));
$loanId = Loan::checkout($pdo, $teacher, 'ast-1', '', '3-2 김학생', '5교시 실습', $due, 'teacher');
$row = $pdo->query('SELECT purpose, borrower_note FROM loans WHERE id=' . $pdo->quote($loanId))->fetch(PDO::FETCH_ASSOC);
check(($row['purpose'] ?? '') === '5교시 실습', 'desk checkout stores purpose');
check(($row['borrower_note'] ?? '') === '3-2 김학생', 'desk checkout stores 학번/비고');

reject(static function () use ($pdo, $teacher, $loanId): void {
    Loan::checkin($pdo, $teacher, $loanId, 'broken', null);
}, $pdo, 'Invalid return condition');

$returned = Loan::checkin($pdo, $teacher, $loanId, 'abnormal', '전원 불량');
check($returned === 'ast-1', 'return with abnormality frees the asset');
$closed = $pdo->query('SELECT status, return_condition, return_note FROM loans WHERE id=' . $pdo->quote($loanId))->fetch(PDO::FETCH_ASSOC);
check(($closed['status'] ?? '') === 'returned', 'loan is returned');
check(($closed['return_condition'] ?? '') === 'abnormal', 'return condition persists');
check(($closed['return_note'] ?? '') === '전원 불량', 'return note persists');

$okLoan = Loan::checkout($pdo, $owner, 'ast-1', '', null, '점검', $due, 'teacher');
Loan::checkin($pdo, $owner, $okLoan, '정상', '');
$okRow = $pdo->query('SELECT return_condition, return_note FROM loans WHERE id=' . $pdo->quote($okLoan))->fetch(PDO::FETCH_ASSOC);
check(($okRow['return_condition'] ?? '') === 'ok' && ($okRow['return_note'] ?? null) === null, '정상 maps to ok without a note');

reject(static function () use ($pdo, $student): void {
    Loan::checkout($pdo, $student, 'ast-2', '학생', '학번', '수업', null, 'teacher');
}, $pdo, 'Student desk loan fields');

$rep = Report::file(
    $pdo,
    $teacher,
    'ast-2',
    '보드가 탐',
    null,
    null,
    '2026-09-15T08:40',
    'urgent',
    'outsource',
);
$filed = $pdo->query('SELECT discovered_at, urgency, prefer, body FROM reports WHERE id=' . $pdo->quote($rep))->fetch(PDO::FETCH_ASSOC);
check(($filed['discovered_at'] ?? '') === '2026-09-15 08:40', 'discovered_at persists');
check(($filed['urgency'] ?? '') === 'urgent', 'urgency persists');
check(($filed['prefer'] ?? '') === 'outsource', 'prefer outsource persists');
check(($filed['body'] ?? '') === '보드가 탐', 'symptom stays in body');

reject(static function () use ($pdo, $student): void {
    Report::file($pdo, $student, 'ast-3', '안 켜짐', null, null, '2026-09-15 09:00', 'urgent', 'replace');
}, $pdo, 'Student repair intake');
reject(static function () use ($pdo, $teacher): void {
    Report::file($pdo, $teacher, 'ast-3', '안 켜짐', null, null, '어제', 'urgent', 'replace');
}, $pdo, 'Invalid discovered_at');
reject(static function () use ($pdo, $teacher): void {
    Report::file($pdo, $teacher, 'ast-3', '안 켜짐', null, null, '2026-09-15 09:00', 'critical', 'replace');
}, $pdo, 'Invalid urgency');
reject(static function () use ($pdo, $teacher): void {
    Report::file($pdo, $teacher, 'ast-3', '안 켜짐', null, null, '2026-09-15 09:00', 'urgent', 'discard');
}, $pdo, 'Invalid prefer');

Report::transition($pdo, $manager, $rep, Report::STATUS_REJECTED);
check(
    $pdo->query('SELECT status FROM reports WHERE id=' . $pdo->quote($rep))->fetchColumn() === 'rejected',
    'manager marks 수리불가'
);
check($pdo->query("SELECT status FROM assets WHERE id='ast-2'")->fetchColumn() === 'repair', '불가 keeps the asset in repair');

reject(static function () use ($pdo, $teacher, $rep): void {
    Asset::retire($pdo, $teacher, 'ast-2', '수리불가', '2026-09-16', '폐기조서', null, 'repair_impossible', $rep);
}, $pdo, 'Teacher retire from repair-impossible');
reject(static function () use ($pdo, $student, $rep): void {
    Asset::retire($pdo, $student, 'ast-2', '수리불가', '2026-09-16', '폐기조서', null, 'repair_impossible', $rep);
}, $pdo, 'Student retire from repair-impossible');

Asset::retire($pdo, $owner, 'ast-2', '', '2026-09-16', '폐기조서 77-1', null, 'repair_impossible', $rep);
$retired = $pdo->query(
    "SELECT status, retire_reason, retire_source, retire_report_id FROM assets WHERE id='ast-2'"
)->fetch(PDO::FETCH_ASSOC);
check(($retired['status'] ?? '') === 'retired', 'repair-impossible handoff retires the asset');
check(($retired['retire_reason'] ?? '') === '수리불가', 'empty reason fills 수리불가');
check(($retired['retire_source'] ?? '') === 'repair_impossible', 'retire_source persists');
check(($retired['retire_report_id'] ?? '') === $rep, 'retire_report_id links the rejected report');

reject(static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-3', '내용연한 초과', '2026-09-16', '조서', null, 'repair_impossible', 'missing-report');
}, $pdo, 'Retire with unknown report');

Asset::retire($pdo, $manager, 'ast-3', '', '2026-09-16', '폐기조서 77-2', null, 'useful_life');
$life = $pdo->query("SELECT retire_reason, retire_source FROM assets WHERE id='ast-3'")->fetch(PDO::FETCH_ASSOC);
check(($life['retire_reason'] ?? '') === '내용연한 초과', 'useful-life handoff fills 내용연한 초과');
check(($life['retire_source'] ?? '') === 'useful_life', 'useful-life source persists');

$costRep = Report::file($pdo, $teacher, 'ast-1', '팬 소음', null, null, '2026-09-16 10:00', 'normal', 'inhouse');
reject(static function () use ($pdo, $teacher, $costRep): void {
    ReportCost::save($pdo, $teacher, $costRep, '10000', '업체', '시설유지비', '2026-09-16', '20000', '견적서', null, '방과후');
}, $pdo, 'Teacher cost estimate');

$saved = ReportCost::save(
    $pdo,
    $manager,
    $costRep,
    '85000',
    '대한용접',
    '시설유지비',
    '2026-09-16',
    '120000',
    '견적서 2026-9',
    null,
    '방과후',
);
check((float) $saved['cost_estimate'] === 120000.0, 'estimate persists');
check((float) $saved['cost_amount'] === 85000.0, 'actual amount still persists');
check(($saved['cost_evidence'] ?? '') === '견적서 2026-9', 'cost evidence memo persists');
check(($saved['cost_budget_program'] ?? '') === '방과후', 'cost links to budget program');
check(($saved['status'] ?? '') === 'open', 'cost extras do not change report status');

$ownerSave = ReportCost::save(
    $pdo,
    $owner,
    $costRep,
    '85000',
    '대한용접',
    '시설유지비',
    '2026-09-16',
    '110000',
    null,
    '/uploads/report-cost/quote.jpg',
    '특화교육',
);
check((float) $ownerSave['cost_estimate'] === 110000.0, 'owner can overwrite estimate');
check(($ownerSave['cost_evidence'] ?? '') === '/uploads/report-cost/quote.jpg', 'evidence photo path persists');
check(($ownerSave['cost_budget_program'] ?? '') === '특화교육', 'owner can change budget program');

$keep = ReportCost::save($pdo, $owner, $costRep, '85000', '대한용접', '시설유지비', '2026-09-16', '110000', '', null, '특화교육');
check(($keep['cost_evidence'] ?? '') === '/uploads/report-cost/quote.jpg', 'empty evidence keeps the previous photo');

$checkRow = Inventory::start($pdo, $owner, 'room');
reject(static function () use ($pdo, $teacher, $checkRow): void {
    Inventory::signTeachers($pdo, $teacher, $checkRow['id'], 'owner', 'manager');
}, $pdo, 'Teacher 실사조서 sign');
reject(static function () use ($pdo, $student, $checkRow): void {
    Inventory::signTeachers($pdo, $student, $checkRow['id'], 'owner', 'manager');
}, $pdo, 'Student 실사조서 sign');
reject(static function () use ($pdo, $owner, $checkRow): void {
    Inventory::signTeachers($pdo, $owner, $checkRow['id'], 'student', 'manager');
}, $pdo, 'Student cannot be 입회 교사');

Inventory::confirm($pdo, $manager, '전장-ast-1');
$done = Inventory::finish($pdo, $manager, $checkRow['id'], 'teacher', 'owner');
check(($done['attending_teacher_id'] ?? '') === 'teacher', 'finish stores 입회 교사');
check(($done['attending_teacher_name'] ?? '') === 'teacher', 'finish stores 입회 이름');
check(($done['confirming_teacher_id'] ?? '') === 'owner', 'finish stores 확인 교사');
check(($done['status'] ?? '') === 'done', 'finish still closes the session');

$signed = Inventory::signTeachers($pdo, $owner, $done['id'], 'manager', 'teacher');
check(($signed['attending_teacher_name'] ?? '') === 'manager', 'sign updates 입회');
check(($signed['confirming_teacher_name'] ?? '') === 'teacher', 'sign updates 확인');

$lines = InventoryBudget::lines($pdo, ['check_id' => $done['id']]);
check($lines !== [], 'budget report still lists the session');
check(($lines[0]['attending_teacher_name'] ?? '') === 'manager', 'budget lines expose 입회 교사');
check(($lines[0]['confirming_teacher_name'] ?? '') === 'teacher', 'budget lines expose 확인 교사');
$csv = InventoryBudget::csv($pdo, ['check_id' => $done['id']]);
check(str_contains($csv, '입회교사') && str_contains($csv, '확인교사'), 'budget CSV has 실사조서 teacher headers');
check(str_contains($csv, 'manager') && str_contains($csv, 'teacher'), 'budget CSV includes signed names');

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
$old->exec(
    "CREATE TABLE inventory_checks (
      id TEXT PRIMARY KEY,
      location_id TEXT NOT NULL,
      location_name TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'active',
      started_by TEXT NOT NULL,
      started_at TEXT NOT NULL,
      finished_at TEXT
    )"
);
Database::migrate($old);
$oldLoanCols = array_column($old->query('PRAGMA table_info(loans)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$oldRepCols = array_column($old->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$oldAstCols = array_column($old->query('PRAGMA table_info(assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$oldInvCols = array_column($old->query('PRAGMA table_info(inventory_checks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('return_condition', $oldLoanCols, true) && in_array('return_note', $oldLoanCols, true), 'migrate adds loan return fields');
check(in_array('discovered_at', $oldRepCols, true) && in_array('urgency', $oldRepCols, true) && in_array('prefer', $oldRepCols, true), 'migrate adds repair intake fields');
check(in_array('cost_estimate', $oldRepCols, true) && in_array('cost_evidence', $oldRepCols, true) && in_array('cost_budget_program', $oldRepCols, true), 'migrate adds cost extras');
check(in_array('retire_source', $oldAstCols, true) && in_array('retire_report_id', $oldAstCols, true), 'migrate adds retire handoff columns');
check(in_array('attending_teacher_id', $oldInvCols, true) && in_array('confirming_teacher_name', $oldInvCols, true), 'migrate adds 실사조서 columns');
Database::migrate($old);
check(true, 'field-handoff migrate is idempotent');

check(Auth::canLoan($teacher) && Auth::canWrite($owner) && !Auth::canWrite($teacher), 'teacher can desk/repair, only manager+ write retire/cost/실사');
check(Auth::canInventory($manager) && !Auth::canInventory($teacher), '실사조서 stays on canInventory');

$deskTpl = (string) file_get_contents($root . '/templates/desk/index.php');
$assetTpl = (string) file_get_contents($root . '/templates/assets/show.php');
$agingTpl = (string) file_get_contents($root . '/templates/assets/aging.php');
$reportTpl = (string) file_get_contents($root . '/templates/reports/show.php');
$invShow = (string) file_get_contents($root . '/templates/inventory/show.php');
$invResult = (string) file_get_contents($root . '/templates/inventory/result.php');
$invReport = (string) file_get_contents($root . '/templates/inventory/report.php');
$deskCtl = (string) file_get_contents($root . '/app/Controllers/DeskController.php');
$loanCtl = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
$assetCtl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
$reportCtl = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
$invCtl = (string) file_get_contents($root . '/app/Controllers/InventoryController.php');
$router = (string) file_get_contents($root . '/app/Router.php');
$homeTpl = (string) file_get_contents($root . '/templates/home/index.php');

check(str_contains($deskCtl, 'borrower_note') && str_contains($deskCtl, 'purpose'), 'desk controller posts purpose and 학번/비고');
check(str_contains($loanCtl, 'return_condition') && str_contains($loanCtl, 'return_note'), 'shared return stores 이상유무');
check(str_contains($deskTpl, 'name="purpose"') && str_contains($deskTpl, 'name="borrower_note"'), 'desk UI has purpose and 학번/비고');
check(str_contains($deskTpl, 'loan_return_check.php'), 'desk return uses 이상유무 partial');
check(str_contains($assetTpl, 'name="discovered_at"') && str_contains($assetTpl, 'name="urgency"') && str_contains($assetTpl, 'name="prefer"'), 'asset repair form has intake fields');
check(str_contains($assetTpl, 'retire_from') && str_contains($assetTpl, '불용·파기 진행'), 'asset show offers 불용·파기 from 수리불가');
check(str_contains($agingTpl, 'retire_from') && str_contains($agingTpl, 'useful_life') && str_contains($agingTpl, '불용·파기'), 'aging exceeded offers 불용·파기');
check(str_contains($reportTpl, '불용·파기 진행') && str_contains($reportTpl, 'RETIRE_SOURCE_REPAIR'), 'rejected report hands off to 파기');
check(str_contains($reportTpl, 'name="cost_estimate"') && str_contains($reportTpl, 'name="cost_budget_program"'), 'cost form has estimate and 사업예산');
check(str_contains($reportTpl, 'name="cost_evidence"') && str_contains($reportTpl, 'cost_evidence_photo'), 'cost form has evidence memo/photo');
check(str_contains($reportCtl, 'STATUS_REJECTED') && str_contains($reportCtl, '파기로 이어갈'), 'reject lands on the handoff page');
check(str_contains($assetCtl, 'discovered_at') && str_contains($assetCtl, 'retire_source'), 'asset controller saves intake and retire source');
check(str_contains($invShow, 'attending_teacher_id') && str_contains($invShow, 'confirming_teacher_id'), 'finish form collects 입회·확인');
check(str_contains($invResult, 'inventory_sign.php') && str_contains($invReport, 'inventory_sign.php'), 'result and budget report reuse 실사조서');
check(str_contains($invCtl, 'function sign') && str_contains($invCtl, 'Csrf::requirePost()'), 'inventory/sign is POST+CSRF');
check(str_contains($router, "'inventory/sign' => [InventoryController::class, 'sign']"), 'inventory/sign is registered');
check(!str_contains($deskTpl, 'new Vue') && !str_contains($reportTpl, 'createApp'), 'handoff UI stays SSR');
check(!str_contains($homeTpl, '초임 용어를 다시') && str_contains($homeTpl, '대여 데스크'), '#76 home copy is left alone');

echo "PASS: {$checks} field-handoff checks\n";
