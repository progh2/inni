<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Asset;
use Inni\Auth;
use Inni\Database;
use Inni\Inventory;
use Inni\InventoryAdjust;

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
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>}
 */
function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status, location_id FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, adjusted_at FROM inventory_check_lines ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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

function seedSchool(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,null,'LOC:bldg','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('room','전자실습실','room','bldg','E-201','LOC:room','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('other','용접실','room','bldg','W-103','LOC:other','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','ea','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,created_at,updated_at) VALUES('solder','납땜','consumable','CAT:solder','m','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-1','eq','스코프 #1','전장-2024-017','available','room','AST:ast-1','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-2','eq','스코프 #2','전장-2024-018','on_loan','room','AST:ast-2','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-3','eq','스코프 #3','전장-2024-019','available','room','AST:ast-3','t','t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','solder','room',18,'t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
        insertUser($pdo, $id, $role);
    }
}

$pdo = memoryDb();
seedSchool($pdo);

$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');
$student = actor('student', 'student');

check(InventoryAdjust::parseReason(' 실사 불일치 ') === '실사 불일치', 'reason trims');
check(InventoryAdjust::parseQuantity('0') === 0.0 && InventoryAdjust::parseQuantity('12.5') === 12.5, 'qty allows zero and decimals');
check(Asset::parseRetireDate('2026.9.15') === '2026-09-15', 'retire date normalizes');
check(Asset::parseRetireEvidence('폐기조서 12호') === '폐기조서 12호', 'evidence note is kept');
check(Asset::parseRetireEvidence('', '/uploads/retire/a.jpg') === '/uploads/retire/a.jpg', 'evidence photo path is enough');

try {
    InventoryAdjust::parseReason('');
    throw new RuntimeException('empty reason was accepted');
} catch (InvalidArgumentException) {
    check(true, 'empty reason rejected');
}
try {
    InventoryAdjust::parseQuantity('-1');
    throw new RuntimeException('negative qty was accepted');
} catch (InvalidArgumentException) {
    check(true, 'negative qty rejected');
}
try {
    Asset::parseRetireDate('');
    throw new RuntimeException('empty retire date was accepted');
} catch (InvalidArgumentException) {
    check(true, 'empty retire date rejected');
}
try {
    Asset::parseRetireEvidence('');
    throw new RuntimeException('empty evidence was accepted');
} catch (InvalidArgumentException) {
    check(true, 'empty evidence rejected');
}

$approvers = InventoryAdjust::approvers($pdo);
$approverIds = array_column($approvers, 'id');
sort($approverIds);
check($approverIds === ['manager', 'owner'], 'approver list is owner/manager only');

$check = Inventory::start($pdo, $owner, 'room');
$lines = Inventory::lines($pdo, $check['id']);
$byAsset = [];
$itemLine = null;
foreach ($lines as $line) {
    if (($line['kind'] ?? '') === 'asset') {
        $byAsset[(string) $line['asset_id']] = $line;
    }
    if (($line['stock_lot_id'] ?? '') === 'lot-1') {
        $itemLine = $line;
    }
}
check(isset($byAsset['ast-1'], $byAsset['ast-2'], $itemLine), 'seed check has assets and solder');

reject($pdo, static function () use ($pdo, $owner, $byAsset): void {
    InventoryAdjust::adjust($pdo, $owner, (string) $byAsset['ast-1']['id'], '미확인', 'owner', 'other', 'lost');
}, 'Adjust while check is active');

Inventory::confirm($pdo, $owner, '전장-2024-017');
$done = Inventory::finish($pdo, $owner, $check['id']);
check($done['status'] === 'done', 'check finishes before adjust');

$lines = Inventory::lines($pdo, $check['id']);
foreach ($lines as $line) {
    if (($line['kind'] ?? '') === 'asset') {
        $byAsset[(string) $line['asset_id']] = $line;
    }
    if (($line['stock_lot_id'] ?? '') === 'lot-1') {
        $itemLine = $line;
    }
}

reject($pdo, static function () use ($pdo, $teacher, $byAsset): void {
    InventoryAdjust::adjust($pdo, $teacher, (string) $byAsset['ast-3']['id'], '미확인', 'owner', null, 'lost');
}, 'Teacher adjust');
reject($pdo, static function () use ($pdo, $student, $byAsset): void {
    InventoryAdjust::adjust($pdo, $student, (string) $byAsset['ast-3']['id'], '미확인', 'owner', null, 'lost');
}, 'Student adjust');
reject($pdo, static function () use ($pdo, $owner, $byAsset): void {
    InventoryAdjust::adjust($pdo, $owner, (string) $byAsset['ast-3']['id'], '미확인', 'teacher', null, 'lost');
}, 'Teacher approver');
reject($pdo, static function () use ($pdo, $owner, $byAsset): void {
    InventoryAdjust::adjust($pdo, $owner, (string) $byAsset['ast-3']['id'], '', 'owner', null, 'lost');
}, 'Empty adjust reason');
reject($pdo, static function () use ($pdo, $owner, $byAsset): void {
    InventoryAdjust::adjust($pdo, $owner, (string) $byAsset['ast-2']['id'], '대여분 분실', 'owner', null, 'lost');
}, 'On-loan asset adjust');

$missing = $byAsset['ast-3'];
$adjusted = InventoryAdjust::adjust($pdo, $manager, (string) $missing['id'], '실사 미확인', 'owner', 'other', 'lost');
check($adjusted['adjusted_at'] !== null && $adjusted['adjust_reason'] === '실사 미확인', 'line records the adjustment');
check($adjusted['adjust_approver'] === 'owner', 'line stores approver name');
$ast3 = $pdo->query("SELECT status, location_id FROM assets WHERE id='ast-3'")->fetch(PDO::FETCH_ASSOC);
check($ast3['status'] === 'lost' && $ast3['location_id'] === 'other', 'missing asset is marked lost and moved');
$adjLog = $pdo->query("SELECT * FROM activity_logs WHERE action='inventory_adjust' AND entity_id='ast-3' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check(is_array($adjLog) && str_contains((string) $adjLog['summary'], '실사 보정'), 'asset adjust writes history');
$meta = json_decode((string) $adjLog['meta_json'], true);
check(is_array($meta) && $meta['reason'] === '실사 미확인' && $meta['approver_id'] === 'owner', 'history keeps reason and approver');
check(($meta['after']['status'] ?? '') === 'lost' && ($meta['after']['location_id'] ?? '') === 'other', 'history keeps before/after');

InventoryAdjust::adjust($pdo, $owner, (string) $itemLine['id'], '실물 12m', 'manager', null, null, '12');
$qty = (float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-1'")->fetchColumn();
check($qty === 12.0, 'item adjust writes stock qty');
$itemLog = $pdo->query("SELECT * FROM activity_logs WHERE action='inventory_adjust' AND entity_id='solder' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check(is_array($itemLog) && str_contains((string) $itemLog['summary'], '승인자 manager'), 'item adjust history names approver');

reject($pdo, static function () use ($pdo, $owner, $itemLine): void {
    InventoryAdjust::adjust($pdo, $owner, (string) $itemLine['id'], '같은 수량', 'owner', null, null, '12');
}, 'Same qty adjust');

reject($pdo, static function () use ($pdo, $teacher): void {
    Asset::retire($pdo, $teacher, 'ast-1', '노후', '2026-09-15', '폐기조서 1');
}, 'Teacher retire');
reject($pdo, static function () use ($pdo, $student): void {
    Asset::retire($pdo, $student, 'ast-1', '노후', '2026-09-15', '폐기조서 1');
}, 'Student retire');
reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-2', '노후', '2026-09-15', '폐기조서 1');
}, 'On-loan retire');
reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-1', '', '2026-09-15', '폐기조서 1');
}, 'Empty retire reason');
reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-1', '노후', '', '폐기조서 1');
}, 'Empty retire date');
reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-1', '노후', '2026-09-15', '');
}, 'Empty retire evidence');

Asset::retire($pdo, $manager, 'ast-1', '내용연한 만료', '2026-09-01', '폐기조서 2026-3');
$retired = $pdo->query("SELECT status, retired_at, retire_reason, retire_evidence FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check(
    $retired['status'] === 'retired'
    && $retired['retired_at'] === '2026-09-01'
    && $retired['retire_reason'] === '내용연한 만료'
    && $retired['retire_evidence'] === '폐기조서 2026-3',
    'retire writes status, date, reason, evidence'
);
$retLog = $pdo->query("SELECT * FROM activity_logs WHERE action='retire' AND entity_id='ast-1' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check(is_array($retLog) && str_contains((string) $retLog['summary'], '파기'), 'retire writes history');
$retMeta = json_decode((string) $retLog['meta_json'], true);
check(is_array($retMeta) && $retMeta['reason'] === '내용연한 만료' && $retMeta['retired_at'] === '2026-09-01', 'retire history keeps reason and date');

reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-1', '다시', '2026-09-16', '조서');
}, 'Retire twice');
reject($pdo, static function () use ($pdo, $owner, $byAsset): void {
    InventoryAdjust::adjust($pdo, $owner, (string) $byAsset['ast-1']['id'], '폐기 후', 'owner', null, 'available');
}, 'Adjust after retire');

$rollback = memoryDb();
seedSchool($rollback);
$rbCheck = Inventory::start($rollback, $owner, 'room');
Inventory::finish($rollback, $owner, $rbCheck['id']);
$rbLine = $rollback->query("SELECT id FROM inventory_check_lines WHERE asset_id='ast-3'")->fetch(PDO::FETCH_ASSOC);
$before = snapshot($rollback);
$rollback->exec("CREATE TRIGGER fail_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test log failure'); END");
try {
    InventoryAdjust::adjust($rollback, $owner, (string) $rbLine['id'], '미확인', 'owner', null, 'lost');
    throw new RuntimeException('Expected log failure');
} catch (PDOException) {
    check(snapshot($rollback) === $before && !$rollback->inTransaction(), 'Log failure rolls back adjust');
}
$rollback->exec('DROP TRIGGER fail_log');

$beforeRetire = snapshot($rollback);
$rollback->exec("CREATE TRIGGER fail_retire_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test log failure'); END");
try {
    Asset::retire($rollback, $owner, 'ast-1', '노후', '2026-09-15', '조서');
    throw new RuntimeException('Expected retire log failure');
} catch (PDOException) {
    check(snapshot($rollback) === $beforeRetire && !$rollback->inTransaction(), 'Log failure rolls back retire');
}
$rollback->exec('DROP TRIGGER fail_retire_log');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('PRAGMA foreign_keys = ON');
$old->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id), name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec("CREATE TABLE inventory_checks (id TEXT PRIMARY KEY, location_id TEXT NOT NULL, location_name TEXT NOT NULL, status TEXT NOT NULL, started_by TEXT NOT NULL, started_at TEXT NOT NULL)");
$old->exec("CREATE TABLE inventory_check_lines (id TEXT PRIMARY KEY, check_id TEXT NOT NULL, kind TEXT NOT NULL, asset_id TEXT, catalog_item_id TEXT, stock_lot_id TEXT, location_id TEXT NOT NULL, name TEXT NOT NULL, expected_qty REAL NOT NULL DEFAULT 1)");
Database::migrate($old);
$astCols = array_column($old->query('PRAGMA table_info(assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$lineCols = array_column($old->query('PRAGMA table_info(inventory_check_lines)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('retired_at', $astCols, true) && in_array('retire_reason', $astCols, true), 'migrate adds retire columns');
check(in_array('adjusted_at', $lineCols, true) && in_array('adjust_approver', $lineCols, true), 'migrate adds adjust columns');
Database::migrate($old);
check(true, 'retire/adjust migrate is idempotent');

$auth = (string) file_get_contents($root . '/app/Auth.php');
check(Auth::canWrite($owner) && Auth::canWrite($manager) && !Auth::canWrite($teacher), 'canWrite is owner/manager only');
check(str_contains($auth, 'function canWrite'), 'Auth exposes canWrite');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'inventory/adjust' => [InventoryController::class, 'adjust']"), 'router registers inventory/adjust');
check(str_contains($router, "'assets/retire' => [AssetController::class, 'retire']"), 'router registers assets/retire');

$invCtl = (string) file_get_contents($root . '/app/Controllers/InventoryController.php');
check(str_contains($invCtl, 'function adjust') && str_contains($invCtl, 'Csrf::requirePost()'), 'adjust is POST+CSRF');
check(str_contains($invCtl, 'Auth::canWrite($user)'), 'adjust is canWrite-gated');

$assetCtl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
check(str_contains($assetCtl, 'function retire') && str_contains($assetCtl, 'Csrf::requirePost()'), 'retire is POST+CSRF');
if (preg_match('/function retire\(\): void\s*\{(.*?)\n    public function |\n}\s*$/s', $assetCtl, $m)) {
    check(str_contains($m[1], 'Auth::canWrite($user)'), 'retire method requires canWrite');
} else {
    check(str_contains($assetCtl, '파기 권한이 없습니다.'), 'retire flashes a permission error');
}

$resultTpl = (string) file_get_contents($root . '/templates/inventory/result.php');
$reportTpl = (string) file_get_contents($root . '/templates/inventory/report.php');
$showTpl = (string) file_get_contents($root . '/templates/assets/show.php');
$partial = (string) file_get_contents($root . '/templates/partials/inventory_adjust.php');
check(str_contains($resultTpl, 'inventory_adjust.php') && str_contains($reportTpl, 'inventory_adjust.php'), 'result and report reuse the adjust form');
check(str_contains($partial, 'name="reason"') && str_contains($partial, 'name="approver_id"'), 'adjust form has reason and approver');
check(str_contains($showTpl, 'assets/retire') && str_contains($showTpl, 'name="retired_at"'), 'asset show has retire form');
check(str_contains($showTpl, 'Auth::canWrite($user)'), 'retire UI is write-gated');

echo "PASS: {$checks} inventory-adjust checks\n";
