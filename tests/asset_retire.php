<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Asset;
use Inni\AssetLifeBoard;
use Inni\Auth;
use Inni\Database;
use Inni\Loan;
use Inni\Report;

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
 * @return array{0: mixed, 1: mixed, 2: mixed}
 */
function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT asset_id, reason FROM asset_retirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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

function seedAssets(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','전자실습실','room','LOC:room','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','스코프','equipment','CAT:eq','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,purchase_date,useful_life_years,created_at,updated_at) VALUES('ast-ok','eq','스코프 #1','M1','available','room','AST:1','2020-01-01',5,'t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-loan','eq','스코프 #2','M2','available','room','AST:2','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-rep','eq','스코프 #3','M3','repair','room','AST:3','t','t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher'] as $id => $role) {
        insertUser($pdo, $id, $role);
    }
}

$pdo = memoryDb();
seedAssets($pdo);
$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');

Loan::checkout($pdo, $owner, 'ast-loan', '이수업', null, '수업', null);

reject($pdo, static function () use ($pdo, $teacher): void {
    Asset::retire($pdo, $teacher, 'ast-ok', '만료', '2026-09-15');
}, 'Teacher retire');
reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-ok', '', '2026-09-15');
}, 'Empty reason');
reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-ok', '만료', '');
}, 'Empty date');
reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-loan', '만료', '2026-09-15');
}, 'On-loan retire');
reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'missing', '만료', '2026-09-15');
}, 'Unknown asset');

$agingBefore = AssetLifeBoard::summary($pdo, '2026-09-15');
$row = Asset::retire($pdo, $manager, 'ast-ok', '내용연한 만료', '2026-09-01', '/uploads/retire/demo.jpg');
check($row['retired_on'] === '2026-09-01' && $row['reason'] === '내용연한 만료', 'retire stores date and reason');
check($row['evidence_path'] === '/uploads/retire/demo.jpg', 'retire stores evidence path');
check($pdo->query("SELECT status FROM assets WHERE id='ast-ok'")->fetchColumn() === 'retired', 'asset status becomes retired');
$log = $pdo->query("SELECT * FROM activity_logs WHERE action='retire' AND entity_id='ast-ok'")->fetch(PDO::FETCH_ASSOC);
check(is_array($log) && str_contains((string) $log['summary'], '파기') && str_contains((string) $log['summary'], '내용연한 만료'), 'retire writes activity history');

reject($pdo, static function () use ($pdo, $owner): void {
    Asset::retire($pdo, $owner, 'ast-ok', '다시', '2026-09-16');
}, 'Second retire');

$found = Asset::retirement($pdo, 'ast-ok');
check(is_array($found) && $found['reason'] === '내용연한 만료', 'retirement lookup');
check(Asset::retirement($pdo, 'ast-rep') === null, 'unretired asset has no row');

$repair = Report::file($pdo, $teacher, 'ast-rep', '전원 불량');
check($pdo->query("SELECT status FROM assets WHERE id='ast-rep'")->fetchColumn() === 'repair', 'repair workflow still marks idle assets');
Asset::retire($pdo, $owner, 'ast-rep', '수리 불가 파기', '2026-09-15');
check($pdo->query("SELECT status FROM assets WHERE id='ast-rep'")->fetchColumn() === 'retired', 'repair asset can be retired');
check($pdo->query("SELECT status FROM reports WHERE id=" . $pdo->quote($repair))->fetchColumn() === 'open', 'open repair row stays');

check($pdo->query("SELECT status FROM assets WHERE id='ast-loan'")->fetchColumn() === 'on_loan', 'loaned asset is untouched');
check(AssetLifeBoard::summary($pdo, '2026-09-15')['total'] === $agingBefore['total'], 'aging board still counts dated assets including retired');

$rollback = memoryDb();
seedAssets($rollback);
$before = snapshot($rollback);
$rollback->exec("CREATE TRIGGER fail_ret_log BEFORE INSERT ON activity_logs WHEN NEW.action='retire' BEGIN SELECT RAISE(ABORT, 'test retire log failure'); END");
try {
    Asset::retire($rollback, $owner, 'ast-ok', '롤백', '2026-09-15');
    throw new RuntimeException('Expected retire log failure');
} catch (PDOException) {
    check(snapshot($rollback) === $before && !$rollback->inTransaction(), 'log failure rolls back retire');
}

$legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy->exec('PRAGMA foreign_keys = ON');
$legacy->exec((string) file_get_contents($root . '/sql/schema.sql'));
$legacy->exec('DROP TABLE asset_retirements');
Database::migrate($legacy);
check(
    $legacy->query("SELECT name FROM sqlite_master WHERE type='table' AND name='asset_retirements'")->fetchColumn() === 'asset_retirements',
    'migrate creates asset_retirements'
);

$ctl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
check(str_contains($ctl, 'function retire') && str_contains($ctl, 'Csrf::requirePost()'), 'retire is POST+CSRF');
check(preg_match('/function retire\(\): void\s*\{[^}]*Auth::canWrite\(\$user\)/s', $ctl) === 1, 'retire requires canWrite');
check(str_contains($ctl, 'confirm_irreversible'), 'retire requires irreversible confirm');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'assets/retire' => [AssetController::class, 'retire']"), 'retire route is registered');
check(!str_contains($router, "'assets/destroy'"), 'no assets/destroy alias');

$show = (string) file_get_contents($root . '/templates/assets/show.php');
check(str_contains($show, 'assets/retire') && str_contains($show, 'name="reason"') && str_contains($show, 'name="retired_on"'), 'asset detail has retire form');
check(str_contains($show, 'name="confirm_irreversible"'), 'retire form gates the irreversible write');
check(str_contains($show, 'Auth::canWrite($user)'), 'retire form is canWrite-only');

$aging = (string) file_get_contents($root . '/templates/assets/aging.php');
check(!str_contains($aging, 'assets/retire') && !str_contains($aging, 'name="retired_on"'), 'aging board stays read-only');
check(Auth::canWrite($owner) && Auth::canWrite($manager) && !Auth::canWrite($teacher), 'retire role is manager+');

echo "PASS: {$checks} asset-retire checks\n";
