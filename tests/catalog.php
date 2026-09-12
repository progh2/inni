<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Catalog;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','now','now')");
$pdo->prepare('INSERT INTO catalog_items(id,name,type,description,tags,unit,min_stock,manufacturer,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['ci-1', '납땜', 'consumable', '실습용', '["소모"]', 'm', 5, 'Kester', 'CAT:ci-1', 'now', 'now']);
$pdo->prepare('INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES(?,?,?,?,?)')
    ->execute(['lot-1', 'ci-1', 'room', 18, 'now']);

$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];
$manager = ['id' => 'manager', 'display_name' => '매니저', 'role' => 'manager', 'status' => 'active'];
$teacher = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];
$checks = 0;

function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT * FROM catalog_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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
        check(snapshot($pdo) === $before, $message . ' changed catalog or stock');
    }
}

Catalog::update($pdo, $owner, 'ci-1', '납땜 실납', '1학년 실습', ['소모', '전자'], 'm', '3', 'EF-1', '알파', null, true);
$row = $pdo->query("SELECT * FROM catalog_items WHERE id='ci-1'")->fetch(PDO::FETCH_ASSOC);
check($row['name'] === '납땜 실납' && $row['description'] === '1학년 실습' && $row['unit'] === 'm', 'Owner edit fields missing');
check((float) $row['min_stock'] === 3.0 && $row['edufine_number'] === 'EF-1' && $row['manufacturer'] === '알파', 'Owner edit optional fields missing');
check((int) $row['favorite'] === 1 && $row['type'] === 'consumable' && $row['qr_code'] === 'CAT:ci-1', 'Type or QR changed');
check(json_decode($row['tags'], true, 512, JSON_THROW_ON_ERROR) === ['소모', '전자'], 'Tags not stored as JSON list');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-1'")->fetchColumn() === 18.0, 'Edit changed stock quantity');
$log = $pdo->query("SELECT * FROM activity_logs WHERE action='update'")->fetch(PDO::FETCH_ASSOC);
$meta = json_decode($log['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($log['actor_id'] === 'owner' && $meta['before']['name'] === '납땜' && $meta['after']['name'] === '납땜 실납', 'Edit audit missing');

Catalog::update($pdo, $manager, 'ci-1', '납땜 실납', null, [], 'ea', '', null, null, null, false);
$row = $pdo->query("SELECT * FROM catalog_items WHERE id='ci-1'")->fetch(PDO::FETCH_ASSOC);
check($row['unit'] === 'ea' && $row['min_stock'] === null && (int) $row['favorite'] === 0 && $row['description'] === null, 'Manager clear optional fields failed');

reject($pdo, static fn () => Catalog::update($pdo, $teacher, 'ci-1', '금지', null, [], 'ea', null, null, null, null, false), 'Teacher edit');
reject($pdo, static fn () => Catalog::update($pdo, array_replace($owner, ['role' => 'student']), 'ci-1', '금지', null, [], 'ea', null, null, null, null, false), 'Student edit');
reject($pdo, static fn () => Catalog::update($pdo, array_replace($owner, ['status' => 'disabled']), 'ci-1', '금지', null, [], 'ea', null, null, null, null, false), 'Disabled owner edit');
reject($pdo, static fn () => Catalog::update($pdo, $owner, 'ci-1', '   ', null, [], 'ea', null, null, null, null, false), 'Empty name');
reject($pdo, static fn () => Catalog::update($pdo, $owner, 'missing', '없는 품목', null, [], 'ea', null, null, null, null, false), 'Missing item');
reject($pdo, static fn () => Catalog::update($pdo, $owner, 'ci-1', '납땜 실납', null, [], 'ea', '-1', null, null, null, false), 'Negative min stock');
reject($pdo, static fn () => Catalog::update($pdo, $owner, 'ci-1', '납땜 실납', null, [], 'ea', 'abc', null, null, null, false), 'Non-numeric min stock');
reject($pdo, static fn () => Catalog::update($pdo, $owner, 'ci-1', '납땜 실납', null, [], 'ea', INF, null, null, null, false), 'Infinite min stock');

$before = snapshot($pdo);
$pdo->exec("CREATE TRIGGER fail_edit_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test edit log failure'); END");
try {
    Catalog::update($pdo, $owner, 'ci-1', '롤백되어야 함', null, [], 'ea', null, null, null, null, false);
    throw new RuntimeException('Expected edit log failure');
} catch (PDOException) {
    check(snapshot($pdo) === $before && !$pdo->inTransaction(), 'Edit log failure did not roll back catalog');
}

echo "PASS: {$checks} catalog checks\n";
