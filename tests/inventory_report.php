<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\CatalogCsv;
use Inni\Inventory;
use Inni\InventoryReport;

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

function actor(string $id, string $role): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => 'active'];
}

function ids(array $rows, string $key = 'id'): array
{
    $out = array_values(array_map(static fn (array $row): string => (string) $row[$key], $rows));
    sort($out);
    return $out;
}

check(InventoryReport::filtersFromRequest([
    'budget_program' => ' 방과후 ',
    'budget_year' => '2026',
    'check_id' => ' inv_1 ',
]) === ['budget_program' => '방과후', 'budget_year' => 2026, 'check_id' => 'inv_1'], 'filtersFromRequest parses program/year/check');
check(InventoryReport::filtersFromRequest([
    'budget_year' => '26',
    'budget_program' => str_repeat('가', 201),
    'check_id' => '',
]) === ['budget_program' => null, 'budget_year' => null, 'check_id' => null], 'invalid budget filters are ignored');
check(InventoryReport::filtersFromRequest([]) === [
    'budget_program' => null,
    'budget_year' => null,
    'check_id' => null,
], 'empty filters');
check(InventoryReport::queryParams(['budget_program' => '방과후', 'budget_year' => 2026]) === [
    'budget_program' => '방과후',
    'budget_year' => '2026',
], 'queryParams keeps budget GET keys');
check(InventoryReport::headerRow() === [
    '실사ID', '실', '실사상태', '시작', '종료', '종류', '품명', '코드', '위치',
    '사업명', '예산연도', '장부수량', '실물수량', '차이', '확인여부',
], 'CSV header columns');

$pdo = memoryDb();
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,null,'LOC:bldg','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('room','전자실습실','room','bldg','E-201','LOC:room','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('cab','계측기 캐비닛','storage','room',null,'LOC:cab','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('other','용접실','room','bldg','W-103','LOC:other','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,budget_program,budget_year,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','ea','방과후',2026,'t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,budget_program,budget_year,created_at,updated_at) VALUES('solder','납땜','consumable','CAT:solder','m','방과후',2026,'t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,budget_program,budget_year,created_at,updated_at) VALUES('other-eq','용접기','equipment','CAT:other-eq','ea','특화교육',2025,'t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,budget_program,budget_year,created_at,updated_at) VALUES('wire','전선','consumable','CAT:wire','m','특화교육',2026,'t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,budget_program,budget_year,created_at,updated_at) VALUES('ast-1','eq','스코프 #1','전장-2024-017','available','cab','AST:ast-1','방과후',2026,'t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,budget_program,budget_year,created_at,updated_at) VALUES('ast-2','eq','스코프 #2','전장-2024-018','available','room','AST:ast-2','방과후',2025,'t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,budget_program,budget_year,created_at,updated_at) VALUES('ast-other','other-eq','용접기 A','용접-2022-004','available','other','AST:ast-other','특화교육',2025,'t','t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','solder','room',18,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-wire','wire','other',4,'t')");
insertUser($pdo, 'owner', 'owner');
$owner = actor('owner', 'owner');

$first = Inventory::start($pdo, $owner, 'room');
Inventory::confirm($pdo, $owner, '전장-2024-017');
$firstDone = Inventory::finish($pdo, $owner, $first['id']);
check($firstDone['status'] === 'done', 'first session finished');

$second = Inventory::start($pdo, $owner, 'other');
$secondDone = Inventory::finish($pdo, $owner, $second['id']);
check($secondDone['status'] === 'done', 'second session finished');

$allDiff = InventoryReport::differences($pdo);
check(ids($allDiff) === ids(array_values(array_filter(
    $pdo->query('SELECT id FROM inventory_check_lines WHERE confirmed_at IS NULL')->fetchAll(PDO::FETCH_ASSOC)
))), 'unfiltered differences are unconfirmed lines');
$allNames = array_column($allDiff, 'name');
check(in_array('스코프 #2', $allNames, true) && in_array('납땜', $allNames, true), 'first session missing asset and lot');
check(in_array('용접기 A', $allNames, true) && in_array('전선', $allNames, true), 'second session missing asset and lot');
check(!in_array('스코프 #1', $allNames, true), 'confirmed asset is not a difference');

$scope2 = null;
$solder = null;
foreach ($allDiff as $row) {
    if ($row['name'] === '스코프 #2') {
        $scope2 = $row;
    }
    if ($row['name'] === '납땜') {
        $solder = $row;
    }
}
check(is_array($scope2) && (float) $scope2['book_qty'] === 1.0 && (float) $scope2['physical_qty'] === 0.0 && (float) $scope2['diff_qty'] === 1.0, 'asset difference is book 1 vs physical 0');
check(is_array($solder) && (float) $solder['book_qty'] === 18.0 && (float) $solder['physical_qty'] === 0.0 && (float) $solder['diff_qty'] === 18.0, 'item difference uses expected_qty as book');
check(is_array($scope2) && $scope2['budget_program'] === '방과후' && (int) $scope2['budget_year'] === 2025, 'asset difference uses assets.budget_*');
check(is_array($solder) && $solder['budget_program'] === '방과후' && (int) $solder['budget_year'] === 2026, 'item difference uses catalog_items.budget_*');

$byProgram = InventoryReport::differences($pdo, ['budget_program' => '방과후']);
check(ids($byProgram, 'name') === ['납땜', '스코프 #2'], 'budget_program filter keeps after-school lines only');

$byYear = InventoryReport::differences($pdo, ['budget_year' => 2026]);
check(ids($byYear, 'name') === ['납땜', '전선'], 'budget_year filter uses purchase/budget year');

$byBoth = InventoryReport::differences($pdo, InventoryReport::filtersFromRequest([
    'budget_program' => '방과후',
    'budget_year' => '2026',
]));
check(ids($byBoth, 'name') === ['납땜'], 'program+year filter is exact');

$ignored = InventoryReport::differences($pdo, InventoryReport::filtersFromRequest([
    'budget_year' => '26',
    'budget_program' => str_repeat('가', 201),
]));
check(count($ignored) === count($allDiff), 'invalid filters do not shrink the difference list');

$oneSession = InventoryReport::differences($pdo, ['check_id' => $first['id'], 'budget_program' => '방과후']);
check(ids($oneSession, 'name') === ['납땜', '스코프 #2'], 'check_id + program stays inside that session');

$sessions = InventoryReport::sessions($pdo, ['budget_program' => '방과후']);
check(count($sessions) === 1 && $sessions[0]['id'] === $first['id'], 'sessions filter hides checks with no matching budget lines');
check((int) $sessions[0]['book_lines'] === 3 && (int) $sessions[0]['confirmed_lines'] === 1 && (int) $sessions[0]['missing_lines'] === 2, 'session aggregates book/confirmed/missing for matching lines');

$yearSessions = InventoryReport::sessions($pdo, ['budget_year' => 2025]);
$yearIds = ids($yearSessions);
$expectedYearIds = [$first['id'], $second['id']];
sort($expectedYearIds);
check($yearIds === $expectedYearIds, 'year 2025 hits both rooms via different programs');

$aggregates = InventoryReport::aggregates($pdo, []);
$keys = [];
foreach ($aggregates as $row) {
    $keys[] = (string) ($row['budget_program'] ?? '') . ':' . (string) ($row['budget_year'] ?? '');
}
check(in_array('방과후:2026', $keys, true) && in_array('특화교육:2025', $keys, true), 'aggregates group by program/year');

$afterSchool = null;
foreach (InventoryReport::aggregates($pdo, ['budget_program' => '방과후']) as $row) {
    if ((string) $row['budget_program'] === '방과후' && (int) $row['budget_year'] === 2026) {
        $afterSchool = $row;
    }
}
check(is_array($afterSchool) && (int) $afterSchool['book_lines'] === 2 && (int) $afterSchool['missing_lines'] === 1, 'filtered aggregate counts confirmed vs missing');

$exported = InventoryReport::export($pdo, ['budget_program' => '방과후', 'budget_year' => 2026]);
check($exported['count'] === 1, 'CSV export count matches filtered differences');
check(!str_contains($exported['csv'], CatalogCsv::BOM), 'export helper leaves BOM to the controller');
foreach (InventoryReport::headerRow() as $header) {
    check(str_contains($exported['csv'], $header), 'CSV contains column ' . $header);
}
check(str_contains($exported['csv'], '납땜') && str_contains($exported['csv'], '방과후') && str_contains($exported['csv'], '2026'), 'CSV includes budget values');
check(str_contains($exported['csv'], '장부수량') && str_contains($exported['csv'], '실물수량') && str_contains($exported['csv'], '차이'), 'CSV has book/physical/diff columns');
check(str_contains($exported['csv'], '미확인'), 'CSV marks unconfirmed rows');
check(!str_contains($exported['csv'], '스코프 #1'), 'CSV omits confirmed lines');
check(!str_contains($exported['csv'], '용접기 A'), 'CSV respects budget filter');
$row = $exported['rows'][1] ?? [];
check($row !== [] && $row[array_search('장부수량', $exported['rows'][0], true)] === '18', 'CSV book qty is expected_qty');
check($row[array_search('실물수량', $exported['rows'][0], true)] === '0', 'CSV physical qty is 0 when unchecked');
check($row[array_search('차이', $exported['rows'][0], true)] === '18', 'CSV diff qty is book minus physical');

$options = InventoryReport::sessionOptions($pdo);
check(count($options) === 2, 'session dropdown lists both checks');

$ctl = (string) file_get_contents($root . '/app/Controllers/InventoryController.php');
check(str_contains($ctl, 'function report') && str_contains($ctl, 'function export'), 'controller has report and GET export');
check(str_contains($ctl, 'Auth::canInventory($user)'), 'report stays behind canInventory');
check(str_contains($ctl, 'InventoryReport::filtersFromRequest'), 'controller uses report filters');
check(!str_contains($ctl, 'Csrf::requirePost()') || substr_count($ctl, 'function export') === 1, 'export action exists');
$exportFn = strstr($ctl, 'function export');
check(is_string($exportFn) && !str_contains(substr($exportFn, 0, 400), 'Csrf::requirePost'), 'CSV export is GET like catalog export');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'inventory/report' => [InventoryController::class, 'report']"), 'report route');
check(str_contains($router, "'inventory/report/csv' => [InventoryController::class, 'export']"), 'GET csv route');

$tpl = (string) file_get_contents($root . '/templates/inventory/report.php');
check(str_contains($tpl, 'name="budget_program"') && str_contains($tpl, 'name="budget_year"'), 'report form has budget filters');
check(str_contains($tpl, '차이 목록') && str_contains($tpl, '장부 vs 실물'), 'report shows difference list');
check(str_contains($tpl, "App::url('inventory/report/csv'"), 'report links to GET CSV');

$more = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($more, "App::url('inventory/report')") && str_contains($more, '실사 리포트'), 'more menu links to report');

$flow = (string) file_get_contents($root . '/app/Inventory.php');
check(str_contains($flow, 'function start') && str_contains($flow, 'function confirm') && str_contains($flow, 'function finish'), 'existing check flow is unchanged');

echo "PASS: {$checks} inventory report checks\n";
