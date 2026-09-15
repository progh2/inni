<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\CatalogCsv;
use Inni\Inventory;
use Inni\InventoryBudget;

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

function memoryDb(): PDO
{
    global $root;
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
    return $pdo;
}

function insertUser(PDO $pdo, string $id, string $role, string $status = 'active'): void
{
    $pdo->prepare(
        'INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)'
    )->execute([$id, $id . '@test', $id, $role, $status, 't', 't']);
}

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

/**
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>}
 */
function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM inventory_checks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, confirmed_at FROM inventory_check_lines ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, budget_program, budget_year FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<string>
 */
function names(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = (string) $row['name'];
    }
    sort($out);
    return $out;
}

$pdo = memoryDb();
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,null,'LOC:bldg','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('room','전자실습실','room','bldg','E-201','LOC:room','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('other','용접실','room','bldg','W-103','LOC:other','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,budget_program,budget_year,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','ea',null,null,'t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,budget_program,budget_year,created_at,updated_at) VALUES('solder','납땜','consumable','CAT:solder','m','방과후',2026,'t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,budget_program,budget_year,created_at,updated_at) VALUES('other-eq','용접기','equipment','CAT:other-eq','ea',null,null,'t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,budget_program,budget_year,created_at,updated_at) VALUES('ast-1','eq','스코프 #1','전장-2024-017','available','room','AST:ast-1','방과후',2026,'t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,budget_program,budget_year,created_at,updated_at) VALUES('ast-2','eq','스코프 #2','전장-2024-018','on_loan','room','AST:ast-2','방과후',2025,'t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,budget_program,budget_year,created_at,updated_at) VALUES('ast-other','other-eq','용접기 A','용접-2022-004','available','other','AST:ast-other','특화교육',2026,'t','t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','solder','room',18,'t')");
foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher'] as $id => $role) {
    insertUser($pdo, $id, $role);
}

$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');

$empty = InventoryBudget::filtersFromRequest([]);
check($empty === [
    'budget_program' => null,
    'budget_year' => null,
    'check_status' => null,
    'check_id' => null,
    'diff' => 'all',
], 'empty request uses all sessions and all diffs');

$parsed = InventoryBudget::filtersFromRequest([
    'budget_program' => ' 방과후 ',
    'budget_year' => '2026',
    'check_status' => 'done',
    'check_id' => ' inv_1 ',
    'diff' => 'missing',
]);
check($parsed === [
    'budget_program' => '방과후',
    'budget_year' => 2026,
    'check_status' => 'done',
    'check_id' => 'inv_1',
    'diff' => 'missing',
], 'request parser keeps budget/session/diff');

$ignored = InventoryBudget::filtersFromRequest([
    'budget_year' => '26',
    'budget_program' => str_repeat('가', 201),
    'check_status' => 'open',
    'diff' => 'unexpected',
]);
check($ignored['budget_program'] === null && $ignored['budget_year'] === null, 'invalid budget filters are ignored');
check($ignored['check_status'] === null && $ignored['diff'] === 'all', 'invalid status/diff fall back to all');

check(InventoryBudget::query($parsed) === [
    'budget_program' => '방과후',
    'budget_year' => '2026',
    'check_status' => 'done',
    'check_id' => 'inv_1',
    'diff' => 'missing',
], 'query helper drops empty defaults');
check(InventoryBudget::query($empty) === [], 'query helper omits all-default filters');

$room = Inventory::start($pdo, $owner, 'room');
Inventory::confirm($pdo, $manager, '전장-2024-017');
Inventory::confirm($pdo, $owner, 'CAT:solder');
$done = Inventory::finish($pdo, $owner, $room['id']);
check($done['status'] === 'done', 'seed session finishes');

$other = Inventory::start($pdo, $manager, 'other');

$all = InventoryBudget::lines($pdo);
check(count($all) === 4, 'default includes finished and active lines');
check(names($all) === ['납땜', '스코프 #1', '스코프 #2', '용접기 A'], 'default names cover both rooms');

$byProgram = InventoryBudget::lines($pdo, ['budget_program' => '방과후']);
check(names($byProgram) === ['납땜', '스코프 #1', '스코프 #2'], 'budget_program uses asset or catalog budget');
check(!in_array('용접기 A', names($byProgram), true), 'other program is excluded');

$byYear = InventoryBudget::lines($pdo, ['budget_year' => 2026]);
check(names($byYear) === ['납땜', '스코프 #1', '용접기 A'], 'budget_year matches asset or catalog year');

$byBoth = InventoryBudget::lines($pdo, InventoryBudget::filtersFromRequest([
    'budget_program' => '방과후',
    'budget_year' => '2026',
]));
check(names($byBoth) === ['납땜', '스코프 #1'], 'program+year intersection');

$doneOnly = InventoryBudget::lines($pdo, ['check_status' => 'done']);
check(names($doneOnly) === ['납땜', '스코프 #1', '스코프 #2'], 'check_status=done excludes the active room');

$activeOnly = InventoryBudget::lines($pdo, ['check_status' => 'active']);
check(names($activeOnly) === ['용접기 A'], 'check_status=active keeps the open session');

$oneSession = InventoryBudget::lines($pdo, ['check_id' => $room['id']]);
check(names($oneSession) === ['납땜', '스코프 #1', '스코프 #2'], 'check_id filters to one session');

$missing = InventoryBudget::lines($pdo, ['diff' => 'missing', 'check_id' => $room['id']]);
check(count($missing) === 1 && $missing[0]['name'] === '스코프 #2', 'diff=missing is the unchecked book line');
check($missing[0]['is_missing'] === true && (float) $missing[0]['physical_qty'] === 0.0, 'missing physical qty is 0');
check((float) $missing[0]['diff_qty'] === (float) $missing[0]['expected_qty'], 'missing diff equals book qty');

$confirmed = InventoryBudget::lines($pdo, ['diff' => 'confirmed', 'check_id' => $room['id']]);
check(names($confirmed) === ['납땜', '스코프 #1'], 'diff=confirmed lists scanned lines');
foreach ($confirmed as $line) {
    check($line['is_confirmed'] === true && (float) $line['diff_qty'] === 0.0, 'confirmed lines have zero book-physical diff');
}

$solder = null;
foreach ($byBoth as $line) {
    if ($line['name'] === '납땜') {
        $solder = $line;
    }
}
check(is_array($solder) && (float) $solder['expected_qty'] === 18.0 && (float) $solder['physical_qty'] === 18.0, 'confirmed item keeps book qty as physical');

$ignoredLines = InventoryBudget::lines($pdo, InventoryBudget::filtersFromRequest([
    'budget_year' => '26',
    'budget_program' => str_repeat('가', 201),
]));
check(count($ignoredLines) === count($all), 'invalid budget filters do not shrink the list');

$aggregates = InventoryBudget::aggregates($pdo, ['budget_program' => '방과후']);
check(count($aggregates) === 2, '방과후 aggregates split by year');
$byKey = [];
foreach ($aggregates as $row) {
    $byKey[(string) (int) $row['budget_year']] = $row;
}
check((int) $byKey['2026']['expected_count'] === 2 && (int) $byKey['2026']['confirmed_count'] === 2 && (int) $byKey['2026']['missing_count'] === 0, '2026 방과후 is fully confirmed');
check((float) $byKey['2026']['expected_qty'] === 19.0 && (float) $byKey['2026']['confirmed_qty'] === 19.0, '2026 방과후 qty is scope + solder');
check((int) $byKey['2025']['expected_count'] === 1 && (int) $byKey['2025']['missing_count'] === 1, '2025 방과후 is the missing scope');
check((float) $byKey['2025']['diff_qty'] === 1.0, '2025 aggregate diff is the missing book qty');

$summary = InventoryBudget::summary($pdo, ['budget_program' => '방과후', 'budget_year' => 2026]);
check($summary['expected_count'] === 2 && $summary['confirmed_count'] === 2 && $summary['missing_count'] === 0, 'summary counts match filtered lines');
check($summary['check_count'] === 1, 'summary counts distinct sessions');

$sessionList = InventoryBudget::checks($pdo);
check(count($sessionList) === 2 && $sessionList[0]['status'] === 'active', 'session list puts the active check first');

$csv = InventoryBudget::csv($pdo, ['budget_program' => '방과후', 'budget_year' => 2026]);
check(!str_contains($csv, CatalogCsv::BOM), 'csv helper does not include BOM');
check(str_contains($csv, '사업명') && str_contains($csv, '장부수량') && str_contains($csv, '실물수량') && str_contains($csv, '차이'), 'csv has book/physical headers');
check(str_contains($csv, '스코프 #1') && str_contains($csv, '납땜') && !str_contains($csv, '용접기 A'), 'csv respects budget filter');
check(str_contains($csv, '확인') && str_contains($csv, '방과후') && str_contains($csv, '2026'), 'csv includes result and budget labels');

$before = snapshot($pdo);
InventoryBudget::lines($pdo, ['budget_program' => '방과후']);
InventoryBudget::aggregates($pdo, ['budget_year' => 2026]);
InventoryBudget::summary($pdo, []);
InventoryBudget::csv($pdo, ['diff' => 'missing']);
check(snapshot($pdo) === $before, 'report helpers are read-only');

check(InventoryBudget::checkStatusLabel('active') === '진행중' && InventoryBudget::checkStatusLabel('done') === '종료', 'session labels');
check(InventoryBudget::kindLabel('asset') === '장비' && InventoryBudget::formatQty(18.0) === '18', 'kind and qty format');

check(Auth::canInventory($owner) && Auth::canInventory($manager) && !Auth::canInventory($teacher), 'report stays on canInventory');

$ctl = (string) file_get_contents($root . '/app/Controllers/InventoryController.php');
check(str_contains($ctl, 'function report') && str_contains($ctl, 'function reportCsv'), 'controller exposes report + csv');
check(str_contains($ctl, 'Auth::canInventory($user)'), 'report reuses canInventory gate');
check(str_contains($ctl, 'InventoryBudget::filtersFromRequest'), 'report reads GET filters');
check(!str_contains($ctl, 'Csrf::requirePost()') || substr_count($ctl, 'function report') >= 1, 'report methods exist');
check(!preg_match('/function reportCsv\(\)[^{]*\{[^}]*Csrf::requirePost/', $ctl), 'csv export is a GET download');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'inventory/report' => [InventoryController::class, 'report']"), 'report route is registered');
check(str_contains($router, "'inventory/report/csv' => [InventoryController::class, 'reportCsv']"), 'csv route is registered');

$tpl = (string) file_get_contents($root . '/templates/inventory/report.php');
check(str_contains($tpl, '<h1>사업예산 실사</h1>'), 'report heading');
check(str_contains($tpl, 'name="budget_program"') && str_contains($tpl, 'name="budget_year"'), 'report form has budget filters');
check(str_contains($tpl, 'name="check_status"') && str_contains($tpl, 'name="check_id"'), 'report form can pick session status');
check(str_contains($tpl, '장부 vs 실물') && str_contains($tpl, 'CSV 내보내기'), 'report shows diff table and csv');
check(str_contains($tpl, "App::url('inventory/report/csv'"), 'csv link keeps current filters');

$more = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($more, '사업예산 실사') && str_contains($more, "App::url('inventory/report')"), 'more menu links the report');
check(str_contains($more, 'Auth::canInventory($user)'), 'more menu keeps inventory on canInventory');

$index = (string) file_get_contents($root . '/templates/inventory/index.php');
$result = (string) file_get_contents($root . '/templates/inventory/result.php');
check(str_contains($index, "App::url('inventory/report')"), 'start page links the report');
check(str_contains($result, "App::url('inventory/report'"), 'result page links the report');

$stock = (string) file_get_contents($root . '/app/Stock.php');
$desk = (string) file_get_contents($root . '/app/Desk.php');
$loan = (string) file_get_contents($root . '/app/Loan.php');
$material = (string) file_get_contents($root . '/app/MaterialBoard.php');
$inv = (string) file_get_contents($root . '/app/Inventory.php');
check(str_contains($stock, 'function issue') && str_contains($desk, 'function findAsset'), 'M6 desk/stock write paths stay');
check(str_contains($loan, 'function checkout') && str_contains($material, 'function list'), 'loans and materials board stay');
check(str_contains($inv, 'function start') && str_contains($inv, 'function confirm') && str_contains($inv, 'function finish'), 'inventory MVP helpers stay');

echo "PASS: {$checks} inventory-budget checks\n";
