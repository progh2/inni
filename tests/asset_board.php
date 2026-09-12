<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Alert;
use Inni\AssetBoard;
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

function memoryDb(): PDO
{
    global $root;
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
    return $pdo;
}

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

function seedBoard(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,null,'LOC:bldg','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('elec','전자실습실','room','bldg','E-201','LOC:elec','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('cab','계측기 캐비닛','storage','elec',null,'LOC:cab','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('weld','용접실','room','bldg','W-103','LOC:weld','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('solder','납땜','consumable','CAT:solder','m',5,'t','t')");
    $pdo->exec("INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES('owner','o@t','owner','owner','active','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-avail','eq','스코프 #1','전장-1','available','cab','AST:1','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-loan','eq','스코프 #2','전장-2','available','elec','AST:2','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-over','eq','스코프 #3','전장-3','available','elec','AST:3','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-repair','eq','용접기 A','용접-1','repair','weld','AST:4','t','t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','solder','elec',18,'t')");
}

function ids(array $rows): array
{
    return array_values(array_map(static fn(array $row): string => (string) $row['id'], $rows));
}

$pdo = memoryDb();
seedBoard($pdo);
$owner = actor('owner', 'owner');

$all = AssetBoard::list($pdo, []);
check(ids($all) === ['ast-repair', 'ast-avail', 'ast-loan', 'ast-over'], 'board lists all assets without a search query');

$available = AssetBoard::list($pdo, ['status' => 'available']);
check(ids($available) === ['ast-avail', 'ast-loan', 'ast-over'], 'status=available matches asset.status');

$repair = AssetBoard::list($pdo, ['status' => 'repair']);
check(ids($repair) === ['ast-repair'], 'status=repair returns only repair assets');

$unknown = AssetBoard::list($pdo, ['status' => 'not-a-status']);
check(count($unknown) === 4, 'unknown status is ignored (fail-open read filter)');

$emptyBudget = ['budget_program' => null, 'budget_year' => null];
$parsedUnknown = AssetBoard::filtersFromRequest(['status' => 'broken', 'q' => '오실로', 'overdue' => 'yes']);
check($parsedUnknown === ['status' => null, 'room' => null, 'overdue' => false] + $emptyBudget, 'request parser ignores search q and invalid overdue/status');

$parsedOk = AssetBoard::filtersFromRequest(['status' => 'on_loan', 'room' => 'elec', 'overdue' => '1']);
check($parsedOk === ['status' => 'on_loan', 'room' => 'elec', 'overdue' => true] + $emptyBudget, 'request parser keeps status/room/overdue');

$parsedBudget = AssetBoard::filtersFromRequest(['budget_program' => ' 방과후 ', 'budget_year' => '2026']);
check($parsedBudget === ['status' => null, 'room' => null, 'overdue' => false, 'budget_program' => '방과후', 'budget_year' => 2026], 'request parser keeps budget program/year');
check(AssetBoard::filtersFromRequest(['budget_year' => '26', 'budget_program' => str_repeat('가', 201)]) === ['status' => null, 'room' => null, 'overdue' => false] + $emptyBudget, 'invalid budget filters are ignored');

$elec = AssetBoard::list($pdo, ['room' => 'elec']);
check(ids($elec) === ['ast-avail', 'ast-loan', 'ast-over'], 'room filter includes descendant storage locations');

$weld = AssetBoard::list($pdo, ['room' => 'weld']);
check(ids($weld) === ['ast-repair'], 'room filter stays in that room');

$rooms = AssetBoard::rooms($pdo);
$roomIds = array_column($rooms, 'id');
sort($roomIds);
check($roomIds === ['elec', 'weld'], 'room filter options are rooms only');

$futureDue = gmdate('c', time() + 3600);
$pastDue = gmdate('c', time() - 3600);
Loan::checkout($pdo, $owner, 'ast-loan', '이수업', null, '수업', $futureDue);
Loan::checkout($pdo, $owner, 'ast-over', '박학생', null, '야간', $pastDue);

$onLoan = AssetBoard::list($pdo, ['status' => 'on_loan']);
check(ids($onLoan) === ['ast-over', 'ast-loan'], 'on_loan filter uses asset.status after checkout');

$overdue = AssetBoard::list($pdo, ['overdue' => true]);
check(ids($overdue) === ['ast-over'], 'checkout of a past-due loan is overdue (same refresh as 대여)');
check(AssetBoard::displayStatus($overdue[0]) === 'overdue', 'overdue loan wins the board badge over asset on_loan');
check(($overdue[0]['status'] ?? '') === 'on_loan', 'overdue asset.status stays on_loan');

$pdo->exec("UPDATE loans SET status = 'active' WHERE asset_id = 'ast-over' AND status = 'overdue'");
check(AssetBoard::list($pdo, ['overdue' => true]) === [], 'stale active past-due is hidden until refreshOverdue');
Alert::refreshOverdue($pdo);
$overdue = AssetBoard::list($pdo, ['overdue' => true]);
check(ids($overdue) === ['ast-over'], 'overdue filter follows loan.status after refreshOverdue');

$stillOnLoan = AssetBoard::list($pdo, ['status' => 'on_loan']);
check(ids($stillOnLoan) === ['ast-over', 'ast-loan'], 'on_loan filter still includes overdue assets');
check(AssetBoard::displayStatus($stillOnLoan[0]) === 'overdue', 'overdue rows sort and badge first');
check(AssetBoard::displayStatus($stillOnLoan[1]) === 'on_loan', 'future-due loan stays 대여중');

$combo = AssetBoard::list($pdo, ['status' => 'on_loan', 'room' => 'elec', 'overdue' => true]);
check(ids($combo) === ['ast-over'], 'status+room+overdue combine with AND');

$emptyCombo = AssetBoard::list($pdo, ['status' => 'available', 'overdue' => true]);
check($emptyCombo === [], 'available+overdue is empty (asset on_loan while loan overdue)');

$summary = AssetBoard::summary($pdo);
check($summary['total'] === 4, 'summary total is all assets');
check($summary['by_status']['on_loan'] === 2, 'summary on_loan count');
check($summary['by_status']['repair'] === 1, 'summary repair count');
check($summary['overdue'] === 1, 'summary overdue follows loan.status');

Loan::checkin($pdo, $owner, (string) $overdue[0]['loan_id']);
$afterReturn = AssetBoard::list($pdo, ['overdue' => true]);
check($afterReturn === [], 'returned loan leaves the overdue filter');
$availableAgain = AssetBoard::list($pdo, ['status' => 'available']);
check(in_array('ast-over', ids($availableAgain), true), 'returned asset is available again');

$pdo->exec("UPDATE assets SET budget_program='방과후', budget_year=2026 WHERE id='ast-avail'");
$pdo->exec("UPDATE assets SET budget_program='방과후', budget_year=2025 WHERE id='ast-loan'");
$pdo->exec("UPDATE assets SET budget_program='특화교육', budget_year=2026 WHERE id='ast-repair'");

$byProgram = AssetBoard::list($pdo, ['budget_program' => '방과후']);
check(ids($byProgram) === ['ast-loan', 'ast-avail'], 'budget_program exact filter uses assets.budget_program');
$byYear = AssetBoard::list($pdo, ['budget_year' => '2026']);
check(ids($byYear) === ['ast-repair', 'ast-avail'], 'budget_year exact filter uses assets.budget_year');
$byBoth = AssetBoard::list($pdo, AssetBoard::filtersFromRequest(['budget_program' => '방과후', 'budget_year' => '2026']));
check(ids($byBoth) === ['ast-avail'], 'budget program + year together');
$comboBudget = AssetBoard::list($pdo, ['status' => 'available', 'budget_program' => '방과후']);
check(ids($comboBudget) === ['ast-avail'], 'status + budget program combine with AND');
$ignoredBudget = AssetBoard::list($pdo, AssetBoard::filtersFromRequest(['budget_year' => '26', 'budget_program' => str_repeat('가', 201)]));
check(count($ignoredBudget) === 4, 'invalid budget filters do not hide the board');
check(isset($byProgram[0]['budget_program'], $byProgram[0]['budget_year']), 'board rows include budget columns');

$boardSrc = (string) file_get_contents($root . '/app/AssetBoard.php');
check(str_contains($boardSrc, 'FROM assets'), 'board query is assets, not catalog browse');
check(!str_contains($boardSrc, 'FROM catalog_items'), 'board does not invent #29 catalog browse');
check(!str_contains($boardSrc, "LIKE ?"), 'board filters are exact status/room/overdue/budget, not search');
check(str_contains($boardSrc, 'Budget::queryFilters') && str_contains($boardSrc, 'Budget::filterSql'), 'board uses #32 budget filter hooks');

$ctl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
check(str_contains($ctl, 'function index'), 'asset controller has board index');
check(str_contains($ctl, 'Alert::refreshOverdue'), 'board refreshes overdue like home/loans');
check(str_contains($ctl, 'AssetBoard::list'), 'board uses AssetBoard query');
check(str_contains($ctl, 'Auth::requireLogin()'), 'board requires login');
check(!preg_match('/function index\(\): void\s*\{[^}]*canWrite/', $ctl), 'board browse is not write-gated');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'assets' => [AssetController::class, 'index']"), 'router registers assets board');

$tpl = (string) file_get_contents($root . '/templates/assets/index.php');
check(str_contains($tpl, 'name="status"') && str_contains($tpl, 'name="room"') && str_contains($tpl, 'name="overdue"'), 'board form has status/room/overdue');
check(str_contains($tpl, 'name="budget_program"') && str_contains($tpl, 'name="budget_year"'), 'board form has budget filters');
check(str_contains($tpl, 'Budget::format'), 'board displays budget on rows');
check(str_contains($tpl, 'method="get"'), 'board filters are GET/SSR');
check(!preg_match('/name=["\']q["\']/', $tpl), 'board does not require a search box');
check(!str_contains($tpl, 'catalog_items') && !str_contains($tpl, '품목 ('), 'board template is assets-only');
check(!str_contains($tpl, '상태·실·연체 필터는 곧 제공됩니다'), 'board replaced the #31 stub');

$more = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($more, "App::url('assets')") && str_contains($more, '기자재 현황'), 'more menu links to the board');

$home = (string) file_get_contents($root . '/templates/home/index.php');
check(str_contains($home, "App::url('assets')"), 'home links to the board');

echo "PASS: {$checks} asset-board checks\n";
