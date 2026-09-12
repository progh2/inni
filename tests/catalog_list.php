<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Catalog;

$root = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:list','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('store','창고','room','ROOM:store','t','t')");

$seed = [
    ['eq', '오실로스코프', 'equipment', 'ea', null],
    ['chair', '실습 의자', 'fixture', 'ea', 10],
    ['solder', '납땜 실납', 'consumable', 'm', 5],
    ['wire', '전선', 'consumable', 'm', 20],
    ['tape', '절연테이프', 'consumable', 'ea', null],
    ['res', '저항 키트', 'part', 'ea', 3],
    ['ok-part', '베어링', 'part', 'ea', 1],
];
$ins = $pdo->prepare(
    'INSERT INTO catalog_items(id,name,type,unit,min_stock,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)'
);
foreach ($seed as $row) {
    $ins->execute([$row[0], $row[1], $row[2], $row[3], $row[4], 'CAT:' . $row[0], 't', 't']);
}

$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-1','eq','스코프 #1','전장-1','available','room','AST:1','t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-2','eq','스코프 #2','전장-2','on_loan','room','AST:2','t','t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-chair','chair','room',40,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-solder-a','solder','room',2,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-solder-b','solder','store',1,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-wire','wire','room',20,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-tape','tape','room',0,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-res','res','room',1,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-ok','ok-part','room',4,'t')");
$pdo->exec("UPDATE catalog_items SET budget_program='방과후', budget_year=2026 WHERE id='solder'");
$pdo->exec("UPDATE catalog_items SET budget_program='방과후', budget_year=2025 WHERE id='wire'");
$pdo->exec("UPDATE catalog_items SET budget_program='특화교육', budget_year=2026 WHERE id='res'");

$checks = 0;

function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function ids(array $rows): array
{
    return array_values(array_map(static fn (array $row): string => (string) $row['id'], $rows));
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

$before = snapshot($pdo);

function idSet(array $rows): array
{
    $ids = ids($rows);
    sort($ids);
    return $ids;
}

function namesSorted(array $rows): bool
{
    $names = array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
    $sorted = $names;
    sort($sorted, SORT_STRING);
    return $names === $sorted;
}

$all = Catalog::list($pdo);
check(idSet($all) === ['chair', 'eq', 'ok-part', 'res', 'solder', 'tape', 'wire'], 'Browse without query should list every catalog item');
check(namesSorted($all) && count($all) === 7, 'Browse is ordered by name');

$byId = [];
foreach ($all as $row) {
    $byId[$row['id']] = $row;
}
check((int) $byId['eq']['asset_count'] === 2 && (float) $byId['eq']['stock_qty'] === 0.0, 'Equipment should count assets');
check((float) $byId['solder']['stock_qty'] === 3.0, 'Lots for the same item should be summed');
check((int) $byId['solder']['low_stock'] === 1, '3 < min 5 is low stock');
check((int) $byId['wire']['low_stock'] === 0, 'qty at min_stock is not low stock');
check((int) $byId['tape']['low_stock'] === 0, 'no min_stock is not low stock');
check((int) $byId['res']['low_stock'] === 1 && (int) $byId['ok-part']['low_stock'] === 0, 'part low-stock flag');
check((int) $byId['chair']['low_stock'] === 0, 'fixture is never low stock even with min_stock');
check((int) $byId['eq']['low_stock'] === 0, 'equipment is never low stock');

$equipment = Catalog::list($pdo, ['type' => 'equipment']);
check(idSet($equipment) === ['eq'] && namesSorted($equipment), 'Type filter equipment');
$consumable = Catalog::list($pdo, ['type' => 'consumable']);
check(idSet($consumable) === ['solder', 'tape', 'wire'] && namesSorted($consumable), 'Type filter consumable');
$part = Catalog::list($pdo, ['type' => 'part']);
check(idSet($part) === ['ok-part', 'res'] && namesSorted($part), 'Type filter part');
$fixture = Catalog::list($pdo, ['type' => 'fixture']);
check(idSet($fixture) === ['chair'], 'Type filter fixture');

$ignored = Catalog::list($pdo, ['type' => 'DROP TABLE catalog_items; --']);
check(ids($ignored) === ids($all), 'Invalid type must be ignored, not applied');
$blank = Catalog::list($pdo, ['type' => '  ', 'q' => '납땜']);
check(ids($blank) === ids($all), 'Search term q must not be required or applied on browse');

$low = Catalog::list($pdo, ['low_stock' => true]);
check(idSet($low) === ['res', 'solder'] && namesSorted($low), 'Low-stock filter uses qty < min_stock for consumable/part');
$lowOne = Catalog::list($pdo, ['low_stock' => '1']);
check(ids($lowOne) === ids($low), 'low_stock=1 from query string');
$lowOn = Catalog::list($pdo, Catalog::listFilters(['low_stock' => 'on', 'type' => '']));
check(ids($lowOn) === ids($low), 'listFilters accepts on');

$combo = Catalog::list($pdo, ['type' => 'consumable', 'low_stock' => 1]);
check(idSet($combo) === ['solder'], 'Type + low-stock together');
$comboEq = Catalog::list($pdo, ['type' => 'equipment', 'low_stock' => true]);
check($comboEq === [], 'Equipment + low-stock is empty');

$none = Catalog::list($pdo, Catalog::listFilters(['type' => 'unknown', 'low_stock' => '0']));
check(ids($none) === ids($all), 'Unknown type and low_stock=0 means full browse');

$emptyBudget = ['budget_program' => null, 'budget_year' => null];
check(Catalog::listFilters(['type' => 'part', 'low_stock' => 'yes']) === ['type' => 'part', 'low_stock' => true] + $emptyBudget, 'listFilters parses allowlisted type');
check(Catalog::listFilters(['type' => 1, 'low_stock' => 'false']) === ['type' => null, 'low_stock' => false] + $emptyBudget, 'non-string type is ignored');
check(Catalog::isTruthyFilter('0') === false && Catalog::isTruthyFilter('') === false, 'falsey low_stock values');

$byProgram = Catalog::list($pdo, ['budget_program' => '방과후']);
check(idSet($byProgram) === ['solder', 'wire'] && namesSorted($byProgram), 'Budget program exact filter');
$byYear = Catalog::list($pdo, ['budget_year' => '2026']);
check(idSet($byYear) === ['res', 'solder'] && namesSorted($byYear), 'Budget year exact filter');
$byBoth = Catalog::list($pdo, Catalog::listFilters(['budget_program' => '방과후', 'budget_year' => '2026']));
check(idSet($byBoth) === ['solder'], 'Budget program + year together');
$comboBudget = Catalog::list($pdo, ['type' => 'consumable', 'budget_program' => '방과후']);
check(idSet($comboBudget) === ['solder', 'wire'], 'Type + budget program');
$ignoredBudget = Catalog::list($pdo, Catalog::listFilters(['budget_year' => '26', 'budget_program' => str_repeat('가', 201)]));
check(ids($ignoredBudget) === ids($all), 'Invalid budget filters are ignored');
check(Catalog::listFilters(['budget_program' => ' 방과후 ', 'budget_year' => '2026']) === [
    'type' => null,
    'low_stock' => false,
    'budget_program' => '방과후',
    'budget_year' => 2026,
], 'listFilters trims budget program and parses YYYY');

$all = Catalog::list($pdo);
check(isset($all[0]['budget_program'], $all[0]['budget_year']) || array_key_exists('budget_program', $all[0]), 'Browse selects budget columns');
$solderRow = null;
foreach ($all as $row) {
    if ($row['id'] === 'solder') {
        $solderRow = $row;
        break;
    }
}
check(is_array($solderRow) && $solderRow['budget_program'] === '방과후' && (int) $solderRow['budget_year'] === 2026, 'Browse includes budget values');

$emptyDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$emptyDb->exec((string) file_get_contents($root . '/sql/schema.sql'));
check(Catalog::list($emptyDb) === [], 'Empty catalog browse is empty list');

check(snapshot($pdo) === $before, 'List/filter must not write catalog, stock, assets, or logs');

$item = (string) file_get_contents($root . '/app/Controllers/ItemController.php');
check(preg_match('/function index\(\): void\s*\{(.*?)\n    public function /s', $item, $indexMatch) === 1, 'ItemController::index exists');
check(str_contains($indexMatch[1], 'Auth::requireLogin()'), 'index() calls requireLogin');
check(!str_contains($indexMatch[1], 'canWrite'), 'teachers may browse; list is not canWrite-gated');
check(str_contains($indexMatch[1], 'Catalog::list'), 'index uses Catalog::list');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'items' => [ItemController::class, 'index']"), 'Router maps items browse');

$search = (string) file_get_contents($root . '/app/Controllers/SearchController.php');
check(str_contains($search, "if (\$q !== '')"), '찾기 still requires a search term');
$searchTpl = (string) file_get_contents($root . '/templates/search/index.php');
check(str_contains($searchTpl, '검색어를 입력하거나 스캔 탭을 사용하세요'), '찾기 empty state stays');
check(str_contains($searchTpl, "App::url('items')"), '찾기 points at browse without replacing search');

$listTpl = (string) file_get_contents($root . '/templates/items/index.php');
check(str_contains($listTpl, 'name="type"') && str_contains($listTpl, 'name="low_stock"'), 'Browse UI has type and low-stock filters');
check(str_contains($listTpl, 'name="budget_program"') && str_contains($listTpl, 'name="budget_year"'), 'Browse UI has budget filters');
check(str_contains($listTpl, 'Budget::format'), 'Browse UI displays budget on rows');
check(str_contains($listTpl, 'method="get"') && !str_contains($listTpl, 'Csrf::field()'), 'Browse is a GET read; no CSRF write');

$more = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($more, "App::url('items')") && str_contains($more, '품목 목록'), '더보기 exposes catalog browse to logged-in teachers');

echo "PASS: {$checks} catalog list checks\n";
