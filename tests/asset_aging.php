<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\AssetAging;
use Inni\AssetLife;

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

function ids(array $rows): array
{
    return array_values(array_map(static fn (array $row): string => (string) $row['id'], $rows));
}

function bands(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['id']] = (string) ($row['life_band'] ?? '');
    }
    return $out;
}

$on = '2026-09-15';
check(AssetLife::expiryDate('2020-03-01', 5) === '2025-03-01', 'expiry is purchase + years');
check(AssetLife::band('2020-03-01', 5, $on) === AssetLife::BAND_OVER, 'past expiry is 초과');
check(AssetLife::band('2021-09-14', 5, $on) === AssetLife::BAND_OVER, 'yesterday expiry is 초과');
check(AssetLife::band('2021-09-15', 5, $on) === AssetLife::BAND_DUE, 'today expiry is 임박');
check(AssetLife::band('2022-03-01', 5, $on) === AssetLife::BAND_DUE, 'within 365 days is 임박');
check(AssetLife::horizonDate($on) === '2027-09-15', 'horizon is today + 365 days');
check(AssetLife::band('2022-09-15', 5, $on) === AssetLife::BAND_DUE, 'horizon day is 임박');
check(AssetLife::band('2022-09-16', 5, $on) === AssetLife::BAND_OK, 'day after horizon is 잔여');
check(AssetLife::band('2024-01-01', 5, $on) === AssetLife::BAND_OK, 'far expiry is 잔여');
check(AssetLife::band('2020-03-01', null, $on) === null, 'missing years has no band');
check(AssetLife::band(null, 5, $on) === null, 'missing date has no band');
check(AssetLife::daysUntilExpiry('2021-09-15', 5, $on) === 0, 'today expiry is 0 days');
check(AssetLife::daysUntilExpiry('2021-09-14', 5, $on) === -1, 'yesterday expiry is -1');
check(AssetLife::daysUntilExpiry('2021-09-25', 5, $on) === 10, 'future expiry days');
check(AssetLife::formatRemaining(-12) === '초과 12일', 'remaining over');
check(AssetLife::formatRemaining(0) === '오늘 만료', 'remaining today');
check(AssetLife::formatRemaining(8) === '잔여 8일', 'remaining due');
check(AssetLife::bandLabel(AssetLife::BAND_DUE) === '임박' && AssetLife::bandLabel(AssetLife::BAND_OVER) === '초과', 'band labels');

$emptyBand = ['band' => null];
check(AssetAging::filtersFromRequest([]) === $emptyBand, 'empty query is 임박+초과');
check(AssetAging::filtersFromRequest(['band' => 'soon', 'q' => '스코프']) === $emptyBand, 'unknown band and search q are ignored');
check(AssetAging::filtersFromRequest(['band' => 'due']) === ['band' => 'due'], 'due filter is kept');
check(AssetAging::filtersFromRequest(['band' => 'over']) === ['band' => 'over'], 'over filter is kept');
check(AssetAging::query(['band' => 'due']) === ['band' => 'due'], 'query allowlist keeps due');
check(AssetAging::query(['band' => 'broken']) === [], 'query allowlist drops unknown band');

$pdo = memoryDb();
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('elec','전자실습실','room','LOC:elec','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
$ins = $pdo->prepare(
    'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,purchase_date,useful_life_years,created_at,updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?)'
);
$ins->execute(['ast-over-old', 'eq', '스코프 초과오래', '전장-1', 'available', 'elec', 'AST:1', '2016-01-01', 5, 't', 't']);
$ins->execute(['ast-over-new', 'eq', '스코프 초과최근', '전장-2', 'retired', 'elec', 'AST:2', '2021-03-01', 5, 't', 't']);
$ins->execute(['ast-due-soon', 'eq', '스코프 임박', '전장-3', 'repair', 'elec', 'AST:3', '2022-01-01', 5, 't', 't']);
$ins->execute(['ast-due-today', 'eq', '스코프 오늘만료', '전장-4', 'available', 'elec', 'AST:4', '2021-09-15', 5, 't', 't']);
$ins->execute(['ast-ok', 'eq', '스코프 잔여', '전장-5', 'available', 'elec', 'AST:5', '2024-01-01', 5, 't', 't']);
$ins->execute(['ast-date-only', 'eq', '스코프 날짜만', '전장-6', 'available', 'elec', 'AST:6', '2020-01-01', null, 't', 't']);
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-empty','eq','스코프 빈값','전장-7','available','elec','AST:7','t','t')");

$watch = AssetAging::list($pdo, [], $on);
check(ids($watch) === ['ast-over-old', 'ast-over-new', 'ast-due-today', 'ast-due-soon'], 'default list is 초과 then 임박 by days');
check(bands($watch) === [
    'ast-over-old' => 'over',
    'ast-over-new' => 'over',
    'ast-due-today' => 'due',
    'ast-due-soon' => 'due',
], 'rows carry life_band');
check(($watch[0]['expiry_date'] ?? '') === '2021-01-01', 'row expiry uses purchase + years');
check(($watch[2]['days_until'] ?? null) === 0, 'today expiry days_until is 0');
check(!in_array('ast-ok', ids($watch), true), '잔여 is omitted from the watch list');
check(!in_array('ast-date-only', ids($watch), true) && !in_array('ast-empty', ids($watch), true), 'incomplete life is omitted');
check(in_array('ast-over-new', ids($watch), true), 'retired 초과 stays on the board');
check(in_array('ast-due-soon', ids($watch), true), 'repair 임박 stays on the board');

$due = AssetAging::list($pdo, ['band' => 'due'], $on);
check(ids($due) === ['ast-due-today', 'ast-due-soon'], 'band=due is 임박 only');
$over = AssetAging::list($pdo, ['band' => 'over'], $on);
check(ids($over) === ['ast-over-old', 'ast-over-new'], 'band=over is 초과 only');
$ignored = AssetAging::list($pdo, AssetAging::filtersFromRequest(['band' => 'expired']), $on);
check(ids($ignored) === ids($watch), 'unknown band fails open to 임박+초과');

$summary = AssetAging::summary($pdo, $on);
check($summary === ['due' => 2, 'over' => 2, 'watch' => 4], 'summary counts 임박/초과 only');

$none = memoryDb();
$none->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('elec','전자실습실','room','LOC:elec','t','t')");
$none->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
$none->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-empty','eq','빈값','전장-1','available','elec','AST:1','t','t')");
check(AssetAging::list($none, [], $on) === [], 'no dated assets means empty board');
check(AssetAging::summary($none, $on) === ['due' => 0, 'over' => 0, 'watch' => 0], 'empty summary');

$ctl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
check(str_contains($ctl, 'function aging'), 'asset controller has aging board');
check(str_contains($ctl, 'AssetAging::list'), 'aging uses AssetAging query');
check(str_contains($ctl, 'Auth::canWrite($user)'), 'aging is canWrite-gated');
check(str_contains($ctl, "View::render('assets/aging'"), 'aging renders assets/aging');
check(!preg_match('/function aging\(\): void\s*\{[^}]*Csrf::/', $ctl), 'aging is GET/read-only (no CSRF)');
check(!preg_match('/function aging\(\): void\s*\{[^}]*\$_POST/', $ctl), 'aging does not read POST');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'assets/aging' => [AssetController::class, 'aging']"), 'router registers assets/aging');

$tpl = (string) file_get_contents($root . '/templates/assets/aging.php');
check(str_contains($tpl, '<h1>연한·노후 기자재</h1>'), 'aging template title');
check(str_contains($tpl, 'name="band"') && str_contains($tpl, '임박만') && str_contains($tpl, '초과만'), 'aging form has 임박/초과 filter');
check(str_contains($tpl, 'method="get"'), 'aging filters are GET/SSR');
check(str_contains($tpl, 'expiry') || str_contains($tpl, '만료 예정'), 'aging shows expiry-ish date');
check(str_contains($tpl, 'AssetLife::format'), 'aging reuses life line');
check(!str_contains($tpl, 'Csrf::') && !str_contains($tpl, 'method="post"'), 'aging template has no POST');
check(!str_contains($tpl, '파기') || str_contains($tpl, '파기·보정은 없습니다'), 'aging does not invent dispose UI');

$assetsTpl = (string) file_get_contents($root . '/templates/assets/index.php');
check(str_contains($assetsTpl, "App::url('assets/aging')"), 'assets board links to aging');
check(str_contains($assetsTpl, 'Auth::canWrite'), 'assets aging link is canWrite-gated');

$more = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($more, '연한·노후 기자재') && str_contains($more, "App::url('assets/aging')"), 'more menu links aging');
check(str_contains($more, 'Auth::canWrite($user)'), 'more aging entry stays canWrite-gated');
check(substr_count($more, "App::url('assets/aging')") === 1, 'more must not duplicate the aging link');
check(substr_count($more, "App::url('assets')") === 1, 'more still has a single 기자재 현황 link');

$layout = (string) file_get_contents($root . '/templates/layouts/app.php');
check(str_contains($layout, "\$current === 'assets/aging'"), 'aging keeps the 더보기 tab active');
check(!str_contains($layout, "str_starts_with(\$current, 'assets/')"), 'asset detail still does not steal 더보기');

$inv = (string) file_get_contents($root . '/app/InventoryBudget.php');
check(str_contains($inv, 'Budget-program inventory'), 'aging does not remove #51 inventory report');
$invTpl = (string) file_get_contents($root . '/templates/inventory/report.php');
check(str_contains($invTpl, '<h1>사업예산 실사</h1>'), '#51 report template stays');

echo "PASS: {$checks} asset-aging checks\n";
