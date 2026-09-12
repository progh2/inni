<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Alert;
use Inni\App;
use Inni\Catalog;
use Inni\Inventory;
use Inni\Loan;
use Inni\Stock;
use Inni\Telegram;

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

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

function appConfig(array $telegram): void
{
    $ref = new ReflectionClass(App::class);
    $prop = $ref->getProperty('config');
    $prop->setAccessible(true);
    $prop->setValue(null, [
        'app_name' => 'inni',
        'school_name' => 'alert-test',
        'timezone' => 'UTC',
        'demo_login' => false,
        'telegram' => $telegram,
    ]);
}

/**
 * @return list<array{url: string, fields: array<string, mixed>}>
 */
function seedStock(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','LOC:room','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,unit,min_stock,qr_code,created_at,updated_at) VALUES('solder','납땜 실납','consumable','m',5,'CAT:solder','t','t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','solder','room',6,'t')");
}

function seedLoan(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','LOC:room','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    $pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-1','eq','스코프 #1','전장-1','available','room','AST:1','t','t')");
    $pdo->exec("INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES('owner','o@t','owner','owner','active','t','t')");
}

function invokeTelegramSave(string $httpMethod, array $post, ?string $sessionToken, string $role): array
{
    global $root;
    $tmp = sys_get_temp_dir() . '/inni-alert-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $dbPath = $tmp . '/inni.sqlite';
    $resultFile = $tmp . '/result.json';
    $payload = [
        'root' => $root,
        'db' => $dbPath,
        'method' => $httpMethod,
        'post' => $post,
        'csrf' => $sessionToken,
        'role' => $role,
        'result' => $resultFile,
    ];
    file_put_contents($tmp . '/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));
    file_put_contents($tmp . '/run.php', <<<'PHP'
<?php
declare(strict_types=1);
$p = json_decode(file_get_contents(__DIR__ . '/payload.json'), true, 512, JSON_THROW_ON_ERROR);
require $p['root'] . '/app/bootstrap.php';

use Inni\App;
use Inni\Csrf;
use Inni\Controllers\SettingsController;
use Inni\Database;

$ref = new ReflectionClass(App::class);
$rootProp = $ref->getProperty('root');
$rootProp->setAccessible(true);
$rootProp->setValue(null, $p['root']);
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, [
    'app_name' => 'inni',
    'school_name' => 'alert-csrf',
    'timezone' => 'UTC',
    'demo_login' => true,
    'session_name' => 'inni_alert_csrf',
    'base_url' => 'http://localhost',
    'db_path' => $p['db'],
    'telegram' => ['bot_token' => '', 'default_chat_id' => ''],
]);

$pdo = new PDO('sqlite:' . $p['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($p['root'] . '/sql/schema.sql'));
$pdo->prepare(
    'INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)'
)->execute(['user-1', 'u@t', 'user', $p['role'], 'active', 't', 't']);
$dbRef = new ReflectionClass(Database::class);
$dbProp = $dbRef->getProperty('pdo');
$dbProp->setAccessible(true);
$dbProp->setValue(null, $pdo);

$_SERVER['REQUEST_METHOD'] = $p['method'];
$_POST = $p['post'];
$_SESSION = ['user_id' => 'user-1'];
if (is_string($p['csrf'])) {
    $_SESSION[Csrf::SESSION_KEY] = $p['csrf'];
}

register_shutdown_function(static function () use ($p): void {
    $settings = [];
    if (is_file($p['db'])) {
        $read = new PDO('sqlite:' . $p['db']);
        $settings = $read->query('SELECT key, value FROM settings ORDER BY key')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    file_put_contents($p['result'], json_encode([
        'status' => http_response_code() ?: 200,
        'body' => (string) ob_get_contents(),
        'settings' => $settings,
    ], JSON_THROW_ON_ERROR));
});

ob_start();
(new SettingsController())->saveTelegram();
PHP);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/run.php');
    exec($cmd . ' 2>&1', $out, $code);
    $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        throw new RuntimeException('telegram save runner failed: ' . implode("\n", $out));
    }
    $decoded['exit'] = $code;
    return $decoded;
}

appConfig(['bot_token' => '', 'default_chat_id' => '']);
check(Telegram::configStatus() === Telegram::STATUS_EMPTY, 'empty token is empty');
check(Telegram::isReady() === false, 'empty token is not ready');

appConfig(['bot_token' => '   ', 'default_chat_id' => '123']);
check(Telegram::isReady() === false, 'whitespace token is not ready');

$calls = [];
Telegram::setHttpHandler(static function (string $url, array $fields) use (&$calls): array {
    $calls[] = ['url' => $url, 'fields' => $fields];
    return ['ok' => true];
});
$emptySend = Telegram::sendMessage('123', 'hello');
check($emptySend['ok'] === false && $emptySend['error'] === 'telegram_token_empty', 'send fails closed without token');
check($calls === [], 'empty token must not call Telegram HTTP');

appConfig(['bot_token' => 'test-bot-token', 'default_chat_id' => '']);
check(Telegram::isReady() === true, 'non-empty fake token is ready');
$noChat = Telegram::sendMessage('', 'hello');
check($noChat['ok'] === false && $noChat['error'] === 'telegram_chat_empty', 'send fails closed without chat id');
check($calls === [], 'empty chat must not call Telegram HTTP');

$okSend = Telegram::sendMessage('4242', 'ping');
check($okSend['ok'] === true, 'stubbed send succeeds');
check(count($calls) === 1, 'ready token+chat hits stub once');
check(str_contains($calls[0]['url'], 'test-bot-token') && str_contains($calls[0]['url'], '/sendMessage'), 'stub URL uses fake token path');
check($calls[0]['fields']['chat_id'] === '4242' && $calls[0]['fields']['text'] === 'ping', 'stub receives chat and text');
check(!str_contains($calls[0]['url'], '123456:ABC'), 'no real-looking token in stub URL');

appConfig(['bot_token' => 'test-bot-token', 'default_chat_id' => '999']);
$pdo = memoryDb();
seedStock($pdo);
$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');

$calls = [];
Telegram::setHttpHandler(static function (string $url, array $fields) use (&$calls): array {
    $calls[] = ['url' => $url, 'fields' => $fields];
    return ['ok' => true];
});

Stock::issue($pdo, $teacher, 'solder', 'lot-1', 1, '수업');
check(Alert::lowStockItem($pdo, 'solder') === null, 'qty 5 is not below min 5');
check($calls === [], 'stock at min_stock does not notify');

Stock::issue($pdo, $teacher, 'solder', 'lot-1', 1, '수업');
$low = Alert::lowStockItem($pdo, 'solder');
check(is_array($low) && (float) $low['qty'] === 4.0, 'qty 4 is below min 5');
check(count($calls) === 1, 'crossing below min_stock sends once');
check(str_contains((string) $calls[0]['fields']['text'], '재고 부족'), 'low-stock text mentions 재고 부족');
check((string) $calls[0]['fields']['chat_id'] === '999', 'low-stock uses config default chat id');

Stock::issue($pdo, $teacher, 'solder', 'lot-1', 1, '수업');
check(count($calls) === 1, 'still-low item is not sent again');

Stock::restock($pdo, $owner, 'solder', 'room', 3, '입고');
check(Alert::lowStockItem($pdo, 'solder') === null, 'restock above min clears low stock');
$dispatched = (int) $pdo->query("SELECT COUNT(*) FROM alert_dispatches WHERE event_key = 'low_stock'")->fetchColumn();
check($dispatched === 0, 'recovered item clears dispatch');

Stock::issue($pdo, $teacher, 'solder', 'lot-1', 3, '수업');
check(count($calls) === 2, 'falling below min again sends a new alert');

Alert::saveSettings($pdo, $manager, '-1001', [
    Alert::EVENT_LOW_STOCK => false,
    Alert::EVENT_OVERDUE_LOAN => true,
]);
check(Alert::chatId($pdo) === '-1001', 'settings chat id wins over config default');
check(Alert::eventEnabled($pdo, Alert::EVENT_LOW_STOCK) === false, 'low_stock can be turned off');
Stock::restock($pdo, $owner, 'solder', 'room', 5, '채움');
Stock::issue($pdo, $teacher, 'solder', 'lot-1', 5, '다시');
check(count($calls) === 2, 'disabled low_stock event does not send');

try {
    Alert::saveSettings($pdo, $teacher, '1', [Alert::EVENT_LOW_STOCK => true]);
    throw new RuntimeException('teacher telegram save was accepted');
} catch (InvalidArgumentException $e) {
    check(str_contains($e->getMessage(), '권한'), 'teacher cannot save telegram settings');
}

try {
    Alert::saveSettings($pdo, $owner, "1\n2", [Alert::EVENT_LOW_STOCK => true]);
    throw new RuntimeException('newline chat id was accepted');
} catch (InvalidArgumentException) {
    check(true, 'newline chat id is rejected');
}

try {
    Alert::saveSettings($pdo, $owner, '123:FAKESECRET_a2b3c4d5e6f7g8h9i0j1', [Alert::EVENT_LOW_STOCK => true]);
    throw new RuntimeException('token-like chat id was accepted');
} catch (InvalidArgumentException) {
    check(true, 'token-like chat id is rejected');
}

$posted = [
    Alert::EVENT_LOW_STOCK => true,
    Alert::EVENT_OVERDUE_LOAN => true,
    'bot_token' => '123:SHOULD-NOT-STORE',
];
Alert::saveSettings($pdo, $owner, '777', $posted);
$stored = $pdo->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
check(!isset($stored['telegram_bot_token']) && !isset($stored['bot_token']), 'posted token is not a settings key');
check(!in_array('123:SHOULD-NOT-STORE', $stored, true), 'posted token is not stored as a setting value');
check(Telegram::botToken() === 'test-bot-token', 'token still comes from config only');

$loanDb = memoryDb();
seedLoan($loanDb);
Alert::saveSettings($loanDb, $owner, '888', [
    Alert::EVENT_LOW_STOCK => true,
    Alert::EVENT_OVERDUE_LOAN => true,
]);
$calls = [];
Telegram::setHttpHandler(static function (string $url, array $fields) use (&$calls): array {
    $calls[] = ['url' => $url, 'fields' => $fields];
    return ['ok' => true];
});
$past = gmdate('c', time() - 3600);
$loanId = Loan::checkout($loanDb, $owner, 'ast-1', '이수업', null, '실습', $past);
$status = (string) $loanDb->query("SELECT status FROM loans WHERE id = " . $loanDb->quote($loanId))->fetchColumn();
check($status === 'overdue', 'past-due checkout is marked overdue');
check(count($calls) === 1, 'newly overdue loan sends once');
check(str_contains((string) $calls[0]['fields']['text'], '연체'), 'overdue text mentions 연체');

Alert::refreshOverdue($loanDb);
check(count($calls) === 1, 'already notified overdue is not sent again');

Loan::checkin($loanDb, $owner, $loanId);
$left = (int) $loanDb->query("SELECT COUNT(*) FROM alert_dispatches WHERE event_key = 'overdue_loan'")->fetchColumn();
check($left === 0, 'return clears overdue dispatch');

$loanDb->exec("UPDATE assets SET status = 'available' WHERE id = 'ast-1'");
$future = gmdate('c', time() + 7200);
$openId = Loan::checkout($loanDb, $owner, 'ast-1', '이수업', null, '실습', $future);
check((string) $loanDb->query("SELECT status FROM loans WHERE id = " . $loanDb->quote($openId))->fetchColumn() === 'active', 'future due stays active');
check(count($calls) === 1, 'active future loan does not notify');
Alert::refreshOverdue($loanDb, gmdate('c', time() + 10800));
check(count($calls) === 2, 'marking overdue later sends once');

appConfig(['bot_token' => '', 'default_chat_id' => '888']);
$calls = [];
Telegram::setHttpHandler(static function (string $url, array $fields) use (&$calls): array {
    $calls[] = ['url' => $url, 'fields' => $fields];
    return ['ok' => true];
});
$emptyDb = memoryDb();
seedStock($emptyDb);
Stock::issue($emptyDb, $teacher, 'solder', 'lot-1', 2, '수업');
check(Alert::listLowStock($emptyDb) !== [], 'low stock list works without telegram');
check($calls === [], 'empty token never hits Telegram HTTP on stock trigger');

appConfig(['bot_token' => 'test-bot-token', 'default_chat_id' => '1']);
$invDb = memoryDb();
$invDb->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','전자실','room','LOC:inv','t','t')");
$invDb->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','스코프','equipment','CAT:inv','t','t')");
$invDb->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-inv','eq','스코프','M-1','available','room','AST:inv','t','t')");
$invDb->exec("INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES('owner','o@t','owner','owner','active','t','t')");
Alert::saveSettings($invDb, $owner, '1', [
    Alert::EVENT_LOW_STOCK => true,
    Alert::EVENT_OVERDUE_LOAN => true,
]);
$calls = [];
Telegram::setHttpHandler(static function (string $url, array $fields) use (&$calls): array {
    $calls[] = ['url' => $url, 'fields' => $fields];
    return ['ok' => true];
});
$started = Inventory::start($invDb, $owner, 'room');
Inventory::finish($invDb, $owner, (string) $started['id']);
check($calls === [], 'inventory finish is not a telegram trigger');

$catalogDb = memoryDb();
seedStock($catalogDb);
Alert::saveSettings($catalogDb, $owner, '5', [
    Alert::EVENT_LOW_STOCK => true,
    Alert::EVENT_OVERDUE_LOAN => false,
]);
$calls = [];
Telegram::setHttpHandler(static function (string $url, array $fields) use (&$calls): array {
    $calls[] = ['url' => $url, 'fields' => $fields];
    return ['ok' => true];
});
Catalog::update($catalogDb, $owner, 'solder', '납땜 실납', null, [], 'm', 10, null, null, null, false);
check(count($calls) === 1, 'raising min_stock above qty sends low-stock');

$sessionToken = bin2hex(random_bytes(32));
$saved = invokeTelegramSave('POST', [
    'csrf_token' => $sessionToken,
    'chat_id' => '555',
    'event_low_stock' => '1',
    'bot_token' => '123:POSTED-TOKEN',
], $sessionToken, 'manager');
check(in_array((int) $saved['status'], [200, 302], true), 'manager CSRF POST saves telegram settings');
$savedMap = [];
foreach ($saved['settings'] as $row) {
    $savedMap[$row['key']] = $row['value'];
}
check(($savedMap['telegram_chat_id'] ?? '') === '555', 'manager saved chat id');
check(($savedMap['telegram_event_low_stock'] ?? '') === '1', 'manager enabled low_stock');
check(($savedMap['telegram_event_overdue_loan'] ?? '') === '0', 'unchecked overdue is stored off');
check(!isset($savedMap['bot_token']) && !in_array('123:POSTED-TOKEN', $savedMap, true), 'controller ignores posted bot token');

$denied = invokeTelegramSave('POST', [
    'csrf_token' => $sessionToken,
    'chat_id' => '555',
    'event_low_stock' => '1',
], $sessionToken, 'teacher');
$deniedMap = [];
foreach ($denied['settings'] as $row) {
    $deniedMap[$row['key']] = $row['value'];
}
check(($deniedMap['telegram_chat_id'] ?? '') === '', 'teacher save does not write chat id');

$badCsrf = invokeTelegramSave('POST', [
    'csrf_token' => 'wrong',
    'chat_id' => '1',
    'event_low_stock' => '1',
], $sessionToken, 'owner');
check((int) $badCsrf['status'] === 403, 'bad CSRF fails closed on telegram settings');

$getDenied = invokeTelegramSave('GET', [
    'csrf_token' => $sessionToken,
    'chat_id' => '1',
], $sessionToken, 'owner');
check((int) $getDenied['status'] === 405, 'GET telegram settings is rejected');

$home = (string) file_get_contents($root . '/templates/home/index.php');
check(str_contains($home, '연결 필요'), 'home shows connect-needed when token empty');
check(str_contains($home, '재고 부족'), 'home still lists low stock');
$settingsTpl = (string) file_get_contents($root . '/templates/settings/index.php');
check(str_contains($settingsTpl, '연결 필요'), 'settings shows connect-needed when token empty');
check(!preg_match('/name=["\']bot_token/', $settingsTpl), 'settings has no token input');

$gitignore = (string) file_get_contents($root . '/.gitignore');
check(str_contains($gitignore, 'config.php'), 'config.php stays untracked');

Telegram::setHttpHandler(null);
echo "PASS: {$checks} alert/telegram checks\n";
