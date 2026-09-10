<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Stock;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','now','now')");
foreach (['consumable', 'part', 'fixture', 'equipment'] as $type) {
    $pdo->prepare('INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?)')
        ->execute([$type, $type, $type, 'CAT:' . $type, 'now', 'now']);
    $pdo->prepare('INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES(?,?,?,?,?)')
        ->execute(['lot-' . $type, $type, 'room', 10, 'now']);
}
$actor = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];
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
        $pdo->query('SELECT * FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM activity_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}
function reject(PDO $pdo, array $actor, mixed $quantity, string $purpose = '수업', string $item = 'consumable', string $lot = 'lot-consumable'): void
{
    $before = snapshot($pdo);
    try {
        Stock::issue($pdo, $actor, $item, $lot, $quantity, $purpose);
        throw new RuntimeException('Invalid issue was accepted');
    } catch (InvalidArgumentException) {
        check(snapshot($pdo) === $before, 'Rejected issue changed stock or logs');
    }
}

Stock::issue($pdo, $actor, 'consumable', 'lot-consumable', '2.5', '실습');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-consumable'")->fetchColumn() === 7.5, 'Fractional issue failed');
$log = $pdo->query('SELECT * FROM activity_logs')->fetch(PDO::FETCH_ASSOC);
$meta = json_decode($log['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($log['actor_id'] === 'teacher' && $log['entity_id'] === 'consumable' && $meta['quantity'] === 2.5 && $meta['purpose'] === '실습', 'Audit metadata missing');
foreach (['', 'abc', '0', '-1', '8', '1e999', '1e-999', '1e-300', null, [], INF, NAN] as $quantity) {
    reject($pdo, $actor, $quantity);
}
reject($pdo, $actor, '1', '   ');
reject($pdo, array_replace($actor, ['role' => 'student']), '1');
reject($pdo, array_replace($actor, ['status' => 'disabled']), '1');
reject($pdo, $actor, '1', '수업', 'part', 'lot-consumable');
reject($pdo, $actor, '1', '수업', 'consumable', 'missing');
foreach (['fixture', 'equipment'] as $type) {
    reject($pdo, $actor, '1', '수업', $type, 'lot-' . $type);
}
Stock::issue($pdo, $actor, 'consumable', 'lot-consumable', '7.5', '전량 사용');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-consumable'")->fetchColumn() === 0.0, 'Exact depletion failed');
reject($pdo, $actor, '1');
Stock::issue($pdo, $actor, 'part', 'lot-part', '1', '부품 사용');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-part'")->fetchColumn() === 9.0, 'Part issue failed');

$before = snapshot($pdo);
$pdo->exec("CREATE TRIGGER fail_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test log failure'); END");
try {
    Stock::issue($pdo, $actor, 'part', 'lot-part', '1', '롤백 확인');
    throw new RuntimeException('Expected log failure');
} catch (PDOException) {
    check(snapshot($pdo) === $before && !$pdo->inTransaction(), 'Log failure did not roll back stock');
}
echo "PASS: {$checks} stock checks\n";
