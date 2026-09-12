<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Stock;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','now','now')");
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('store','창고','room','ROOM:store','now','now')");
foreach (['consumable', 'part', 'fixture', 'equipment'] as $type) {
    $pdo->prepare('INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?)')
        ->execute([$type, $type, $type, 'CAT:' . $type, 'now', 'now']);
    $pdo->prepare('INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES(?,?,?,?,?)')
        ->execute(['lot-' . $type, $type, 'room', 10, 'now']);
}
$actor = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];
$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];
$manager = ['id' => 'manager', 'display_name' => '매니저', 'role' => 'manager', 'status' => 'active'];
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
        $pdo->query('SELECT action, entity_id, meta_json FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM stock_issue_cancels ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
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
$pdo->exec('DROP TRIGGER fail_log');

function rejectRestock(PDO $pdo, array $actor, mixed $quantity, string $item = 'consumable', string $location = 'room', string $note = '보충'): void
{
    $before = snapshot($pdo);
    try {
        Stock::restock($pdo, $actor, $item, $location, $quantity, $note);
        throw new RuntimeException('Invalid restock was accepted');
    } catch (InvalidArgumentException) {
        check(snapshot($pdo) === $before, 'Rejected restock changed stock or logs');
    }
}

function rejectCancel(PDO $pdo, array $actor, string $item, string $logId, string $reason = '오입력'): void
{
    $before = snapshot($pdo);
    try {
        Stock::cancelIssue($pdo, $actor, $item, $logId, $reason);
        throw new RuntimeException('Invalid cancel was accepted');
    } catch (InvalidArgumentException) {
        check(snapshot($pdo) === $before, 'Rejected cancel changed stock or logs');
    }
}

Stock::restock($pdo, $owner, 'consumable', 'room', '2.5', '학기 초');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-consumable'")->fetchColumn() === 2.5, 'Restock add failed');
$restockLog = $pdo->query("SELECT * FROM activity_logs WHERE action='restock' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$restockMeta = json_decode($restockLog['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($restockLog['actor_id'] === 'owner' && $restockMeta['quantity'] === 2.5 && $restockMeta['note'] === '학기 초', 'Restock audit missing');

Stock::restock($pdo, $manager, 'consumable', 'store', '4', '새 위치');
$newLot = $pdo->query("SELECT * FROM stock_lots WHERE catalog_item_id='consumable' AND location_id='store'")->fetch(PDO::FETCH_ASSOC);
check($newLot && (float) $newLot['quantity'] === 4.0, 'Restock did not create a new lot');

Stock::restock($pdo, $owner, 'fixture', 'room', '3', '');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-fixture'")->fetchColumn() === 13.0, 'Fixture restock failed');

foreach (['', 'abc', '0', '-1', '1e999', '1e-999', '1e-300', null, [], INF, NAN] as $quantity) {
    rejectRestock($pdo, $owner, $quantity);
}
rejectRestock($pdo, $actor, '1');
rejectRestock($pdo, array_replace($owner, ['role' => 'student']), '1');
rejectRestock($pdo, array_replace($owner, ['status' => 'disabled']), '1');
rejectRestock($pdo, $owner, '1', 'equipment');
rejectRestock($pdo, $owner, '1', 'consumable', 'missing');
rejectRestock($pdo, $owner, '1', 'missing', 'room');

$before = snapshot($pdo);
$pdo->exec("CREATE TRIGGER fail_restock_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test restock log failure'); END");
try {
    Stock::restock($pdo, $owner, 'part', 'room', '1', '롤백');
    throw new RuntimeException('Expected restock log failure');
} catch (PDOException) {
    check(snapshot($pdo) === $before && !$pdo->inTransaction(), 'Restock log failure did not roll back stock');
}
$pdo->exec('DROP TRIGGER fail_restock_log');

Stock::issue($pdo, $actor, 'consumable', 'lot-consumable', '1.5', '오입력');
$issueQty = (float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-consumable'")->fetchColumn();
$issueLog = $pdo->query("SELECT * FROM activity_logs WHERE action='issue' AND entity_id='consumable' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
Stock::cancelIssue($pdo, $actor, 'consumable', $issueLog['id'], '수량 오입력');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-consumable'")->fetchColumn() === $issueQty + 1.5, 'Cancel did not restore quantity');
$cancel = $pdo->query("SELECT * FROM stock_issue_cancels WHERE issue_log_id=" . $pdo->quote($issueLog['id']))->fetch(PDO::FETCH_ASSOC);
check($cancel && (float) $cancel['quantity'] === 1.5 && $cancel['reason'] === '수량 오입력' && $cancel['actor_id'] === 'teacher', 'Cancel history row missing');
$cancelLog = $pdo->query("SELECT * FROM activity_logs WHERE action='cancel_issue' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cancelMeta = json_decode($cancelLog['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($cancelMeta['issue_log_id'] === $issueLog['id'] && $cancelMeta['quantity'] === 1.5, 'Cancel activity metadata missing');

rejectCancel($pdo, $actor, 'consumable', $issueLog['id']);
rejectCancel($pdo, $actor, 'consumable', $issueLog['id'], '   ');
rejectCancel($pdo, $actor, 'part', $issueLog['id']);
rejectCancel($pdo, $actor, 'consumable', 'missing');
rejectCancel($pdo, $actor, 'consumable', $restockLog['id']);
rejectCancel($pdo, array_replace($actor, ['role' => 'student']), 'consumable', $issueLog['id']);
rejectCancel($pdo, array_replace($actor, ['status' => 'disabled']), 'consumable', $issueLog['id']);

Stock::issue($pdo, $actor, 'part', 'lot-part', '1', '취소 롤백');
$partIssue = $pdo->query("SELECT * FROM activity_logs WHERE action='issue' AND entity_id='part' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$before = snapshot($pdo);
$pdo->exec("CREATE TRIGGER fail_cancel_log BEFORE INSERT ON activity_logs WHEN NEW.action='cancel_issue' BEGIN SELECT RAISE(ABORT, 'test cancel log failure'); END");
try {
    Stock::cancelIssue($pdo, $manager, 'part', $partIssue['id'], '롤백');
    throw new RuntimeException('Expected cancel log failure');
} catch (PDOException) {
    check(snapshot($pdo) === $before && !$pdo->inTransaction(), 'Cancel log failure did not roll back stock');
}
$pdo->exec('DROP TRIGGER fail_cancel_log');
Stock::cancelIssue($pdo, $manager, 'part', $partIssue['id'], '담당 취소');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-part'")->fetchColumn() === 9.0, 'Manager cancel restore failed');

echo "PASS: {$checks} stock checks\n";
