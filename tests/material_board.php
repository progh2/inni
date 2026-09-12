<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\MaterialBoard;

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

function seedBoard(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,null,'LOC:bldg','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('elec','전자실습실','room','bldg','E-201','LOC:elec','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('cab','계측기 캐비닛','storage','elec',null,'LOC:cab','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('weld','용접실','room','bldg','W-103','LOC:weld','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('chair','실습 의자','fixture','CAT:chair','ea',10,'t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('solder','납땜','consumable','CAT:solder','m',5,'t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('wire','전선','consumable','CAT:wire','m',20,'t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('tape','절연테이프','consumable','CAT:tape','ea',null,'t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('res','저항 키트','part','CAT:res','ea',3,'t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('ok-part','베어링','part','CAT:ok','ea',1,'t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('empty','드릴비트','consumable','CAT:empty','ea',2,'t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-1','eq','스코프 #1','전장-1','available','cab','AST:1','t','t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-chair','chair','elec',40,'t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-solder-cab','solder','cab',2,'t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-solder-weld','solder','weld',1,'t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-wire','wire','elec',20,'t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-tape','tape','elec',0,'t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-res','res','weld',1,'t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-ok','ok-part','elec',4,'t')");
    $pdo->exec("UPDATE catalog_items SET budget_program='방과후', budget_year=2026 WHERE id='solder'");
    $pdo->exec("UPDATE catalog_items SET budget_program='방과후', budget_year=2025 WHERE id='wire'");
    $pdo->exec("UPDATE catalog_items SET budget_program='특화교육', budget_year=2026 WHERE id='res'");
}

function ids(array $rows): array
{
    return array_values(array_map(static fn(array $row): string => (string) $row['id'], $rows));
}

function idSet(array $rows): array
{
    $ids = ids($rows);
    sort($ids);
    return $ids;
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT * FROM catalog_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
    ];
}

$pdo = memoryDb();
seedBoard($pdo);
$before = snapshot($pdo);

$all = MaterialBoard::list($pdo, []);
check(idSet($all) === ['empty', 'ok-part', 'res', 'solder', 'tape', 'wire'], 'board lists consumable/part only, no search');
check(!in_array('eq', ids($all), true) && !in_array('chair', ids($all), true), 'equipment and fixture stay off the materials board');

$names = array_map(static fn(array $row): string => (string) $row['name'], $all);
$lowFirst = array_slice($names, 0, 3);
sort($lowFirst);
check($lowFirst === ['납땜', '드릴비트', '저항 키트'], 'low-stock rows sort first, then name');

$byId = [];
foreach ($all as $row) {
    $byId[$row['id']] = $row;
}
check((float) $byId['solder']['stock_qty'] === 3.0, 'lots for the same item are summed');
check((int) $byId['solder']['low_stock'] === 1, '3 < min 5 is low stock');
check((int) $byId['wire']['low_stock'] === 0, 'qty at min_stock is not low stock');
check((int) $byId['tape']['low_stock'] === 0, 'no min_stock is not low stock');
check((int) $byId['res']['low_stock'] === 1 && (int) $byId['ok-part']['low_stock'] === 0, 'part low-stock flag');
check((int) $byId['empty']['low_stock'] === 1 && (float) $byId['empty']['stock_qty'] === 0.0, 'item with no lots and min_stock is low');
check(str_contains((string) $byId['solder']['rooms_label'], '계측기 캐비닛') && str_contains((string) $byId['solder']['rooms_label'], '용접실'), 'row shows lot rooms');
check(MaterialBoard::rowIsLowStock($byId['solder']) === true, 'rowIsLowStock matches catalog/alert rule');
check(MaterialBoard::rowIsLowStock($byId['wire']) === false, 'qty == min_stock is not highlighted');

$consumable = MaterialBoard::list($pdo, ['type' => 'consumable']);
check(idSet($consumable) === ['empty', 'solder', 'tape', 'wire'], 'type=consumable');
$part = MaterialBoard::list($pdo, ['type' => 'part']);
check(idSet($part) === ['ok-part', 'res'], 'type=part');

$unknown = MaterialBoard::list($pdo, ['type' => 'equipment']);
check(idSet($unknown) === idSet($all), 'equipment type is ignored; board stays consumable/part');
$fixtureType = MaterialBoard::list($pdo, ['type' => 'fixture']);
check(idSet($fixtureType) === idSet($all), 'fixture type is ignored; no new type invented');
$garbage = MaterialBoard::list($pdo, ['type' => 'DROP TABLE catalog_items; --']);
check(ids($garbage) === ids($all), 'invalid type is ignored (fail-open read filter)');

$emptyBudget = ['budget_program' => null, 'budget_year' => null];
$parsedUnknown = MaterialBoard::filtersFromRequest(['type' => 'equipment', 'q' => '납땜', 'low_stock' => 'yes']);
check($parsedUnknown === ['type' => null, 'low_stock' => true, 'room' => null] + $emptyBudget, 'request parser ignores search q and non-material type');

$parsedOk = MaterialBoard::filtersFromRequest(['type' => 'part', 'room' => 'elec', 'low_stock' => '1']);
check($parsedOk === ['type' => 'part', 'low_stock' => true, 'room' => 'elec'] + $emptyBudget, 'request parser keeps type/room/low_stock');

$parsedBudget = MaterialBoard::filtersFromRequest(['budget_program' => ' 방과후 ', 'budget_year' => '2026']);
check($parsedBudget === ['type' => null, 'low_stock' => false, 'room' => null, 'budget_program' => '방과후', 'budget_year' => 2026], 'request parser keeps budget program/year');
check(MaterialBoard::filtersFromRequest(['budget_year' => '26', 'budget_program' => str_repeat('가', 201)]) === ['type' => null, 'low_stock' => false, 'room' => null] + $emptyBudget, 'invalid budget filters are ignored');

$low = MaterialBoard::list($pdo, ['low_stock' => true]);
check(idSet($low) === ['empty', 'res', 'solder'], 'low-stock filter uses qty < min_stock');
foreach ($low as $row) {
    check((int) $row['low_stock'] === 1, 'low-stock filter rows are highlighted');
}
$lowOne = MaterialBoard::list($pdo, MaterialBoard::filtersFromRequest(['low_stock' => '1']));
check(ids($lowOne) === ids($low), 'low_stock=1 from query string');

$elec = MaterialBoard::list($pdo, ['room' => 'elec']);
check(idSet($elec) === ['ok-part', 'solder', 'tape', 'wire'], 'room filter includes descendant storage lots');
check(!in_array('res', ids($elec), true) && !in_array('empty', ids($elec), true), 'room filter excludes other rooms and items with no lots');

$weld = MaterialBoard::list($pdo, ['room' => 'weld']);
check(idSet($weld) === ['res', 'solder'], 'room filter stays in that room');

$rooms = MaterialBoard::rooms($pdo);
$roomIds = array_column($rooms, 'id');
sort($roomIds);
check($roomIds === ['elec', 'weld'], 'room filter options are rooms only');

$combo = MaterialBoard::list($pdo, ['type' => 'consumable', 'room' => 'elec', 'low_stock' => true]);
check(idSet($combo) === ['solder'], 'type+room+low_stock combine with AND');

$byProgram = MaterialBoard::list($pdo, ['budget_program' => '방과후']);
check(idSet($byProgram) === ['solder', 'wire'], 'budget_program exact filter uses catalog_items.budget_program');
$byYear = MaterialBoard::list($pdo, ['budget_year' => '2026']);
check(idSet($byYear) === ['res', 'solder'], 'budget_year exact filter uses catalog_items.budget_year');
$byBoth = MaterialBoard::list($pdo, MaterialBoard::filtersFromRequest(['budget_program' => '방과후', 'budget_year' => '2026']));
check(idSet($byBoth) === ['solder'], 'budget program + year together');
$comboBudget = MaterialBoard::list($pdo, ['type' => 'consumable', 'budget_program' => '방과후']);
check(idSet($comboBudget) === ['solder', 'wire'], 'type + budget program combine with AND');
$ignoredBudget = MaterialBoard::list($pdo, MaterialBoard::filtersFromRequest(['budget_year' => '26', 'budget_program' => str_repeat('가', 201)]));
check(idSet($ignoredBudget) === idSet($all), 'invalid budget filters do not hide the board');
check(isset($byProgram[0]['budget_program'], $byProgram[0]['budget_year']), 'board rows include budget columns');

$summary = MaterialBoard::summary($pdo);
check($summary['total'] === 6, 'summary total is consumable+part only');
check($summary['by_type']['consumable'] === 4, 'summary consumable count');
check($summary['by_type']['part'] === 2, 'summary part count');
check($summary['low_stock'] === 3, 'summary low_stock follows qty < min_stock');

check(snapshot($pdo) === $before, 'list/filter must not write catalog, stock, assets, or logs');

$emptyDb = memoryDb();
check(MaterialBoard::list($emptyDb) === [], 'empty board is empty list');
check(MaterialBoard::summary($emptyDb) === ['total' => 0, 'low_stock' => 0, 'by_type' => ['consumable' => 0, 'part' => 0]], 'empty summary is zeros');

$boardSrc = (string) file_get_contents($root . '/app/MaterialBoard.php');
check(str_contains($boardSrc, "type IN ('consumable','part')"), 'board query is consumable/part stock, not full catalog browse');
check(!str_contains($boardSrc, "LIKE ?"), 'board filters are exact type/room/low-stock/budget, not search');
check(str_contains($boardSrc, 'Budget::queryFilters') && str_contains($boardSrc, 'Budget::filterSql'), 'board uses #32 budget filter hooks');
check(str_contains($boardSrc, 'Do not invent a new catalog type'), 'type extension stays documented against inventing a type');
check(MaterialBoard::MATERIAL_TYPES === ['consumable', 'part'], 'MATERIAL_TYPES stays existing consumable/part');

$ctl = (string) file_get_contents($root . '/app/Controllers/MaterialController.php');
check(str_contains($ctl, 'function index'), 'material controller has board index');
check(str_contains($ctl, 'MaterialBoard::list'), 'board uses MaterialBoard query');
check(str_contains($ctl, 'Auth::requireLogin()'), 'board requires login');
check(!preg_match('/function index\(\): void\s*\{[^}]*canWrite/', $ctl), 'board browse is not write-gated');
check(!str_contains($ctl, 'Csrf::'), 'read-only board has no CSRF write');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'materials' => [MaterialController::class, 'index']"), 'router registers materials board');

$tpl = (string) file_get_contents($root . '/templates/materials/index.php');
check(str_contains($tpl, '<h1>실험실습재료 현황</h1>'), 'board title is 실험실습재료, not 품목 목록');
check(str_contains($tpl, 'name="type"') && str_contains($tpl, 'name="room"') && str_contains($tpl, 'name="low_stock"'), 'board form has type/room/low-stock');
check(str_contains($tpl, 'name="budget_program"') && str_contains($tpl, 'name="budget_year"'), 'board form has budget filters');
check(str_contains($tpl, 'Budget::format'), 'board displays budget on rows');
check(str_contains($tpl, 'badge overdue'), 'low-stock rows show 부족 badge');
check(str_contains($tpl, 'is-low-stock'), 'low-stock rows have highlight class');
check(str_contains($tpl, 'method="get"'), 'board filters are GET/SSR');
check(!preg_match('/name=["\']q["\']/', $tpl), 'board does not require a search box');
check(!str_contains($tpl, 'Csrf::field()'), 'board is a GET read; no CSRF write');
check(str_contains($tpl, "App::url('items')"), 'board points at #29 catalog for equipment/fixture');

$more = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($more, "App::url('materials')") && str_contains($more, '실험실습재료 현황'), 'more menu links to the board');
check(substr_count($more, "App::url('materials')") === 1, 'more must not duplicate the materials board link');

$home = (string) file_get_contents($root . '/templates/home/index.php');
check(str_contains($home, "App::url('materials')"), 'home links to the board');

$layout = (string) file_get_contents($root . '/templates/layouts/app.php');
check(str_contains($layout, "\$current === 'materials'"), 'materials board keeps the 더보기 tab active');

echo "PASS: {$checks} material-board checks\n";
