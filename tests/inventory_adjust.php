<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\Database;
use Inni\Inventory;
use Inni\InventoryBudget;

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

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

/**
 * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed}
 */
function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT line_id, physical_qty FROM inventory_adjustments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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

function seedInventory(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,null,'LOC:bldg','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('room','전자실습실','room','bldg','E-201','LOC:room','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','ea','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('solder','납땜','consumable','CAT:solder','m',5,'t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-1','eq','스코프 #1','전장-2024-017','available','room','AST:ast-1','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-2','eq','스코프 #2','전장-2024-018','on_loan','room','AST:ast-2','t','t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','solder','room',18,'t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
        insertUser($pdo, $id, $role);
    }
}

$pdo = memoryDb();
seedInventory($pdo);
$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');
$student = actor('student', 'student');

$check = Inventory::start($pdo, $owner, 'room');
Inventory::confirm($pdo, $owner, 'AST:ast-2');
$done = Inventory::finish($pdo, $owner, $check['id']);
check($done['status'] === 'done', 'session is finished before adjust');
$unchecked = Inventory::unchecked($pdo, $check['id']);
$byKind = [];
foreach ($unchecked as $line) {
    $byKind[(string) $line['kind']] = $line;
}
check(isset($byKind['item'], $byKind['asset']), 'missing list has the item and unused asset');

$itemLine = $byKind['item'];
$assetLine = $byKind['asset'];

reject($pdo, static function () use ($pdo, $teacher, $itemLine): void {
    Inventory::adjust($pdo, $teacher, (string) $itemLine['id'], 0, '미확인', '교사');
}, 'Teacher adjust');
reject($pdo, static function () use ($pdo, $student, $itemLine): void {
    Inventory::adjust($pdo, $student, (string) $itemLine['id'], 0, '미확인', '학생');
}, 'Student adjust');
reject($pdo, static function () use ($pdo, $owner, $itemLine): void {
    Inventory::adjust($pdo, $owner, (string) $itemLine['id'], 0, '', 'owner');
}, 'Empty reason');
reject($pdo, static function () use ($pdo, $owner, $itemLine): void {
    Inventory::adjust($pdo, $owner, (string) $itemLine['id'], 0, '미확인', '');
}, 'Empty approver');
reject($pdo, static function () use ($pdo, $owner, $itemLine): void {
    Inventory::adjust($pdo, $owner, (string) $itemLine['id'], -1, '미확인', 'owner');
}, 'Negative qty');
reject($pdo, static function () use ($pdo, $owner, $assetLine): void {
    Inventory::adjust($pdo, $owner, (string) $assetLine['id'], 2, '미확인', 'owner');
}, 'Asset qty not 0/1');

$active = memoryDb();
seedInventory($active);
$open = Inventory::start($active, $owner, 'room');
$openLine = Inventory::unchecked($active, $open['id'])[0];
reject($active, static function () use ($active, $owner, $openLine): void {
    Inventory::adjust($active, $owner, (string) $openLine['id'], 0, '진행중', 'owner');
}, 'Active check adjust');

$confirmedId = $pdo->query(
    "SELECT id FROM inventory_check_lines WHERE check_id = " . $pdo->quote($check['id']) . " AND confirmed_at IS NOT NULL LIMIT 1"
)->fetchColumn();
reject($pdo, static function () use ($pdo, $owner, $confirmedId): void {
    Inventory::adjust($pdo, $owner, (string) $confirmedId, 0, '확인됨', 'owner');
}, 'Confirmed line adjust');

$adj = Inventory::adjust($pdo, $manager, (string) $itemLine['id'], 0, '실사 미확인', '김부장');
check((float) $adj['physical_qty'] === 0.0 && $adj['approver_name'] === '김부장', 'item adjust stores reason and approver');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-1'")->fetchColumn() === 0.0, 'item adjust writes book qty to the lot');
$log = $pdo->query("SELECT * FROM activity_logs WHERE action='inventory_adjust' AND entity_id='solder' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check(is_array($log) && str_contains((string) $log['summary'], '실사 보정') && str_contains((string) $log['summary'], '김부장'), 'item adjust writes activity history');
$meta = json_decode((string) $log['meta_json'], true);
check(is_array($meta) && $meta['reason'] === '실사 미확인' && $meta['applied'] === 'stock', 'item adjust meta records ledger write');

reject($pdo, static function () use ($pdo, $owner, $itemLine): void {
    Inventory::adjust($pdo, $owner, (string) $itemLine['id'], 4, '다시', 'owner');
}, 'Double item adjust');

$assetAdj = Inventory::adjust($pdo, $owner, (string) $assetLine['id'], 0, '실물 없음', 'owner');
check($assetAdj['kind'] === 'asset', 'asset missing line can be adjusted');
check($pdo->query("SELECT status FROM assets WHERE id='ast-1'")->fetchColumn() === 'lost', 'missing asset adjust marks lost, not retired');
check($pdo->query("SELECT status FROM assets WHERE id='ast-2'")->fetchColumn() === 'on_loan', 'on-loan asset is unchanged');
$assetLog = $pdo->query("SELECT summary, meta_json FROM activity_logs WHERE action='inventory_adjust' AND entity_id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check(is_array($assetLog) && str_contains((string) $assetLog['summary'], '실물 없음'), 'asset adjust leaves history');

$mapped = Inventory::adjustmentsForLines($pdo, [(string) $itemLine['id'], (string) $assetLine['id']]);
check(count($mapped) === 2 && isset($mapped[$itemLine['id']], $mapped[$assetLine['id']]), 'lookup returns both adjustments');

$beforeReport = [
    $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
];
InventoryBudget::lines($pdo, ['diff' => 'missing']);
InventoryBudget::summary($pdo, []);
check(
    [
        $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ] === $beforeReport,
    'budget report helpers stay read-only after adjust'
);

$rollback = memoryDb();
seedInventory($rollback);
$rbCheck = Inventory::start($rollback, $owner, 'room');
Inventory::finish($rollback, $owner, $rbCheck['id']);
$rbLine = Inventory::unchecked($rollback, $rbCheck['id']);
$rbItem = null;
foreach ($rbLine as $line) {
    if ($line['kind'] === 'item') {
        $rbItem = $line;
    }
}
check($rbItem !== null, 'rollback fixture has an item line');
$before = snapshot($rollback);
$rollback->exec("CREATE TRIGGER fail_adj_log BEFORE INSERT ON activity_logs WHEN NEW.action='inventory_adjust' BEGIN SELECT RAISE(ABORT, 'test adjust log failure'); END");
try {
    Inventory::adjust($rollback, $owner, (string) $rbItem['id'], 0, '롤백', 'owner');
    throw new RuntimeException('Expected adjust log failure');
} catch (PDOException) {
    check(snapshot($rollback) === $before && !$rollback->inTransaction(), 'log failure rolls back adjust');
}

$legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy->exec('PRAGMA foreign_keys = ON');
$legacy->exec((string) file_get_contents($root . '/sql/schema.sql'));
$legacy->exec('DROP TABLE inventory_adjustments');
Database::migrate($legacy);
$tables = $legacy->query("SELECT name FROM sqlite_master WHERE type='table' AND name='inventory_adjustments'")->fetchColumn();
check($tables === 'inventory_adjustments', 'migrate creates inventory_adjustments');
Database::migrate($legacy);
check(true, 'adjust migrate is idempotent');

$ctl = (string) file_get_contents($root . '/app/Controllers/InventoryController.php');
check(str_contains($ctl, 'function adjust') && str_contains($ctl, 'Csrf::requirePost()'), 'adjust is POST+CSRF');
check(str_contains($ctl, 'Auth::canInventory($user)') && str_contains($ctl, 'Auth::canWrite($user)'), 'adjust is canInventory/canWrite');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'inventory/adjust' => [InventoryController::class, 'adjust']"), 'adjust route is registered');
check(!str_contains($router, "'assets/adjust'"), 'adjust is not on assets/adjust');

$result = (string) file_get_contents($root . '/templates/inventory/result.php');
$report = (string) file_get_contents($root . '/templates/inventory/report.php');
$partial = (string) file_get_contents($root . '/templates/partials/inventory_adjust.php');
check(str_contains($result, 'inventory_adjust.php') && str_contains($report, 'inventory_adjust.php'), 'result and report reuse the adjust form');
check(str_contains($partial, 'name="reason"') && str_contains($partial, 'name="approver_name"'), 'adjust form has reason and approver');
check(str_contains($partial, 'Csrf::field()'), 'adjust form posts CSRF');
check(Auth::canInventory($owner) && Auth::canWrite($manager) && !Auth::canWrite($teacher), 'role gates stay owner/manager');

$aging = (string) file_get_contents($root . '/templates/assets/aging.php');
check(!str_contains($aging, 'inventory/adjust') && !str_contains($aging, 'assets/retire'), 'aging board has no adjust/retire writes');

echo "PASS: {$checks} inventory-adjust checks\n";
