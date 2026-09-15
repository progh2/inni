<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
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

function reject(callable $fn, string $message): void
{
    try {
        $fn();
        throw new RuntimeException($message . ' was accepted');
    } catch (InvalidArgumentException) {
        check(true, $message);
    }
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT * FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_id, meta_json FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM stock_issue_cancels ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function memoryDb(): PDO
{
    global $root;
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,'LOC:bldg','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('elec','전자실습실','room','bldg','E-201','LOC:elec','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('weld','용접실','room','bldg','W-103','LOC:weld','t','t')");
    $pdo->exec("INSERT INTO locations(id,name,kind,parent_id,qr_code,created_at,updated_at) VALUES('cab','계측기 캐비닛','storage','elec','LOC:cab','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,unit,qr_code,created_at,updated_at) VALUES('solder','납땜','consumable','m','CAT:solder','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,unit,qr_code,created_at,updated_at) VALUES('res','저항','part','ea','CAT:res','t','t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-solder','solder','cab',20,'t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-res','res','elec',8,'t')");
    return $pdo;
}

$pdo = memoryDb();
$teacher = ['id' => 'teacher', 'display_name' => '이수업', 'role' => 'teacher', 'status' => 'active'];
$owner = ['id' => 'owner', 'display_name' => '김담당', 'role' => 'owner', 'status' => 'active'];
$student = ['id' => 'student', 'display_name' => '학생', 'role' => 'student', 'status' => 'active'];

check(Auth::canLoan($teacher) && Auth::canLoan($owner) && !Auth::canLoan($student), 'Issue stays on canLoan roles');

Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '2', '5교시 실습', 'elec');
$qty = (float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-solder'")->fetchColumn();
check($qty === 18.0, 'Issue without memo still subtracts stock');
$log = $pdo->query("SELECT * FROM activity_logs WHERE action='issue' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$meta = json_decode((string) $log['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check(
    $meta['room_id'] === 'elec'
    && $meta['room_name'] === '전자실습실'
    && $meta['purpose'] === '5교시 실습'
    && !isset($meta['class_memo'])
    && str_contains((string) $log['summary'], '분출')
    && str_contains((string) $log['summary'], '전자실습실'),
    'Issue stores required room and omits empty memo'
);

Stock::issue($pdo, $owner, 'solder', 'lot-solder', '1.5', '프로젝트', 'weld', '2학년 3반');
$logMemo = $pdo->query("SELECT * FROM activity_logs WHERE action='issue' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$metaMemo = json_decode((string) $logMemo['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check(
    $metaMemo['room_id'] === 'weld'
    && $metaMemo['class_memo'] === '2학년 3반'
    && str_contains((string) $logMemo['summary'], '2학년 3반'),
    'Optional class memo is stored when present'
);

Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '  방과후  ', 'elec', '  ');
$trimLog = $pdo->query("SELECT * FROM activity_logs WHERE action='issue' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$trimMeta = json_decode((string) $trimLog['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($trimMeta['purpose'] === '방과후' && !isset($trimMeta['class_memo']), 'Blank memo is treated as omitted');

foreach (['', '   '] as $room) {
    $before = snapshot($pdo);
    reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', $room), 'Empty room');
    check(snapshot($pdo) === $before, 'Empty room does not change stock');
}

$before = snapshot($pdo);
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'missing'), 'Unknown room');
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'cab'), 'Storage is not a room');
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'bldg'), 'Building is not a room');
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'elec', str_repeat('가', 201)), 'Overlong class memo');
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'elec', "줄\n바꿈"), 'Control class memo');
reject(static fn () => Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '수업', 'elec', ['x']), 'Array class memo');
check(snapshot($pdo) === $before, 'Rejected room/memo cases leave stock unchanged');

$before = snapshot($pdo);
reject(static fn () => Stock::issue($pdo, $student, 'solder', 'lot-solder', '1', '수업', 'elec'), 'Student issue');
reject(static fn () => Stock::issue($pdo, array_replace($teacher, ['status' => 'disabled']), 'solder', 'lot-solder', '1', '수업', 'elec'), 'Disabled issue');
reject(static fn () => Stock::issue($pdo, array_replace($teacher, ['status' => 'pending']), 'solder', 'lot-solder', '1', '수업', 'elec'), 'Pending issue');
check(snapshot($pdo) === $before, 'Permission rejects do not change stock');

$all = Stock::catalogHistory($pdo, 'solder');
$issueLogs = array_values(array_filter($all, static fn (array $row): bool => $row['action'] === 'issue'));
check(count($issueLogs) === 3, 'Unfiltered history includes every 분출');

$elec = Stock::catalogHistory($pdo, 'solder', ['room' => 'elec']);
$weld = Stock::catalogHistory($pdo, 'solder', ['room' => 'weld']);
$missing = Stock::catalogHistory($pdo, 'solder', ['room' => 'missing']);
check(count($elec) === 2 && count($weld) === 1 && $missing === [], 'History filter is by stored room');
foreach ($elec as $row) {
    $rowMeta = json_decode((string) $row['meta_json'], true, 512, JSON_THROW_ON_ERROR);
    check($rowMeta['room_id'] === 'elec', 'Elec filter only returns elec issues');
}
check(Stock::historyFiltersFromRequest(['room' => ' weld ']) === ['room' => 'weld'], 'GET room filter trims');
check(Stock::historyFiltersFromRequest(['room' => '']) === ['room' => null], 'Empty GET room is all history');
check(Stock::historyFiltersFromRequest(['room' => ['elec']]) === ['room' => null], 'Non-string room filter is ignored');

$issueId = (string) $pdo->query("SELECT id FROM activity_logs WHERE action='issue' AND json_extract(meta_json,'$.room_id')='weld' ORDER BY rowid DESC LIMIT 1")->fetchColumn();
Stock::cancelIssue($pdo, $teacher, 'solder', $issueId, '수량 오입력');
$cancelLog = $pdo->query("SELECT * FROM activity_logs WHERE action='cancel_issue' ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cancelMeta = json_decode((string) $cancelLog['meta_json'], true, 512, JSON_THROW_ON_ERROR);
check($cancelMeta['room_id'] === 'weld' && $cancelMeta['class_memo'] === '2학년 3반', 'Cancel copies room and memo');
$weldAfter = Stock::catalogHistory($pdo, 'solder', ['room' => 'weld']);
$weldActions = array_values(array_map(static fn (array $row): string => (string) $row['action'], $weldAfter));
check($weldActions === ['cancel_issue', 'issue'], 'Room filter includes matching cancel');

Stock::restock($pdo, $owner, 'solder', 'cab', '1', '보충');
$filtered = Stock::catalogHistory($pdo, 'solder', ['room' => 'elec']);
foreach ($filtered as $row) {
    check(in_array($row['action'], ['issue', 'cancel_issue'], true), 'Room filter hides restock');
}

$showTpl = (string) file_get_contents($root . '/templates/items/show.php');
check(str_contains($showTpl, 'name="room_id"') && str_contains($showTpl, '실 선택'), 'Issue form has required room select');
check(str_contains($showTpl, 'name="class_memo"') && str_contains($showTpl, '수업 메모'), 'Issue form has optional class memo');
check(str_contains($showTpl, '>분출<') && str_contains($showTpl, 'Auth::canLoan($user)'), 'Issue submit uses 분출 and canLoan');
check(str_contains($showTpl, 'id="history-room"') && str_contains($showTpl, 'name="room"'), 'Item history can filter by room');
check(str_contains($showTpl, 'Csrf::field()') && str_contains($showTpl, "App::url('items/issue')"), 'Issue form stays POST+CSRF');

$ctl = (string) file_get_contents($root . '/app/Controllers/ItemController.php');
check(preg_match('/function issue\(\): void\s*\{.*?Auth::canLoan\(\$user\)/s', $ctl) === 1, 'Controller issue requires canLoan');
check(preg_match('/function issue\(\): void\s*\{.*?Csrf::requirePost\(\)/s', $ctl) === 1, 'Controller issue is POST+CSRF');
check(str_contains($ctl, "\$_POST['room_id']") && str_contains($ctl, "\$_POST['class_memo']"), 'Controller passes room and memo');
check(str_contains($ctl, 'Stock::catalogHistory') && str_contains($ctl, 'historyFiltersFromRequest'), 'Item history uses room filter helper');
check(str_contains($ctl, 'AssetBoard::rooms'), 'Issue form rooms come from existing room list');

$stockSrc = (string) file_get_contents($root . '/app/Stock.php');
check(str_contains($stockSrc, "kind'] ?? '') !== 'room'") || str_contains($stockSrc, "!== 'room'"), 'Room must be kind=room');
check(str_contains($stockSrc, 'parseClassMemo') && str_contains($stockSrc, 'requireRoom'), 'Issue validates room and memo');

$moreTpl = (string) file_get_contents($root . '/templates/more/index.php');
$matTpl = (string) file_get_contents($root . '/templates/materials/index.php');
$desk = (string) file_get_contents($root . '/app/Controllers/DeskController.php');
$repair = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
check(
    str_contains($moreTpl, '대여 데스크')
    && str_contains($matTpl, '실험실습재료')
    && str_contains($desk, 'function index')
    && str_contains($repair, 'function updateStatus'),
    'Desk, repair, and materials board stay present'
);
check(!str_contains($matTpl, 'items/issue'), 'Materials board has no #50 분출 CTA');

echo "PASS: {$checks} stock-issue checks\n";
