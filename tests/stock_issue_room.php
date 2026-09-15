<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Stock;

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

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT * FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_id, meta_json FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM stock_issue_cancels ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function reject(callable $fn, PDO $pdo, string $message): void
{
    $before = snapshot($pdo);
    try {
        $fn();
        throw new RuntimeException($message . ' was accepted');
    } catch (InvalidArgumentException) {
        check(snapshot($pdo) === $before, $message . ' changed stock or logs');
    }
}

check(Stock::parseClassMemo(null) === '' && Stock::parseClassMemo('  ') === '', 'Empty class memo is blank');
check(Stock::parseClassMemo(' 2학년 전자회로 ') === '2학년 전자회로', 'Class memo trims');
check(Stock::issueRoomFromRequest(['room' => ' elec ']) === 'elec', 'Request parser keeps room');
check(Stock::issueRoomFromRequest(['issue_room' => 'weld'], 'issue_room') === 'weld', 'Request parser reads issue_room');
check(Stock::issueRoomFromRequest(['room' => ''], 'room') === null, 'Blank room filter is null');

reject(static fn () => Stock::parseClassMemo(['x']), memoryDb(), 'Array class memo');
reject(static fn () => Stock::parseClassMemo("a\nb"), memoryDb(), 'Newline class memo');
reject(static fn () => Stock::parseClassMemo(str_repeat('가', 201)), memoryDb(), 'Overlong class memo');

$pdo = memoryDb();
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,'LOC:bldg','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('elec','전자실습실','room','bldg','E-201','LOC:elec','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('cab','계측기 캐비닛','storage','elec',null,'LOC:cab','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('weld','용접실','room','bldg','W-103','LOC:weld','t','t')");
$pdo->prepare('INSERT INTO catalog_items(id,name,type,unit,min_stock,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')
    ->execute(['solder', '납땜', 'consumable', 'm', 5, 'CAT:solder', 't', 't']);
$pdo->prepare('INSERT INTO catalog_items(id,name,type,unit,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
    ->execute(['wire', '전선', 'consumable', 'm', 'CAT:wire', 't', 't']);
$pdo->prepare('INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,lot_code,expires_at,updated_at) VALUES(?,?,?,?,?,?,?)')
    ->execute(['lot-solder', 'solder', 'cab', 10, 'SN-1', '2028-11-30', 't']);
$pdo->prepare('INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES(?,?,?,?,?)')
    ->execute(['lot-wire', 'wire', 'elec', 8, 't']);

$teacher = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];
$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];

Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '2', '5교시 실습', 'elec', '2학년 전자회로');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-solder'")->fetchColumn() === 8.0, 'Issue with room still deducts');
$lot = $pdo->query("SELECT lot_code, expires_at FROM stock_lots WHERE id='lot-solder'")->fetch(PDO::FETCH_ASSOC);
check($lot['lot_code'] === 'SN-1' && $lot['expires_at'] === '2028-11-30', 'Issue must not clear lot/expiry');
$issueLog = $pdo->query("SELECT * FROM activity_logs WHERE action='issue' AND entity_id='solder' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$meta = json_decode((string) $issueLog['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($meta['room_id'] === 'elec' && $meta['room_name'] === '전자실습실', 'Issue meta stores room');
check($meta['class_memo'] === '2학년 전자회로' && $meta['purpose'] === '5교시 실습', 'Issue meta stores class memo');
check(str_contains((string) $issueLog['summary'], '분출') && str_contains((string) $issueLog['summary'], '전자실습실'), 'Issue summary uses 분출 and room');

Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '방과후', 'weld', '');
Stock::issue($pdo, $teacher, 'wire', 'lot-wire', '1', '수업', 'elec', '1학년');

$elecLogs = Stock::itemLogs($pdo, 'solder', 'elec');
check(count($elecLogs) === 1 && str_contains((string) $elecLogs[0]['summary'], '전자실습실'), 'Item history filters by room');
$allLogs = Stock::itemLogs($pdo, 'solder', null);
check(count($allLogs) >= 2, 'Unfiltered item history keeps all actions');
$weldHist = Stock::issueHistory($pdo, 'weld');
check(count($weldHist) === 1 && json_decode((string) $weldHist[0]['meta_json'], true)['room_id'] === 'weld', 'Board history filters by room');
$elecHist = Stock::issueHistory($pdo, 'elec');
check(count($elecHist) === 2, 'Board history includes other items in that room');

reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'missing', ''), $pdo, 'Unknown room');
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'cab', ''), $pdo, 'Storage is not a room');
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'bldg', ''), $pdo, 'Building is not a room');
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', ['elec'], ''), $pdo, 'Array room');

Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '호환');
$compat = $pdo->query("SELECT meta_json FROM activity_logs WHERE action='issue' AND entity_id='solder' ORDER BY rowid DESC LIMIT 1")->fetchColumn();
$compatMeta = json_decode((string) $compat, true, 512, JSON_THROW_ON_ERROR);
check(!isset($compatMeta['room_id']), 'Engine still allows issue without room');

$issueQty = (float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-solder'")->fetchColumn();
Stock::cancelIssue($pdo, $teacher, 'solder', $issueLog['id'], '수량 오입력');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-solder'")->fetchColumn() === $issueQty + 2.0, 'Cancel still restores quantity');
$cancelLog = $pdo->query("SELECT * FROM activity_logs WHERE action='cancel_issue' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cancelMeta = json_decode((string) $cancelLog['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($cancelMeta['room_id'] === 'elec' && $cancelMeta['issue_log_id'] === $issueLog['id'], 'Cancel copies room onto history');
$elecAfterCancel = Stock::itemLogs($pdo, 'solder', 'elec');
$elecActions = array_column($elecAfterCancel, 'action');
check(in_array('issue', $elecActions, true) && in_array('cancel_issue', $elecActions, true), 'Room filter keeps cancel for that room');

$showTpl = (string) file_get_contents($root . '/templates/items/show.php');
check(str_contains($showTpl, 'name="room_id"') && str_contains($showTpl, 'name="class_memo"'), 'Issue form has room and class memo');
check(str_contains($showTpl, '분출') && str_contains($showTpl, "App::url('items/issue')"), 'Issue form uses 분출 copy');
check(str_contains($showTpl, 'Csrf::field()') && str_contains($showTpl, 'method="post"'), 'Issue form is POST+CSRF');
check(str_contains($showTpl, 'Auth::canLoan($user)'), 'Issue form is canLoan-gated');
check(str_contains($showTpl, 'name="room"') && str_contains($showTpl, '이력 걸러보기'), 'Item show filters history by room');
check(str_contains($showTpl, 'items/cancel-issue') && str_contains($showTpl, 'name="issue_log_id"'), 'Cancel-issue path stays on item show');
check(str_contains($showTpl, 'id="issue"') && str_contains($showTpl, 'id="restock"'), 'Item show keeps #issue and #restock anchors for the board');
check(str_contains($showTpl, 'items/lot') && str_contains($showTpl, 'name="lot_code"') && str_contains($showTpl, 'name="expires_at"'), '#48 lot/expiry fields stay');
check(str_contains($showTpl, 'badge overdue') && str_contains($showTpl, '부족'), '#48 shortage badge stays on item show');

$matTpl = (string) file_get_contents($root . '/templates/materials/index.php');
check(str_contains($matTpl, 'name="issue_room"') && str_contains($matTpl, '최근 분출'), 'Materials board filters recent 분출 by room');
check(str_contains($matTpl, 'material_actions.php'), 'Materials board rows link to 분출/재입고');
check(!str_contains($matTpl, 'Csrf::field()'), 'Materials history stays GET/read');
check(str_contains($matTpl, 'badge overdue') && str_contains($matTpl, 'is-low-stock'), '#48 shortage badge stays on materials board');

$ctl = (string) file_get_contents($root . '/app/Controllers/ItemController.php');
check(preg_match('/function issue\(\): void\s*\{.*?Csrf::requirePost\(\)/s', $ctl) === 1, 'Issue is POST+CSRF');
check(preg_match('/function issue\(\): void\s*\{.*?Auth::canLoan\(\$user\)/s', $ctl) === 1, 'Issue requires canLoan');
check(str_contains($ctl, 'room_id') && str_contains($ctl, 'class_memo'), 'Issue controller passes room and memo');
check(preg_match('/function cancelIssue\(\): void\s*\{.*?Csrf::requirePost\(\)/s', $ctl) === 1, 'Cancel-issue stays POST+CSRF');

$matCtl = (string) file_get_contents($root . '/app/Controllers/MaterialController.php');
check(str_contains($matCtl, 'Stock::issueHistory') && str_contains($matCtl, 'issue_room'), 'Materials board loads room-filtered issue history');
check(str_contains($matCtl, 'MaterialBoard::waitingRestock'), 'Materials board loads 재입고 대기');
check(!str_contains($matCtl, 'Csrf::'), 'Materials board stays read-only');

$newTpl = (string) file_get_contents($root . '/templates/items/new.php');
check(str_contains($newTpl, 'name="unit"') && str_contains($newTpl, 'name="min_stock"') && str_contains($newTpl, 'name="lot_code"'), '#48 create fields stay');

$loanInbox = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
$desk = (string) file_get_contents($root . '/app/Controllers/DeskController.php');
$repair = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
check(str_contains($loanInbox, 'function mine') && str_contains($desk, 'function index') && str_contains($repair, 'function updateStatus'), 'Loans/mine, desk, repair stay present');

echo "PASS: {$checks} stock-issue-room checks\n";
