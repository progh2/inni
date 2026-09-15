<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\AssetLife;
use Inni\AssetLifeBoard;

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

$today = '2026-09-15';

check(AssetLife::expiryDate('2021-09-15', 5) === '2026-09-15', 'expiry on today');
check(AssetLife::bucket('2021-09-15', 5, $today) === AssetLife::BUCKET_IMMINENT, 'expiry today is 임박');
check(AssetLife::bucket('2021-09-16', 5, $today) === AssetLife::BUCKET_IMMINENT, 'expiry tomorrow is 임박');
check(AssetLife::bucket('2021-09-14', 5, $today) === AssetLife::BUCKET_EXCEEDED, 'expiry yesterday is 초과');
check(AssetLife::bucket('2018-01-01', 5, $today) === AssetLife::BUCKET_EXCEEDED, 'old expiry is 초과');
check(AssetLife::bucket('2022-09-15', 5, $today) === AssetLife::BUCKET_IMMINENT, 'expiry in 365 days is 임박');
check(AssetLife::expiryDate('2022-09-15', 5) === '2027-09-15', '365-day horizon expiry');
check(AssetLife::bucket('2022-09-16', 5, $today) === null, 'expiry in 366 days is healthy');
check(AssetLife::bucket(null, 5, $today) === null, 'no date is not aging');
check(AssetLife::bucket('2020-01-01', null, $today) === null, 'no years is not aging');
check(AssetLife::daysUntilExpiry('2021-09-15', 5, $today) === 0, 'days today is 0');
check(AssetLife::daysUntilExpiry('2021-09-14', 5, $today) === -1, 'days yesterday is -1');
check(AssetLife::daysUntilExpiry('2021-09-16', 5, $today) === 1, 'days tomorrow is 1');
check(AssetLife::remainingLabel(-12) === '초과 12일', 'remaining exceeded');
check(AssetLife::remainingLabel(0) === '오늘 만료', 'remaining today');
check(AssetLife::remainingLabel(30) === '잔여 30일', 'remaining future');
check(AssetLife::bucketLabel(AssetLife::BUCKET_IMMINENT) === '임박', 'label 임박');
check(AssetLife::bucketLabel(AssetLife::BUCKET_EXCEEDED) === '초과', 'label 초과');

check(AssetLifeBoard::normalizeLife('imminent') === 'imminent', 'normalize imminent');
check(AssetLifeBoard::normalizeLife('exceeded') === 'exceeded', 'normalize exceeded');
check(AssetLifeBoard::normalizeLife('임박') === 'imminent', 'normalize Korean 임박');
check(AssetLifeBoard::normalizeLife('초과') === 'exceeded', 'normalize Korean 초과');
check(AssetLifeBoard::normalizeLife('broken') === null, 'unknown life is ignored');
check(AssetLifeBoard::filtersFromRequest(['life' => '임박']) === ['life' => 'imminent'], 'request parser keeps 임박');
check(AssetLifeBoard::filtersFromRequest(['life' => 'not-a-filter', 'q' => '스코프']) === ['life' => null], 'request parser ignores search and invalid life');

$pdo = memoryDb();
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','장비','equipment','CAT:eq','t','t')");

$insert = $pdo->prepare(
    'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,purchase_date,useful_life_years,created_at,updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?)'
);
$insert->execute(['ast-over', 'eq', '용접기 초과', '용접-1', 'available', 'room', 'AST:over', '2018-01-01', 5, 't', 't']);
$insert->execute(['ast-today', 'eq', '스코프 오늘', '전장-1', 'available', 'room', 'AST:today', '2021-09-15', 5, 't', 't']);
$insert->execute(['ast-soon', 'eq', '드릴 임박', '공통-1', 'repair', 'room', 'AST:soon', '2022-09-15', 5, 't', 't']);
$insert->execute(['ast-far', 'eq', 'CNC 여유', '기공-1', 'available', 'room', 'AST:far', '2024-09-15', 5, 't', 't']);
$insert->execute(['ast-nodate', 'eq', '날짜없음', '공-1', 'available', 'room', 'AST:nodate', null, 5, 't', 't']);
$insert->execute(['ast-noyear', 'eq', '연한없음', '공-2', 'available', 'room', 'AST:noyear', '2018-01-01', null, 't', 't']);

$all = AssetLifeBoard::list($pdo, [], $today);
check(ids($all) === ['ast-over', 'ast-today', 'ast-soon'], 'default list is 초과 then 임박, excludes healthy/incomplete');
check(($all[0]['life_bucket'] ?? '') === 'exceeded', 'first row is 초과');
check(($all[1]['life_bucket'] ?? '') === 'imminent' && ($all[2]['life_bucket'] ?? '') === 'imminent', 'later rows are 임박');
check(($all[0]['expiry_date'] ?? '') === '2023-01-01', 'list expiry uses #39 purchase+years');
check(($all[1]['remaining_label'] ?? '') === '오늘 만료', 'today remaining label');
check(isset($all[0]['purchase_date'], $all[0]['useful_life_years'], $all[0]['life_label']), 'rows reuse #39 life columns');

$imminent = AssetLifeBoard::list($pdo, ['life' => 'imminent'], $today);
check(ids($imminent) === ['ast-today', 'ast-soon'], 'life=imminent excludes 초과 and healthy');
check(!in_array('ast-over', ids($imminent), true), '임박 filter hides 초과');

$exceeded = AssetLifeBoard::list($pdo, ['life' => 'exceeded'], $today);
check(ids($exceeded) === ['ast-over'], 'life=exceeded is past useful life only');
check(($exceeded[0]['days_until_expiry'] ?? null) < 0, '초과 days are negative');

$ko = AssetLifeBoard::list($pdo, AssetLifeBoard::filtersFromRequest(['life' => '임박']), $today);
check(ids($ko) === ids($imminent), 'Korean 임박 filter matches imminent');

$ignored = AssetLifeBoard::list($pdo, AssetLifeBoard::filtersFromRequest(['life' => 'broken']), $today);
check(ids($ignored) === ids($all), 'invalid life filter fails open to all aging');

$summary = AssetLifeBoard::summary($pdo, $today);
check($summary === ['total' => 3, 'imminent' => 2, 'exceeded' => 1], 'summary counts aging only');

$empty = memoryDb();
$empty->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','t','t')");
check(AssetLifeBoard::list($empty, [], $today) === [], 'empty board lists nothing');
check(AssetLifeBoard::summary($empty, $today) === ['total' => 0, 'imminent' => 0, 'exceeded' => 0], 'empty summary is zero');

$src = (string) file_get_contents($root . '/app/AssetLifeBoard.php');
check(str_contains($src, 'purchase_date') && str_contains($src, 'useful_life_years'), 'board reads #39 fields');
check(!str_contains($src, 'pps_useful_life') && !str_contains($src, 'PpsUsefulLife'), 'board does not re-query PPS seed');
check(!str_contains($src, 'destroy') && !str_contains($src, 'adjust') && !str_contains($src, 'repair_cost'), 'board has no #53/#54 writes');

$ctl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
check(str_contains($ctl, 'function aging'), 'asset controller has aging board');
check(str_contains($ctl, 'AssetLifeBoard::list'), 'aging uses AssetLifeBoard query');
check(str_contains($ctl, "View::render('assets/aging'"), 'aging renders assets/aging');
check(preg_match('/function aging\(\): void\s*\{[^}]*Auth::requireLogin\(\)/s', $ctl) === 1, 'aging requires login');
check(!preg_match('/function aging\(\): void\s*\{[^}]*canWrite/', $ctl), 'aging browse is not write-gated');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'assets/aging' => [AssetController::class, 'aging']"), 'router registers aging board');
check(!str_contains($router, "'assets/destroy'") && !str_contains($router, "'assets/adjust'"), 'no #53 destroy/adjust routes');

$tpl = (string) file_get_contents($root . '/templates/assets/aging.php');
check(str_contains($tpl, '<h1>연한·노후 기자재</h1>'), 'aging title');
check(str_contains($tpl, 'name="life"'), 'aging form has life filter');
check(str_contains($tpl, '임박') && str_contains($tpl, '초과'), 'aging UI names 임박/초과');
check(str_contains($tpl, 'method="get"'), 'aging filters are GET/SSR');
check(str_contains($tpl, '조달청 제안'), 'aging mentions #40 PPS suggest');
check(!preg_match('/name=["\'](adjust|destroy|repair_cost)/', $tpl), 'aging form has no #53/#54 fields');
check(!str_contains($tpl, 'assets/destroy') && !str_contains($tpl, 'assets/adjust'), 'aging has no destroy/adjust actions');

$more = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($more, '연한·노후 기자재') && str_contains($more, "App::url('assets/aging')"), 'more menu links to aging');

$assetsTpl = (string) file_get_contents($root . '/templates/assets/index.php');
check(str_contains($assetsTpl, "App::url('assets/aging')"), 'status board links to aging');

$layout = (string) file_get_contents($root . '/templates/layouts/app.php');
check(str_contains($layout, "\$current === 'assets/aging'"), 'aging keeps 더보기 tab active');

echo "PASS: {$checks} asset-life-board checks\n";
