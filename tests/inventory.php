<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\Inventory;
use Inni\Scan;

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

/**
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>}
 */
function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM inventory_checks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, confirmed_at FROM inventory_check_lines ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_id FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function reject(PDO $pdo, callable $fn, string $message): void
{
    $before = snapshot($pdo);
    try {
        $fn();
        throw new RuntimeException($message . ' was accepted');
    } catch (InvalidArgumentException) {
        check(snapshot($pdo) === $before, $message . ' changed state');
    }
}

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

$pdo = memoryDb();
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,null,'LOC:bldg','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('room','전자실습실','room','bldg','E-201','LOC:room','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('cab','계측기 캐비닛','storage','room',null,'LOC:cab','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('other','용접실','room','bldg','W-103','LOC:other','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,created_at,updated_at) VALUES('solder','납땜','consumable','CAT:solder','m','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('other-eq','용접기','equipment','CAT:other-eq','t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-1','eq','스코프 #1','전장-2024-017','available','cab','AST:ast-1','t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-2','eq','스코프 #2','전장-2024-018','on_loan','room','AST:ast-2','t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-other','other-eq','용접기 A','용접-2022-004','available','other','AST:ast-other','t','t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','solder','room',18,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-other','solder','other',4,'t')");
foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
    insertUser($pdo, $id, $role);
}

$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');
$student = actor('student', 'student');

check(Scan::lookup($pdo, '전장-2024-017') === ['kind' => 'asset', 'id' => 'ast-1'], 'Scan lookup uses management number');
check(Scan::lookup($pdo, 'AST:ast-2') === ['kind' => 'asset', 'id' => 'ast-2'], 'Scan lookup uses AST prefix');
check(Scan::lookup($pdo, 'CAT:solder') === ['kind' => 'catalog', 'id' => 'solder'], 'Scan lookup uses CAT prefix');
check(Scan::lookup($pdo, 'E-201') === ['kind' => 'location', 'id' => 'room'], 'Scan lookup uses room code');
check(Scan::lookup($pdo, 'missing') === null, 'Unknown code is null');

reject($pdo, static function () use ($pdo, $teacher): void {
    Inventory::start($pdo, $teacher, 'room');
}, 'Teacher start');
reject($pdo, static function () use ($pdo, $student): void {
    Inventory::start($pdo, $student, 'room');
}, 'Student start');
reject($pdo, static function () use ($pdo, $owner): void {
    Inventory::start($pdo, $owner, 'cab');
}, 'Storage start');
reject($pdo, static function () use ($pdo, $owner): void {
    Inventory::start($pdo, $owner, '');
}, 'Empty room start');
reject($pdo, static function () use ($pdo, $owner): void {
    Inventory::confirm($pdo, $owner, '전장-2024-017');
}, 'Confirm without an active check');

$check = Inventory::start($pdo, $owner, 'room');
check($check['status'] === 'active' && $check['location_id'] === 'room', 'Start creates one active check');
$lines = Inventory::lines($pdo, $check['id']);
$ids = array_column($lines, 'asset_id');
$lots = array_column($lines, 'stock_lot_id');
check(in_array('ast-1', $ids, true) && in_array('ast-2', $ids, true), 'Expected list includes room and descendant assets');
check(!in_array('ast-other', $ids, true), 'Expected list excludes other rooms');
check(in_array('lot-1', $lots, true) && !in_array('lot-other', $lots, true), 'Expected list includes this room stock only');
check(count($lines) === 3, 'Expected list is two assets and one lot');

reject($pdo, static function () use ($pdo, $manager): void {
    Inventory::start($pdo, $manager, 'other');
}, 'Second active start');

$qtyBefore = (float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-1'")->fetchColumn();
$confirmed = Inventory::confirm($pdo, $manager, '전장-2024-017');
check($confirmed['asset_id'] === 'ast-1' && $confirmed['confirmed_at'] !== null, 'Management number confirms the asset');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-1'")->fetchColumn() === $qtyBefore, 'Confirm does not change stock');

Inventory::confirm($pdo, $owner, 'AST:ast-2');
Inventory::confirm($pdo, $owner, 'CAT:solder');
check(Inventory::unchecked($pdo, $check['id']) === [], 'All expected lines can be confirmed via scan prefixes');

reject($pdo, static function () use ($pdo, $owner): void {
    Inventory::confirm($pdo, $owner, '전장-2024-017');
}, 'Already confirmed');
reject($pdo, static function () use ($pdo, $owner): void {
    Inventory::confirm($pdo, $owner, '용접-2022-004');
}, 'Other-room asset');
reject($pdo, static function () use ($pdo, $owner): void {
    Inventory::confirm($pdo, $owner, 'E-201');
}, 'Location code');
reject($pdo, static function () use ($pdo, $owner): void {
    Inventory::confirm($pdo, $owner, 'no-such-code');
}, 'Unknown code');
reject($pdo, static function () use ($pdo, $teacher): void {
    Inventory::confirm($pdo, $teacher, '전장-2024-017');
}, 'Teacher confirm');

$done = Inventory::finish($pdo, $owner, $check['id']);
check($done['status'] === 'done' && $done['finished_at'] !== null, 'Finish closes the single active check');
check(Inventory::active($pdo) === null, 'No active check after finish');
check(Inventory::unchecked($pdo, $check['id']) === [], 'Finished all-confirmed session has empty unchecked list');

reject($pdo, static function () use ($pdo, $owner, $check): void {
    Inventory::finish($pdo, $owner, $check['id']);
}, 'Finish twice');

$second = Inventory::start($pdo, $manager, 'other');
Inventory::confirm($pdo, $manager, 'AST:ast-other');
$finished = Inventory::finish($pdo, $manager, $second['id']);
$unchecked = Inventory::unchecked($pdo, $second['id']);
check($finished['status'] === 'done', 'Second session can start after the first is done');
check(count($unchecked) === 1 && $unchecked[0]['stock_lot_id'] === 'lot-other', 'Result lists the unchecked stock item');

$emptyRoom = memoryDb();
$emptyRoom->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('empty','빈 실','room','LOC:empty','t','t')");
insertUser($emptyRoom, 'owner', 'owner');
$empty = Inventory::start($emptyRoom, $owner, 'empty');
check(Inventory::lines($emptyRoom, $empty['id']) === [], 'Empty room starts with no expected lines');
$emptyDone = Inventory::finish($emptyRoom, $owner, $empty['id']);
check($emptyDone['status'] === 'done' && Inventory::unchecked($emptyRoom, $empty['id']) === [], 'Empty room finish has no unchecked lines');

$rollback = memoryDb();
$rollback->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','전자실습실','room','LOC:room','t','t')");
$rollback->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','스코프','equipment','CAT:eq','t','t')");
$rollback->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-1','eq','스코프','M1','available','room','AST:ast-1','t','t')");
insertUser($rollback, 'owner', 'owner');
$before = snapshot($rollback);
$rollback->exec("CREATE TRIGGER fail_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test log failure'); END");
try {
    Inventory::start($rollback, $owner, 'room');
    throw new RuntimeException('Expected log failure');
} catch (PDOException) {
    check(snapshot($rollback) === $before && !$rollback->inTransaction(), 'Log failure rolls back the inventory start');
}
$rollback->exec('DROP TRIGGER fail_log');

$auth = (string) file_get_contents($root . '/app/Auth.php');
check(str_contains($auth, 'function canInventory'), 'Auth exposes canInventory');
check(Auth::canInventory($owner) && Auth::canInventory($manager) && !Auth::canInventory($teacher), 'canInventory is owner/manager only');

echo "PASS: {$checks} inventory checks\n";
