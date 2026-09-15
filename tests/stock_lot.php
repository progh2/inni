<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Catalog;
use Inni\Database;
use Inni\MaterialBoard;
use Inni\Stock;
use Inni\StockLot;

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

check(StockLot::parseLotCode(null) === null && StockLot::parseLotCode('  ') === null, 'Empty lot code is null');
check(StockLot::parseLotCode(' LOT-1 ') === 'LOT-1', 'Lot code is trimmed');
check(StockLot::parseDate(null, '유통기한') === null && StockLot::parseDate('  ', '유통기한') === null, 'Empty date is null');
check(StockLot::parseDate('2026-03-15', '유통기한') === '2026-03-15', 'ISO date parses');
check(StockLot::parseDate('2026.3.5', '입고일') === '2026-03-05', 'Dotted date parses');
check(StockLot::parseDate('2026/12/01', '입고일') === '2026-12-01', 'Slashed date parses');
check(
    StockLot::format('A-12', '2026-12-31', '2026-03-01') === '로트 A-12 · 유통기한 2026-12-31 · 입고 2026-03-01',
    'Format all parts'
);
check(StockLot::format(null, null, null) === '', 'Format empty');
check(StockLot::format('B-1', null, null) === '로트 B-1', 'Format lot only');
check(StockLot::format(null, '2027-01-01', null) === '유통기한 2027-01-01', 'Format expiry only');

reject(static fn () => StockLot::parseLotCode(str_repeat('가', 81)), 'Overlong lot code');
reject(static fn () => StockLot::parseLotCode(['x']), 'Non-string lot code');
reject(static fn () => StockLot::parseDate('2026-13-01', '유통기한'), 'Invalid month');
reject(static fn () => StockLot::parseDate('tomorrow', '입고일'), 'Free text date');
reject(static fn () => StockLot::parseDate('1899-01-01', '입고일'), 'Date below range');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('PRAGMA foreign_keys = ON');
$old->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id), name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec("CREATE TABLE loans (id TEXT PRIMARY KEY, kind TEXT NOT NULL, asset_id TEXT, status TEXT NOT NULL DEFAULT 'active')");
$old->exec(
    'CREATE TABLE stock_lots (
      id TEXT PRIMARY KEY,
      catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id),
      location_id TEXT NOT NULL REFERENCES locations(id),
      quantity REAL NOT NULL DEFAULT 0,
      updated_at TEXT NOT NULL,
      UNIQUE(catalog_item_id, location_id)
    )'
);
$old->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','t','t')");
$old->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('old','구납땜','consumable','CAT:old','t','t')");
$old->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-old','old','room',8,'t')");
Database::migrate($old);
$lotCols = array_column($old->query('PRAGMA table_info(stock_lots)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('lot_code', $lotCols, true), 'Migrate adds lot_code');
check(in_array('expires_at', $lotCols, true), 'Migrate adds expires_at');
check(in_array('received_at', $lotCols, true), 'Migrate adds received_at');
$legacy = $old->query("SELECT lot_code, expires_at, received_at, quantity FROM stock_lots WHERE id='lot-old'")->fetch(PDO::FETCH_ASSOC);
check($legacy['lot_code'] === null && $legacy['expires_at'] === null && $legacy['received_at'] === null, 'Old rows stay empty after migrate');
check((float) $legacy['quantity'] === 8.0, 'Migrate does not change quantity');
$uniq = $old->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='stock_lots'")->fetchColumn();
check(is_string($uniq) && str_contains($uniq, 'UNIQUE'), 'Location uniqueness stays on rebuilt-or-legacy table');
Database::migrate($old);
check(true, 'Migrate is idempotent');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','now','now')");
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('store','창고','room','ROOM:store','now','now')");
$pdo->prepare('INSERT INTO catalog_items(id,name,type,unit,min_stock,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
    ->execute(['ci-1', '납땜', 'consumable', 'm', 5, 'CAT:ci-1', 'now', 'now']);
$pdo->prepare('INSERT INTO catalog_items(id,name,type,unit,min_stock,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
    ->execute(['ci-eq', '멀티미터', 'equipment', 'ea', null, 'CAT:ci-eq', 'now', 'now']);
StockLot::insert($pdo, 'lot-1', 'ci-1', 'room', 3, 'LOT-A', '2026-12-31', '2026-03-01', 'now');

$schemaLot = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='stock_lots'")->fetchColumn();
check(is_string($schemaLot) && str_contains($schemaLot, 'lot_code') && str_contains($schemaLot, 'UNIQUE'), 'Fresh schema has lot columns and unique location');

$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];
$manager = ['id' => 'manager', 'display_name' => '매니저', 'role' => 'manager', 'status' => 'active'];
$teacher = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];

$row = $pdo->query("SELECT * FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check($row['lot_code'] === 'LOT-A' && $row['expires_at'] === '2026-12-31' && $row['received_at'] === '2026-03-01', 'Insert stores lot attributes');
check((float) $row['quantity'] === 3.0, 'Insert quantity unchanged by attributes');

$listed = Catalog::list($pdo);
$byId = [];
foreach ($listed as $item) {
    $byId[$item['id']] = $item;
}
check((int) $byId['ci-1']['low_stock'] === 1, 'Shortage badge still uses qty < min_stock with lot fields');
check(Catalog::rowIsLowStock($byId['ci-1']) === true, 'rowIsLowStock ignores lot attributes');
$board = MaterialBoard::list($pdo);
check((int) $board[0]['low_stock'] === 1, 'Materials board shortage badge still works');

StockLot::update($pdo, $owner, 'ci-1', 'lot-1', 'LOT-B', '2027-01-15', '2026-04-01');
$row = $pdo->query("SELECT * FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check($row['lot_code'] === 'LOT-B' && $row['expires_at'] === '2027-01-15' && $row['received_at'] === '2026-04-01', 'Owner can edit lot attributes');
check((float) $row['quantity'] === 3.0, 'Lot edit does not change quantity');
$log = $pdo->query("SELECT * FROM activity_logs WHERE action='update' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$meta = json_decode((string) $log['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($log['entity_id'] === 'ci-1' && $meta['before']['lot_code'] === 'LOT-A' && $meta['after']['lot_code'] === 'LOT-B', 'Lot edit audit missing');

StockLot::update($pdo, $manager, 'ci-1', 'lot-1', '', '', '');
$row = $pdo->query("SELECT lot_code, expires_at, received_at, quantity FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check($row['lot_code'] === null && $row['expires_at'] === null && $row['received_at'] === null, 'Empty values clear lot attributes');
check((float) $row['quantity'] === 3.0, 'Clearing attributes leaves quantity');

function snapshotLots(PDO $pdo): array
{
    return [
        $pdo->query('SELECT * FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_id FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

$before = snapshotLots($pdo);
reject(static fn () => StockLot::update($pdo, $teacher, 'ci-1', 'lot-1', 'NO', null, null), 'Teacher lot edit');
check(snapshotLots($pdo) === $before, 'Rejected teacher edit changed nothing');
reject(static fn () => StockLot::update($pdo, array_replace($owner, ['status' => 'disabled']), 'ci-1', 'lot-1', 'NO', null, null), 'Disabled owner lot edit');
reject(static fn () => StockLot::update($pdo, $owner, 'missing', 'lot-1', 'NO', null, null), 'Missing item lot edit');
reject(static fn () => StockLot::update($pdo, $owner, 'ci-1', 'missing', 'NO', null, null), 'Missing lot edit');
reject(static fn () => StockLot::update($pdo, $owner, 'ci-1', 'lot-1', 'NO', 'yesterday', null), 'Bad expiry on edit');

Stock::restock($pdo, $owner, 'ci-1', 'room', '2', '보충', 'LOT-C', '2028-06-01', '2026-09-01');
$row = $pdo->query("SELECT * FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check((float) $row['quantity'] === 5.0, 'Restock still adds quantity');
check($row['lot_code'] === 'LOT-C' && $row['expires_at'] === '2028-06-01' && $row['received_at'] === '2026-09-01', 'Restock can set lot attributes');

Stock::restock($pdo, $owner, 'ci-1', 'room', '1', '수량만');
$row = $pdo->query("SELECT * FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check((float) $row['quantity'] === 6.0 && $row['lot_code'] === 'LOT-C', 'Empty restock lot fields leave attributes');

Stock::restock($pdo, $manager, 'ci-1', 'store', '4', '새 위치', 'LOT-NEW', '2029-01-01', '2026-09-10');
$newLot = $pdo->query("SELECT * FROM stock_lots WHERE catalog_item_id='ci-1' AND location_id='store'")->fetch(PDO::FETCH_ASSOC);
check($newLot && (float) $newLot['quantity'] === 4.0 && $newLot['lot_code'] === 'LOT-NEW', 'New location restock stores lot attributes');
check($newLot['expires_at'] === '2029-01-01' && $newLot['received_at'] === '2026-09-10', 'New location restock dates');

$afterRestock = Catalog::list($pdo, ['low_stock' => true]);
check($afterRestock === [], 'qty 10 >= min 5 is not shortage after restock');
$all = Catalog::list($pdo);
foreach ($all as $item) {
    if ($item['id'] === 'ci-1') {
        check((int) $item['low_stock'] === 0, 'Shortage badge clears when qty reaches min');
    }
}

Stock::issue($pdo, $teacher, 'ci-1', 'lot-1', '6', '전량');
$lowAgain = Catalog::list($pdo);
$lowById = [];
foreach ($lowAgain as $item) {
    $lowById[$item['id']] = $item;
}
check((int) $lowById['ci-1']['low_stock'] === 1, 'Shortage badge returns after issue below min');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-1'")->fetchColumn() === 0.0, 'Issue still subtracts with lot attributes present');

$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/templates/items/index.php');
$boardSrc = (string) file_get_contents(dirname(__DIR__) . '/templates/materials/index.php');
$newSrc = (string) file_get_contents(dirname(__DIR__) . '/templates/items/new.php');
$editSrc = (string) file_get_contents(dirname(__DIR__) . '/templates/items/edit.php');
$showSrc = (string) file_get_contents(dirname(__DIR__) . '/templates/items/show.php');
$lotFieldsSrc = (string) file_get_contents(dirname(__DIR__) . '/templates/partials/lot_fields.php');
$alertSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Alert.php');
check(str_contains($indexSrc, '부족') && str_contains($indexSrc, 'low_stock'), 'Catalog list keeps shortage badge');
check(str_contains($boardSrc, '부족') && str_contains($boardSrc, 'low_stock'), 'Materials board keeps shortage badge');
check(str_contains($newSrc, 'name="unit"') && str_contains($newSrc, 'name="min_stock"'), 'Register UI has unit and min stock');
check(str_contains($newSrc, 'partials/lot_fields.php'), 'Register UI includes optional lot fields');
check(str_contains($lotFieldsSrc, 'name="lot_code"') && str_contains($lotFieldsSrc, 'name="expires_at"') && str_contains($lotFieldsSrc, 'name="received_at"'), 'Lot field partial has code/expiry/received');
check(str_contains($editSrc, 'name="unit"') && str_contains($editSrc, 'name="min_stock"'), 'Edit UI has unit and min stock');
check(str_contains($showSrc, 'items/lot') && str_contains($showSrc, 'Csrf::field()'), 'Item show posts lot edit with CSRF');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/app/Router.php'), "'items/lot'"), 'items/lot write route is registered');
check(str_contains($alertSrc, 'qty < c.min_stock'), 'Alert shortage condition unchanged');

echo "PASS: {$checks} stock lot checks\n";
