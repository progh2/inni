<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Alert;
use Inni\Catalog;
use Inni\Database;
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
check(StockLot::parseLotCode(' SN-1 ') === 'SN-1', 'Lot code trims');
check(StockLot::parseExpiresAt(null) === null && StockLot::parseExpiresAt('') === null, 'Empty expiry is null');
check(StockLot::parseExpiresAt('2027-03-31') === '2027-03-31', 'ISO expiry parses');
check(StockLot::parseExpiresAt('2027.3.5') === '2027-03-05', 'Dotted expiry parses');
check(StockLot::parseExpiresAt('2027/12/01') === '2027-12-01', 'Slashed expiry parses');
check(StockLot::isExpired(null) === false && StockLot::isExpired('') === false, 'Empty expiry is not expired');
check(StockLot::isExpired('2020-01-01', '2026-09-15') === true, 'Past expiry is expired');
check(StockLot::isExpired('2027-01-01', '2026-09-15') === false, 'Future expiry is not expired');
check(StockLot::format('SN-1', '2027-03-31') === '로트 SN-1 · 유통기한 2027-03-31', 'Format both lot fields');
check(StockLot::format(null, null) === '', 'Format empty');
check(Catalog::parseUnit('') === 'ea' && Catalog::parseUnit(' m ') === 'm', 'Unit defaults and trims');
check(Catalog::parseMinStock('') === null && Catalog::parseMinStock('3.5') === 3.5, 'Min stock parses');

reject(static fn () => StockLot::parseLotCode(str_repeat('가', 81)), 'Overlong lot code');
reject(static fn () => StockLot::parseLotCode("a\nb"), 'Newline lot code');
reject(static fn () => StockLot::parseExpiresAt('2027-13-01'), 'Invalid expiry month');
reject(static fn () => StockLot::parseExpiresAt('yesterday'), 'Free text expiry');
reject(static fn () => Catalog::parseMinStock('-1'), 'Negative min stock');
reject(static fn () => Catalog::parseUnit(["ea"]), 'Array unit');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('PRAGMA foreign_keys = ON');
$old->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id), name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec("CREATE TABLE loans (id TEXT PRIMARY KEY, kind TEXT NOT NULL, asset_id TEXT, status TEXT NOT NULL DEFAULT 'active')");
$old->exec('CREATE TABLE stock_lots (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL, location_id TEXT NOT NULL, quantity REAL NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)');
$old->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('old','구품목','consumable','CAT:old','t','t')");
$old->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실','room','ROOM:old','t','t')");
$old->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-old','old','room',4,'t')");
Database::migrate($old);
$lotCols = array_column($old->query('PRAGMA table_info(stock_lots)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('lot_code', $lotCols, true) && in_array('expires_at', $lotCols, true), 'Migrate adds lot columns');
$legacy = $old->query("SELECT lot_code, expires_at, quantity FROM stock_lots WHERE id='lot-old'")->fetch(PDO::FETCH_ASSOC);
check($legacy['lot_code'] === null && $legacy['expires_at'] === null && (float) $legacy['quantity'] === 4.0, 'Old lots stay empty after migrate');
Database::migrate($old);
check(true, 'Lot migrate is idempotent');

$noLots = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$noLots->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$noLots->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL, name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$noLots->exec("CREATE TABLE loans (id TEXT PRIMARY KEY, kind TEXT NOT NULL, asset_id TEXT, status TEXT NOT NULL DEFAULT 'active')");
Database::migrate($noLots);
check(true, 'Migrate skips missing stock_lots');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','now','now')");
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('store','창고','room','ROOM:store','now','now')");
$pdo->prepare('INSERT INTO catalog_items(id,name,type,unit,min_stock,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
    ->execute(['ci-1', '납땜', 'consumable', 'm', 5, 'CAT:ci-1', 'now', 'now']);
$pdo->prepare('INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES(?,?,?,?,?)')
    ->execute(['lot-1', 'ci-1', 'room', 2, 'now']);

$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];
$teacher = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];

Stock::updateLot($pdo, $owner, 'ci-1', 'lot-1', 'SN-1', '2027-03-31');
$row = $pdo->query("SELECT lot_code, expires_at, quantity FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check($row['lot_code'] === 'SN-1' && $row['expires_at'] === '2027-03-31' && (float) $row['quantity'] === 2.0, 'Lot attrs save without changing qty');
$log = $pdo->query("SELECT * FROM activity_logs WHERE action='update_lot' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check(is_array($log) && str_contains((string) $log['summary'], '로트 SN-1'), 'Lot update writes audit');

Stock::updateLot($pdo, $owner, 'ci-1', 'lot-1', '', '');
$row = $pdo->query("SELECT lot_code, expires_at FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check($row['lot_code'] === null && $row['expires_at'] === null, 'Lot attrs clear');

reject(static fn () => Stock::updateLot($pdo, $teacher, 'ci-1', 'lot-1', 'X', null), 'Teacher lot update');
reject(static fn () => Stock::updateLot($pdo, $owner, 'ci-1', 'missing', 'X', null), 'Missing lot');
reject(static fn () => Stock::updateLot($pdo, $owner, 'ci-1', 'lot-1', 'X', 'bad-date'), 'Bad expiry on lot update');

Stock::restock($pdo, $owner, 'ci-1', 'room', '1', '보충');
$row = $pdo->query("SELECT lot_code, expires_at, quantity FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check((float) $row['quantity'] === 3.0 && $row['lot_code'] === null, 'Restock without attrs leaves lot fields');

Stock::restock($pdo, $owner, 'ci-1', 'room', '1', '로트입고', [
    'lot_code' => 'SN-2',
    'expires_at' => '2028-01-15',
]);
$row = $pdo->query("SELECT lot_code, expires_at, quantity FROM stock_lots WHERE id='lot-1'")->fetch(PDO::FETCH_ASSOC);
check((float) $row['quantity'] === 4.0 && $row['lot_code'] === 'SN-2' && $row['expires_at'] === '2028-01-15', 'Restock can set lot attrs');

Stock::restock($pdo, $owner, 'ci-1', 'store', '2', '새 위치', [
    'lot_code' => 'SN-STORE',
    'expires_at' => '2026-12-01',
]);
$newLot = $pdo->query("SELECT * FROM stock_lots WHERE catalog_item_id='ci-1' AND location_id='store'")->fetch(PDO::FETCH_ASSOC);
check(is_array($newLot) && $newLot['lot_code'] === 'SN-STORE' && (float) $newLot['quantity'] === 2.0, 'New location restock stores lot attrs');

Catalog::update($pdo, $owner, 'ci-1', '납땜', null, [], 'm', '20', null, null, null, false);
check(Alert::lowStockItem($pdo, 'ci-1') !== null, 'Raising min stock above qty is low stock');
$listed = Catalog::list($pdo);
$ci = null;
foreach ($listed as $item) {
    if ($item['id'] === 'ci-1') {
        $ci = $item;
        break;
    }
}
check(is_array($ci) && !empty($ci['low_stock']), 'Catalog list flags shortage after min stock edit');

Catalog::update($pdo, $owner, 'ci-1', '납땜', null, [], 'ea', '1', null, null, null, false);
check(Alert::lowStockItem($pdo, 'ci-1') === null, 'Lowering min stock below qty clears shortage');

$root = dirname(__DIR__);
$newTpl = (string) file_get_contents($root . '/templates/items/new.php');
check(str_contains($newTpl, 'name="unit"') && str_contains($newTpl, 'name="min_stock"'), 'Create form has unit and min stock');
check(str_contains($newTpl, 'name="lot_code"') && str_contains($newTpl, 'name="expires_at"'), 'Create form has optional lot/expiry');
check(str_contains($newTpl, 'Csrf::field()') && str_contains($newTpl, 'method="post"'), 'Create form is POST+CSRF');

$editTpl = (string) file_get_contents($root . '/templates/items/edit.php');
check(str_contains($editTpl, 'name="unit"') && str_contains($editTpl, 'name="min_stock"'), 'Edit form has unit and min stock');
check(str_contains($editTpl, 'Csrf::field()'), 'Edit form includes CSRF');

$showTpl = (string) file_get_contents($root . '/templates/items/show.php');
check(str_contains($showTpl, 'badge overdue') && str_contains($showTpl, '부족'), 'Item show can render shortage badge');
check(str_contains($showTpl, 'items/lot') && str_contains($showTpl, 'Auth::canWrite($user)'), 'Lot edit is canWrite-gated');
check(str_contains($showTpl, 'StockLot::format'), 'Item show displays lot/expiry');

$listTpl = (string) file_get_contents($root . '/templates/items/index.php');
check(str_contains($listTpl, 'badge overdue') && str_contains($listTpl, 'is-low-stock'), 'Item list shows shortage badge and highlight');

$matTpl = (string) file_get_contents($root . '/templates/materials/index.php');
check(str_contains($matTpl, 'badge overdue') && str_contains($matTpl, 'is-low-stock'), 'Materials board shows shortage badge');

$ctl = (string) file_get_contents($root . '/app/Controllers/ItemController.php');
check(str_contains($ctl, 'function save') && str_contains($ctl, 'Csrf::requirePost()'), 'Create is POST+CSRF');
check(str_contains($ctl, 'Alert::notifyLowStock') && str_contains($ctl, 'function save'), 'Create wires existing low-stock alert');
check(str_contains($ctl, 'function updateLot') && str_contains($ctl, 'Auth::canWrite($user)'), 'Lot update requires canWrite');
check(preg_match('/function updateLot\(\): void\s*\{.*?Csrf::requirePost\(\)/s', $ctl) === 1, 'Lot update is POST+CSRF');
check(str_contains($ctl, 'Catalog::parseMinStock') && str_contains($ctl, 'Catalog::parseUnit'), 'Create validates unit and min stock');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'items/lot' => [ItemController::class, 'updateLot']"), 'Router maps lot update');

$loanInbox = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
$desk = (string) file_get_contents($root . '/app/Controllers/DeskController.php');
$repair = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
check(str_contains($loanInbox, 'function mine') && str_contains($desk, 'function index') && str_contains($repair, 'function updateStatus'), 'Loans/mine, desk, repair controllers stay present');

echo "PASS: {$checks} stock-lot checks\n";
