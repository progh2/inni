<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Asset;
use Inni\AssetLife;
use Inni\CatalogCsv;
use Inni\Database;

$checks = 0;

function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function reject(callable $fn, string $message): void
{
    try {
        $fn();
        throw new RuntimeException($message . ' was accepted');
    } catch (InvalidArgumentException) {
        check(true, $message);
    }
}

check(AssetLife::parsePurchaseDate(null) === null && AssetLife::parsePurchaseDate('  ') === null, 'Empty date is null');
check(AssetLife::parsePurchaseDate('2024-03-15') === '2024-03-15', 'ISO date parses');
check(AssetLife::parsePurchaseDate('2024.3.5') === '2024-03-05', 'Dotted date parses');
check(AssetLife::parsePurchaseDate('2024/12/01') === '2024-12-01', 'Slashed date parses');
check(AssetLife::parseUsefulLifeYears(null) === null && AssetLife::parseUsefulLifeYears('') === null, 'Empty years is null');
check(AssetLife::parseUsefulLifeYears('5') === 5 && AssetLife::parseUsefulLifeYears(8) === 8, 'Years parse');
check(AssetLife::expiryDate('2020-03-01', 5) === '2025-03-01', 'Expiry is purchase + years');
check(AssetLife::expiryDate('2020-03-01', null) === null, 'Expiry needs years');
check(AssetLife::expiryDate(null, 5) === null, 'Expiry needs date');
check(AssetLife::format('2020-03-01', 5) === '도입 2020-03-01 · 5년 · 만료 예정 2025-03-01', 'Format all parts');
check(AssetLife::format(null, null) === '', 'Format empty');
check(AssetLife::format('2020-03-01', null) === '도입 2020-03-01', 'Format date only');
check(AssetLife::format(null, 7) === '7년', 'Format years only');

reject(static fn () => AssetLife::parsePurchaseDate('2024-13-01'), 'Invalid month');
reject(static fn () => AssetLife::parsePurchaseDate('yesterday'), 'Free text date');
reject(static fn () => AssetLife::parsePurchaseDate('1899-01-01'), 'Date below range');
reject(static fn () => AssetLife::parseUsefulLifeYears('0'), 'Zero years');
reject(static fn () => AssetLife::parseUsefulLifeYears('101'), 'Years above range');
reject(static fn () => AssetLife::parseUsefulLifeYears('five'), 'Non-numeric years');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('PRAGMA foreign_keys = ON');
$old->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id), name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec("CREATE TABLE loans (id TEXT PRIMARY KEY, kind TEXT NOT NULL, asset_id TEXT, status TEXT NOT NULL DEFAULT 'active')");
$old->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('old','구품목','equipment','CAT:old','t','t')");
$old->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-old','old','구장비','M-1','available','room','AST:old','t','t')");
Database::migrate($old);
$astCols = array_column($old->query('PRAGMA table_info(assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('useful_life_years', $astCols, true), 'Migrate adds useful_life_years');
$legacy = $old->query("SELECT useful_life_years FROM assets WHERE id='ast-old'")->fetch(PDO::FETCH_ASSOC);
check($legacy['useful_life_years'] === null, 'Old rows stay empty after migrate');
Database::migrate($old);
check(true, 'Migrate is idempotent');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','now','now')");
$pdo->prepare('INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?)')
    ->execute(['ci-eq', '멀티미터', 'equipment', 'CAT:ci-eq', 'now', 'now']);
$pdo->prepare('INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute(['ast-1', 'ci-eq', '멀티미터', '전장-1', 'available', 'room', 'AST:1', 'now', 'now']);

$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];
$teacher = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];

Asset::updateLife($pdo, $owner, 'ast-1', '2021-04-01', '6');
$asset = $pdo->query("SELECT purchase_date, useful_life_years FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check($asset['purchase_date'] === '2021-04-01' && (int) $asset['useful_life_years'] === 6, 'Asset save life');
check(AssetLife::expiryDate($asset['purchase_date'], $asset['useful_life_years']) === '2027-04-01', 'Saved row expiry');

Asset::updateLife($pdo, $owner, 'ast-1', '', '');
$asset = $pdo->query("SELECT purchase_date, useful_life_years FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check($asset['purchase_date'] === null && $asset['useful_life_years'] === null, 'Asset clear life');

Asset::updateLife($pdo, $owner, 'ast-1', '2022-09-12', '');
$asset = $pdo->query("SELECT purchase_date, useful_life_years FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check($asset['purchase_date'] === '2022-09-12' && $asset['useful_life_years'] === null, 'Date only save');

reject(static fn () => Asset::updateLife($pdo, $owner, 'ast-1', '2022-99-01', '5'), 'Invalid date does not write');
$afterBad = $pdo->query("SELECT purchase_date, useful_life_years FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check($afterBad['purchase_date'] === '2022-09-12' && $afterBad['useful_life_years'] === null, 'Invalid date leaves previous values');

reject(static fn () => Asset::updateLife($pdo, $teacher, 'ast-1', '2020-01-01', 4), 'Teacher asset life');

Asset::updateLife($pdo, $owner, 'ast-1', '2019-06-01', 8);
$exported = CatalogCsv::export($pdo);
check(str_contains($exported['csv'], '도입일') && str_contains($exported['csv'], '내용연한'), 'Export headers include life');
check(str_contains($exported['csv'], '2019-06-01') && str_contains($exported['csv'], '8'), 'Export includes life values');

$round = CatalogCsv::import($pdo, $owner, $exported['csv'], 'round-life.csv');
check($round['created'] === 0 && $round['updated'] === 1 && $round['skipped'] === 0, 'Life export re-import updates');
$again = $pdo->query("SELECT purchase_date, useful_life_years FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check($again['purchase_date'] === '2019-06-01' && (int) $again['useful_life_years'] === 8, 'Round-trip keeps life');

$createCsv = CatalogCsv::encode([
    CatalogCsv::headerRow(),
    ['', '충전드릴', '장비', '', '', 'ea', '', '', '', '0', '실습실', '1', '드릴-1', '', '', '2023-02-10', '5'],
    ['', '빈수명', '장비', '', '', 'ea', '', '', '', '0', '실습실', '1', '드릴-2', '', '', '', ''],
]);
$created = CatalogCsv::import($pdo, $owner, $createCsv, 'life-new.csv');
check($created['created'] === 2 && $created['skipped'] === 0, 'CSV create with life: ' . json_encode($created));
$drill = $pdo->query("SELECT a.purchase_date, a.useful_life_years FROM assets a JOIN catalog_items c ON c.id=a.catalog_item_id WHERE c.name='충전드릴'")->fetch(PDO::FETCH_ASSOC);
check(is_array($drill) && $drill['purchase_date'] === '2023-02-10' && (int) $drill['useful_life_years'] === 5, 'CSV create stores life');
$empty = $pdo->query("SELECT a.purchase_date, a.useful_life_years FROM assets a JOIN catalog_items c ON c.id=a.catalog_item_id WHERE c.name='빈수명'")->fetch(PDO::FETCH_ASSOC);
check(is_array($empty) && $empty['purchase_date'] === null && $empty['useful_life_years'] === null, 'CSV create allows empty life');

$koCsv = CatalogCsv::encode([
    ['품명', '유형', '위치', '수량', '도입날짜', '내용연한(년)'],
    ['용접기', '장비', '실습실', '1', '2018/05/20', '10'],
]);
$fromKo = CatalogCsv::import($pdo, $owner, $koCsv, 'ko-life.csv');
check($fromKo['created'] === 1, 'Korean life headers import');
$weld = $pdo->query("SELECT a.purchase_date, a.useful_life_years FROM assets a JOIN catalog_items c ON c.id=a.catalog_item_id WHERE c.name='용접기'")->fetch(PDO::FETCH_ASSOC);
check(is_array($weld) && $weld['purchase_date'] === '2018-05-20' && (int) $weld['useful_life_years'] === 10, 'Korean date alias maps');

$root = dirname(__DIR__);
$newTpl = (string) file_get_contents($root . '/templates/items/new.php');
check(str_contains($newTpl, '도입일') && str_contains($newTpl, 'name="purchase_date"'), 'Create form labels purchase_date as 도입일');
check(str_contains($newTpl, '내용연한(년)') && str_contains($newTpl, 'name="useful_life_years"'), 'Create form has 내용연한');
$showTpl = (string) file_get_contents($root . '/templates/assets/show.php');
check(str_contains($showTpl, '도입일') && !str_contains($showTpl, '구매일'), 'Detail labels 도입일 not 구매일');
check(str_contains($showTpl, '만료 예정일') && str_contains($showTpl, 'AssetLife::expiryDate'), 'Detail shows expiry');
$boardTpl = (string) file_get_contents($root . '/templates/assets/index.php');
check(str_contains($boardTpl, 'AssetLife::format'), 'Board shows life line');

echo "PASS: {$checks} asset-life checks\n";
