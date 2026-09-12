<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Csrf;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function methodBody(string $source, string $method): string
{
    if (!preg_match('/function ' . preg_quote($method, '/') . '\(\): void\s*\{/', $source, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = $match[0][1] + strlen($match[0][0]);
    if (preg_match('/\n    public function /', $source, $next, PREG_OFFSET_CAPTURE, $start)) {
        return substr($source, $start, $next[0][1] - $start);
    }
    return substr($source, $start);
}

function invokeReturnLoan(string $httpMethod, array $post, ?string $sessionToken): array
{
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/inni-csrf-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $dbPath = $tmp . '/inni.sqlite';
    $runner = $tmp . '/run.php';
    $resultFile = $tmp . '/result.json';
    $payload = [
        'root' => $root,
        'db' => $dbPath,
        'method' => $httpMethod,
        'post' => $post,
        'csrf' => $sessionToken,
        'result' => $resultFile,
    ];
    file_put_contents($tmp . '/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$p = json_decode(file_get_contents(__DIR__ . '/payload.json'), true, 512, JSON_THROW_ON_ERROR);
require $p['root'] . '/app/bootstrap.php';

use Inni\App;
use Inni\Csrf;
use Inni\Controllers\LoanController;

$ref = new ReflectionClass(App::class);
$rootProp = $ref->getProperty('root');
$rootProp->setAccessible(true);
$rootProp->setValue(null, $p['root']);
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, [
    'app_name' => 'inni',
    'school_name' => 'csrf-test',
    'timezone' => 'UTC',
    'demo_login' => true,
    'session_name' => 'inni_csrf_test',
    'base_url' => 'http://localhost',
    'db_path' => $p['db'],
]);

$_SERVER['REQUEST_METHOD'] = $p['method'];
$_POST = $p['post'];
$_SESSION = ['user_id' => 'demo-owner'];
if (is_string($p['csrf'])) {
    $_SESSION[Csrf::SESSION_KEY] = $p['csrf'];
}

register_shutdown_function(static function () use ($p): void {
    $loan = null;
    if (is_file($p['db'])) {
        $pdo = new PDO('sqlite:' . $p['db']);
        $loan = $pdo->query("SELECT status FROM loans WHERE id = 'loan-1'")->fetchColumn() ?: null;
    }
    file_put_contents($p['result'], json_encode([
        'status' => http_response_code(),
        'loan' => $loan,
        'body' => (string) ob_get_contents(),
    ], JSON_UNESCAPED_UNICODE));
});

ob_start();
(new LoanController())->returnLoan();
PHP);

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>' . escapeshellarg($tmp . '/stderr.txt'));
    $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
    $stderr = is_file($tmp . '/stderr.txt') ? (string) file_get_contents($tmp . '/stderr.txt') : '';
    $decoded = json_decode($raw, true);
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
    if (!is_array($decoded)) {
        throw new RuntimeException('Return-loan harness failed: ' . $raw . $stderr);
    }
    return $decoded;
}

function invokeNamedController(string $class, string $method, string $httpMethod, array $post, ?string $sessionToken): array
{
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/inni-csrf-ctrl-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $dbPath = $tmp . '/inni.sqlite';
    $runner = $tmp . '/run.php';
    $resultFile = $tmp . '/result.json';
    $payload = [
        'root' => $root,
        'db' => $dbPath,
        'class' => $class,
        'method' => $method,
        'http' => $httpMethod,
        'post' => $post,
        'csrf' => $sessionToken,
        'result' => $resultFile,
    ];
    file_put_contents($tmp . '/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$p = json_decode(file_get_contents(__DIR__ . '/payload.json'), true, 512, JSON_THROW_ON_ERROR);
require $p['root'] . '/app/bootstrap.php';

use Inni\App;
use Inni\Csrf;

$ref = new ReflectionClass(App::class);
$rootProp = $ref->getProperty('root');
$rootProp->setAccessible(true);
$rootProp->setValue(null, $p['root']);
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, [
    'app_name' => 'inni',
    'school_name' => 'csrf-test',
    'timezone' => 'UTC',
    'demo_login' => true,
    'session_name' => 'inni_csrf_test',
    'base_url' => 'http://localhost',
    'db_path' => $p['db'],
]);

$_SERVER['REQUEST_METHOD'] = $p['http'];
$_POST = $p['post'];
$_GET = [];
$_SESSION = ['user_id' => 'demo-owner'];
if (is_string($p['csrf'])) {
    $_SESSION[Csrf::SESSION_KEY] = $p['csrf'];
}

register_shutdown_function(static function () use ($p): void {
    file_put_contents($p['result'], json_encode([
        'status' => http_response_code(),
        'flash' => $_SESSION['_flash'] ?? [],
        'body' => (string) ob_get_contents(),
    ], JSON_UNESCAPED_UNICODE));
});

ob_start();
$controller = new $p['class']();
$controller->{$p['method']}();
PHP);

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>' . escapeshellarg($tmp . '/stderr.txt'));
    $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
    $stderr = is_file($tmp . '/stderr.txt') ? (string) file_get_contents($tmp . '/stderr.txt') : '';
    $decoded = json_decode($raw, true);
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
    if (!is_array($decoded)) {
        throw new RuntimeException('Controller harness failed: ' . $raw . $stderr);
    }
    return $decoded;
}

$_SESSION = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

$token = Csrf::token();
check(strlen($token) === 64 && ctype_xdigit($token), 'CSRF token should be 32 random bytes hex');
check(Csrf::token() === $token, 'CSRF token should stay stable in the session');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf_token'] = $token;
check(Csrf::inspect() === 200, 'Valid CSRF should be accepted');

$_POST['csrf_token'] = 'not-the-session-token';
check(Csrf::inspect() === 403, 'Bad CSRF should be rejected');

unset($_POST['csrf_token']);
check(Csrf::inspect() === 403, 'Missing CSRF should be rejected');

$_POST['csrf_token'] = '';
check(Csrf::inspect() === 403, 'Empty CSRF should be rejected');

$_POST['csrf_token'] = ['array'];
check(Csrf::inspect() === 403, 'Non-string CSRF should be rejected');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST['csrf_token'] = $token;
check(Csrf::inspect() === 405, 'GET should be rejected even with a valid token');

$_SERVER['REQUEST_METHOD'] = 'PUT';
check(Csrf::inspect() === 405, 'Non-POST methods should be rejected');

$root = dirname(__DIR__);
$field = Csrf::field();
check(str_contains($field, 'name="csrf_token"') && str_contains($field, $token), 'Hidden field should carry the session token');
check(str_contains((string) file_get_contents($root . '/app/Csrf.php'), 'Support::e(self::token())'), 'Hidden field value must be escaped');
$mutations = [
    'app/Controllers/ItemController.php' => ['save', 'update', 'issue', 'restock', 'cancelIssue'],
    'app/Controllers/AssetController.php' => ['loan', 'move', 'report', 'photo'],
    'app/Controllers/LoanController.php' => ['returnLoan'],
    'app/Controllers/RoomController.php' => ['save'],
    'app/Controllers/SettingsController.php' => ['save', 'saveTelegram', 'approve'],
    'app/Controllers/ScanController.php' => ['resolve'],
    'app/Controllers/LabelController.php' => ['print'],
    'app/Controllers/InventoryController.php' => ['start', 'confirm', 'finish'],
    'app/Controllers/CatalogCsvController.php' => ['import'],
];
foreach ($mutations as $file => $methods) {
    $source = file_get_contents($root . '/' . $file);
    check(is_string($source), $file . ' missing');
    foreach ($methods as $method) {
        check(str_contains(methodBody($source, $method), 'Csrf::requirePost()'), $file . '::' . $method . ' must call Csrf::requirePost()');
    }
}

$forms = [
    'templates/items/show.php' => 'items/issue',
    'templates/items/new.php' => 'items/save',
    'templates/items/edit.php' => 'items/update',
    'templates/rooms/index.php' => 'rooms/save',
    'templates/assets/show.php' => 'assets/loan',
    'templates/loans/index.php' => 'loans/return',
    'templates/settings/index.php' => 'settings/save',
    'templates/settings/users.php' => 'settings/approve',
    'templates/partials/scan_input.php' => 'scan-manual-form',
    'templates/labels/index.php' => 'labels/print',
    'templates/inventory/index.php' => 'inventory/start',
    'templates/inventory/show.php' => 'inventory/finish',
    'templates/rooms/show.php' => 'inventory/start',
    'templates/catalog/csv.php' => 'catalog/csv/import',
];
foreach ($forms as $file => $route) {
    $source = file_get_contents($root . '/' . $file);
    check(is_string($source) && str_contains($source, 'Csrf::field()'), $file . ' form for ' . $route . ' must include Csrf::field()');
    check(str_contains($source, $route), $file . ' must post to ' . $route);
}
$scanPage = (string) file_get_contents($root . '/templates/scan/index.php')
    . (string) file_get_contents($root . '/templates/partials/scan_input.php');
check(str_contains($scanPage, 'scan/resolve') && str_contains($scanPage, 'Csrf::field()'), 'Scan page + partial post to scan/resolve with CSRF');
$invShow = (string) file_get_contents($root . '/templates/inventory/show.php')
    . (string) file_get_contents($root . '/templates/partials/scan_input.php');
check(str_contains($invShow, 'inventory/confirm') && str_contains($invShow, 'Csrf::field()'), 'Inventory confirm reuses scan CSRF POST');
$show = (string) file_get_contents($root . '/templates/items/show.php');
check(str_contains($show, 'items/restock') && str_contains($show, 'items/cancel-issue'), 'Item show must include restock and cancel-issue forms');
$settingsForm = (string) file_get_contents($root . '/templates/settings/index.php');
check(str_contains($settingsForm, 'settings/telegram'), 'settings telegram form posts to settings/telegram');
check(substr_count($settingsForm, 'Csrf::field()') >= 2, 'school and telegram settings forms include CSRF');
check(!preg_match('/name=["\']bot_token/', $settingsForm), 'settings must not accept a bot token field');
check(!preg_match('/name=["\']api_key/', $settingsForm), 'settings must not accept an AI api_key field');
check(!str_contains($settingsForm, 'settings/ai'), 'settings has no AI write form');

$sessionToken = bin2hex(random_bytes(32));
$accepted = invokeReturnLoan('POST', [
    'csrf_token' => $sessionToken,
    'loan_id' => 'loan-1',
], $sessionToken);
check(($accepted['status'] === 302 || $accepted['status'] === 200) && $accepted['loan'] === 'returned', 'Valid CSRF should return an active loan');

$rejected = invokeReturnLoan('POST', [
    'csrf_token' => 'wrong-token',
    'loan_id' => 'loan-1',
], $sessionToken);
check($rejected['status'] === 403 && $rejected['loan'] === 'active', 'Bad CSRF must fail closed without returning the loan');

$getRejected = invokeReturnLoan('GET', [
    'csrf_token' => $sessionToken,
    'loan_id' => 'loan-1',
], $sessionToken);
check($getRejected['status'] === 405 && $getRejected['loan'] === 'active', 'GET must be rejected for loan return');

$scanTpl = (string) file_get_contents($root . '/templates/scan/index.php')
    . (string) file_get_contents($root . '/templates/partials/scan_input.php');
check(str_contains($scanTpl, 'csrf.name = csrfName') && str_contains($scanTpl, 'csrf.value = csrfToken'), 'Camera submit must attach csrf_token');
check(str_contains($scanTpl, 'isSecureContext') && str_contains($scanTpl, 'useManual'), 'Scan page must fall back to code input when the camera cannot start');
check(str_contains($scanTpl, "facingMode: 'environment'"), 'Scan should prefer the rear camera');

$printLayout = (string) file_get_contents($root . '/templates/layouts/print.php');
check(str_contains($printLayout, '@media print') && str_contains($printLayout, '.toolbar'), 'Print layout must hide the toolbar when printing');
check(str_contains($printLayout, 'page-break-inside: avoid') && str_contains($printLayout, 'dashed'), 'Print labels must keep dashed sticker boxes on one page');

$scanMissing = invokeNamedController('Inni\\Controllers\\ScanController', 'resolve', 'POST', [
    'code' => '전장-2024-017',
], $sessionToken);
check($scanMissing['status'] === 403, 'Scan resolve without CSRF must be 403');

$scanBad = invokeNamedController('Inni\\Controllers\\ScanController', 'resolve', 'POST', [
    'csrf_token' => 'wrong-token',
    'code' => '전장-2024-017',
], $sessionToken);
check($scanBad['status'] === 403, 'Scan resolve with a bad CSRF token must be 403');

$scanGet = invokeNamedController('Inni\\Controllers\\ScanController', 'resolve', 'GET', [
    'csrf_token' => $sessionToken,
    'code' => '전장-2024-017',
], $sessionToken);
check($scanGet['status'] === 405, 'Scan resolve GET must be 405');

$scanOk = invokeNamedController('Inni\\Controllers\\ScanController', 'resolve', 'POST', [
    'csrf_token' => $sessionToken,
    'code' => '전장-2024-017',
], $sessionToken);
check($scanOk['status'] === 302 && ($scanOk['flash'] ?? []) === [], 'Valid scan CSRF should redirect without an error flash');

$printMissing = invokeNamedController('Inni\\Controllers\\LabelController', 'print', 'POST', [
    'asset_ids' => ['ast-dmm-1'],
], $sessionToken);
check($printMissing['status'] === 403, 'Label print without CSRF must be 403');

$printGet = invokeNamedController('Inni\\Controllers\\LabelController', 'print', 'GET', [
    'csrf_token' => $sessionToken,
    'asset_ids' => ['ast-dmm-1'],
], $sessionToken);
check($printGet['status'] === 405, 'Label print GET must be 405');

$printOk = invokeNamedController('Inni\\Controllers\\LabelController', 'print', 'POST', [
    'csrf_token' => $sessionToken,
    'asset_ids' => ['ast-dmm-1'],
], $sessionToken);
check(
    ($printOk['status'] === 200 || $printOk['status'] === false)
    && str_contains((string) $printOk['body'], 'qr-0')
    && str_contains((string) $printOk['body'], 'QRCode.toDataURL'),
    'Valid label CSRF should render a QR preview'
);

function invokeItemAction(string $methodName, string $httpMethod, array $post, ?string $sessionToken, bool $issueFirst = false): array
{
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/inni-csrf-item-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $dbPath = $tmp . '/inni.sqlite';
    $runner = $tmp . '/run.php';
    $resultFile = $tmp . '/result.json';
    $payload = [
        'root' => $root,
        'db' => $dbPath,
        'method_name' => $methodName,
        'method' => $httpMethod,
        'post' => $post,
        'csrf' => $sessionToken,
        'issue_first' => $issueFirst,
        'result' => $resultFile,
    ];
    file_put_contents($tmp . '/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$p = json_decode(file_get_contents(__DIR__ . '/payload.json'), true, 512, JSON_THROW_ON_ERROR);
require $p['root'] . '/app/bootstrap.php';

use Inni\App;
use Inni\Csrf;
use Inni\Controllers\ItemController;
use Inni\Stock;

$ref = new ReflectionClass(App::class);
$rootProp = $ref->getProperty('root');
$rootProp->setAccessible(true);
$rootProp->setValue(null, $p['root']);
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, [
    'app_name' => 'inni',
    'school_name' => 'csrf-test',
    'timezone' => 'UTC',
    'demo_login' => true,
    'session_name' => 'inni_csrf_item_test',
    'base_url' => 'http://localhost',
    'db_path' => $p['db'],
]);

$_SERVER['REQUEST_METHOD'] = $p['method'];
$_POST = $p['post'];
$_SESSION = ['user_id' => 'demo-owner'];
if (is_string($p['csrf'])) {
    $_SESSION[Csrf::SESSION_KEY] = $p['csrf'];
}

if (!empty($p['issue_first'])) {
    $actor = ['id' => 'demo-owner', 'display_name' => '김담당', 'role' => 'owner', 'status' => 'active'];
    Stock::issue(\Inni\Database::pdo(), $actor, 'ci-solder', 'lot-solder', '2', 'CSRF 출고');
    $logId = \Inni\Database::pdo()->query("SELECT id FROM activity_logs WHERE action='issue' ORDER BY rowid DESC LIMIT 1")->fetchColumn();
    if (empty($_POST['issue_log_id'])) {
        $_POST['issue_log_id'] = (string) $logId;
    }
}

register_shutdown_function(static function () use ($p): void {
    $qty = null;
    $cancels = 0;
    if (is_file($p['db'])) {
        $pdo = new PDO('sqlite:' . $p['db']);
        $qty = $pdo->query("SELECT quantity FROM stock_lots WHERE id = 'lot-solder'")->fetchColumn();
        $cancels = (int) $pdo->query('SELECT COUNT(*) FROM stock_issue_cancels')->fetchColumn();
    }
    file_put_contents($p['result'], json_encode([
        'status' => http_response_code(),
        'quantity' => $qty === false ? null : (is_numeric($qty) ? (float) $qty : $qty),
        'cancels' => $cancels,
        'body' => (string) ob_get_contents(),
    ], JSON_UNESCAPED_UNICODE));
});

ob_start();
$controller = new ItemController();
$controller->{$p['method_name']}();
PHP);

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>' . escapeshellarg($tmp . '/stderr.txt'));
    $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
    $stderr = is_file($tmp . '/stderr.txt') ? (string) file_get_contents($tmp . '/stderr.txt') : '';
    $decoded = json_decode($raw, true);
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
    if (!is_array($decoded)) {
        throw new RuntimeException('Item mutation harness failed: ' . $raw . $stderr);
    }
    return $decoded;
}

$restockOk = invokeItemAction('restock', 'POST', [
    'csrf_token' => $sessionToken,
    'item_id' => 'ci-solder',
    'location_id' => 'loc-elec',
    'quantity' => '3',
    'note' => '보충',
], $sessionToken);
check(($restockOk['status'] === 302 || $restockOk['status'] === 200) && (float) $restockOk['quantity'] === 21.0, 'Valid CSRF should restock');

$restockBad = invokeItemAction('restock', 'POST', [
    'csrf_token' => 'wrong-token',
    'item_id' => 'ci-solder',
    'location_id' => 'loc-elec',
    'quantity' => '3',
], $sessionToken);
check($restockBad['status'] === 403 && (float) $restockBad['quantity'] === 18.0, 'Bad CSRF must fail closed without restocking');

$restockGet = invokeItemAction('restock', 'GET', [
    'csrf_token' => $sessionToken,
    'item_id' => 'ci-solder',
    'location_id' => 'loc-elec',
    'quantity' => '3',
], $sessionToken);
check($restockGet['status'] === 405 && (float) $restockGet['quantity'] === 18.0, 'GET must be rejected for restock');

$cancelOk = invokeItemAction('cancelIssue', 'POST', [
    'csrf_token' => $sessionToken,
    'item_id' => 'ci-solder',
    'reason' => '오입력',
], $sessionToken, true);
check(($cancelOk['status'] === 302 || $cancelOk['status'] === 200) && (float) $cancelOk['quantity'] === 18.0 && $cancelOk['cancels'] === 1, 'Valid CSRF should cancel an issue');

$cancelBad = invokeItemAction('cancelIssue', 'POST', [
    'csrf_token' => 'wrong-token',
    'item_id' => 'ci-solder',
    'reason' => '오입력',
], $sessionToken, true);
check($cancelBad['status'] === 403 && (float) $cancelBad['quantity'] === 16.0 && $cancelBad['cancels'] === 0, 'Bad CSRF must fail closed without cancelling');

$cancelGet = invokeItemAction('cancelIssue', 'GET', [
    'csrf_token' => $sessionToken,
    'item_id' => 'ci-solder',
    'reason' => '오입력',
], $sessionToken, true);
check($cancelGet['status'] === 405 && (float) $cancelGet['quantity'] === 16.0 && $cancelGet['cancels'] === 0, 'GET must be rejected for cancel-issue');

function invokeInventoryAction(string $methodName, string $httpMethod, array $post, ?string $sessionToken, bool $startFirst = false): array
{
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/inni-csrf-inv-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $dbPath = $tmp . '/inni.sqlite';
    $runner = $tmp . '/run.php';
    $resultFile = $tmp . '/result.json';
    $payload = [
        'root' => $root,
        'db' => $dbPath,
        'method_name' => $methodName,
        'method' => $httpMethod,
        'post' => $post,
        'csrf' => $sessionToken,
        'start_first' => $startFirst,
        'result' => $resultFile,
    ];
    file_put_contents($tmp . '/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$p = json_decode(file_get_contents(__DIR__ . '/payload.json'), true, 512, JSON_THROW_ON_ERROR);
require $p['root'] . '/app/bootstrap.php';

use Inni\App;
use Inni\Csrf;
use Inni\Controllers\InventoryController;
use Inni\Inventory;

$ref = new ReflectionClass(App::class);
$rootProp = $ref->getProperty('root');
$rootProp->setAccessible(true);
$rootProp->setValue(null, $p['root']);
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, [
    'app_name' => 'inni',
    'school_name' => 'csrf-test',
    'timezone' => 'UTC',
    'demo_login' => true,
    'session_name' => 'inni_csrf_inv_test',
    'base_url' => 'http://localhost',
    'db_path' => $p['db'],
]);

$_SERVER['REQUEST_METHOD'] = $p['method'];
$_POST = $p['post'];
$_SESSION = ['user_id' => 'demo-owner'];
if (is_string($p['csrf'])) {
    $_SESSION[Csrf::SESSION_KEY] = $p['csrf'];
}

if (!empty($p['start_first'])) {
    $actor = ['id' => 'demo-owner', 'display_name' => '김담당', 'role' => 'owner', 'status' => 'active'];
    $check = Inventory::start(\Inni\Database::pdo(), $actor, 'loc-elec');
    if (empty($_POST['check_id'])) {
        $_POST['check_id'] = (string) $check['id'];
    }
}

register_shutdown_function(static function () use ($p): void {
    $active = 0;
    $confirmed = 0;
    if (is_file($p['db'])) {
        $pdo = new PDO('sqlite:' . $p['db']);
        $active = (int) $pdo->query("SELECT COUNT(*) FROM inventory_checks WHERE status = 'active'")->fetchColumn();
        $confirmed = (int) $pdo->query('SELECT COUNT(*) FROM inventory_check_lines WHERE confirmed_at IS NOT NULL')->fetchColumn();
    }
    file_put_contents($p['result'], json_encode([
        'status' => http_response_code(),
        'active' => $active,
        'confirmed' => $confirmed,
        'body' => (string) ob_get_contents(),
    ], JSON_UNESCAPED_UNICODE));
});

ob_start();
$controller = new InventoryController();
$controller->{$p['method_name']}();
PHP);

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>' . escapeshellarg($tmp . '/stderr.txt'));
    $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
    $stderr = is_file($tmp . '/stderr.txt') ? (string) file_get_contents($tmp . '/stderr.txt') : '';
    $decoded = json_decode($raw, true);
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
    if (!is_array($decoded)) {
        throw new RuntimeException('Inventory mutation harness failed: ' . $raw . $stderr);
    }
    return $decoded;
}

$invStartOk = invokeInventoryAction('start', 'POST', [
    'csrf_token' => $sessionToken,
    'location_id' => 'loc-elec',
], $sessionToken);
check(($invStartOk['status'] === 302 || $invStartOk['status'] === 200) && $invStartOk['active'] === 1, 'Valid CSRF should start inventory');

$invStartBad = invokeInventoryAction('start', 'POST', [
    'csrf_token' => 'wrong-token',
    'location_id' => 'loc-elec',
], $sessionToken);
check($invStartBad['status'] === 403 && $invStartBad['active'] === 0, 'Bad CSRF must fail closed without starting inventory');

$invStartGet = invokeInventoryAction('start', 'GET', [
    'csrf_token' => $sessionToken,
    'location_id' => 'loc-elec',
], $sessionToken);
check($invStartGet['status'] === 405 && $invStartGet['active'] === 0, 'GET must be rejected for inventory start');

$invConfirmOk = invokeInventoryAction('confirm', 'POST', [
    'csrf_token' => $sessionToken,
    'code' => '전장-2024-017',
], $sessionToken, true);
check(($invConfirmOk['status'] === 302 || $invConfirmOk['status'] === 200) && $invConfirmOk['confirmed'] === 1, 'Valid CSRF should confirm a scanned item');

$invConfirmBad = invokeInventoryAction('confirm', 'POST', [
    'csrf_token' => 'wrong-token',
    'code' => '전장-2024-017',
], $sessionToken, true);
check($invConfirmBad['status'] === 403 && $invConfirmBad['confirmed'] === 0, 'Bad CSRF must fail closed without confirming');

$invConfirmGet = invokeInventoryAction('confirm', 'GET', [
    'csrf_token' => $sessionToken,
    'code' => '전장-2024-017',
], $sessionToken, true);
check($invConfirmGet['status'] === 405 && $invConfirmGet['confirmed'] === 0, 'GET must be rejected for inventory confirm');

$invFinishGet = invokeInventoryAction('finish', 'GET', [
    'csrf_token' => $sessionToken,
], $sessionToken, true);
check($invFinishGet['status'] === 405 && $invFinishGet['active'] === 1, 'GET must be rejected for inventory finish');

function invokeCatalogCsvImport(string $httpMethod, array $post, ?string $sessionToken, ?string $csvBody): array
{
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/inni-csrf-csv-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $dbPath = $tmp . '/inni.sqlite';
    $csvPath = $tmp . '/upload.csv';
    $runner = $tmp . '/run.php';
    $resultFile = $tmp . '/result.json';
    if (is_string($csvBody)) {
        file_put_contents($csvPath, $csvBody);
    }
    $payload = [
        'root' => $root,
        'db' => $dbPath,
        'method' => $httpMethod,
        'post' => $post,
        'csrf' => $sessionToken,
        'csv' => is_string($csvBody) ? $csvPath : null,
        'result' => $resultFile,
    ];
    file_put_contents($tmp . '/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$p = json_decode(file_get_contents(__DIR__ . '/payload.json'), true, 512, JSON_THROW_ON_ERROR);
require $p['root'] . '/app/bootstrap.php';

use Inni\App;
use Inni\Csrf;
use Inni\Controllers\CatalogCsvController;

$ref = new ReflectionClass(App::class);
$rootProp = $ref->getProperty('root');
$rootProp->setAccessible(true);
$rootProp->setValue(null, $p['root']);
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, [
    'app_name' => 'inni',
    'school_name' => 'csrf-test',
    'timezone' => 'UTC',
    'demo_login' => true,
    'session_name' => 'inni_csrf_csv_test',
    'base_url' => 'http://localhost',
    'db_path' => $p['db'],
]);

$_SERVER['REQUEST_METHOD'] = $p['method'];
$_POST = $p['post'];
$_FILES = [];
$_SESSION = ['user_id' => 'demo-owner'];
if (is_string($p['csrf'])) {
    $_SESSION[Csrf::SESSION_KEY] = $p['csrf'];
}
if (is_string($p['csv'])) {
    $_FILES['csv'] = [
        'name' => 'upload.csv',
        'type' => 'text/csv',
        'tmp_name' => $p['csv'],
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($p['csv']),
    ];
}

register_shutdown_function(static function () use ($p): void {
    $items = 0;
    if (is_file($p['db'])) {
        $pdo = new PDO('sqlite:' . $p['db']);
        $items = (int) $pdo->query('SELECT COUNT(*) FROM catalog_items')->fetchColumn();
    }
    file_put_contents($p['result'], json_encode([
        'status' => http_response_code(),
        'items' => $items,
        'body' => (string) ob_get_contents(),
    ], JSON_UNESCAPED_UNICODE));
});

ob_start();
(new CatalogCsvController())->import();
PHP);

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>' . escapeshellarg($tmp . '/stderr.txt'));
    $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
    $stderr = is_file($tmp . '/stderr.txt') ? (string) file_get_contents($tmp . '/stderr.txt') : '';
    $decoded = json_decode($raw, true);
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
    if (!is_array($decoded)) {
        throw new RuntimeException('Catalog CSV harness failed: ' . $raw . $stderr);
    }
    return $decoded;
}

$csvBody = "품명,유형,위치,수량\nCSRF소모품,소모품,전자실습실,3\n";
$csvGet = invokeCatalogCsvImport('GET', ['csrf_token' => $sessionToken], $sessionToken, $csvBody);
check($csvGet['status'] === 405, 'GET must be rejected for catalog csv import');

$csvBad = invokeCatalogCsvImport('POST', ['csrf_token' => 'wrong-token'], $sessionToken, $csvBody);
check($csvBad['status'] === 403, 'Bad CSRF must fail closed without importing catalog csv');

$csvOk = invokeCatalogCsvImport('POST', ['csrf_token' => $sessionToken], $sessionToken, $csvBody);
check(
    ($csvOk['status'] === 302 || $csvOk['status'] === 200) && $csvOk['items'] > $csvBad['items'],
    'Valid CSRF should import a catalog csv row'
);

echo "PASS: {$checks} csrf checks\n";
