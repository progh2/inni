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
    'app/Controllers/ItemController.php' => ['save', 'issue'],
    'app/Controllers/AssetController.php' => ['loan', 'move', 'report', 'photo'],
    'app/Controllers/LoanController.php' => ['returnLoan'],
    'app/Controllers/RoomController.php' => ['save'],
    'app/Controllers/SettingsController.php' => ['save', 'approve'],
    'app/Controllers/ScanController.php' => ['resolve'],
    'app/Controllers/LabelController.php' => ['print'],
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
    'templates/rooms/index.php' => 'rooms/save',
    'templates/assets/show.php' => 'assets/loan',
    'templates/loans/index.php' => 'loans/return',
    'templates/settings/index.php' => 'settings/save',
    'templates/settings/users.php' => 'settings/approve',
    'templates/scan/index.php' => 'scan/resolve',
    'templates/labels/index.php' => 'labels/print',
];
foreach ($forms as $file => $route) {
    $source = file_get_contents($root . '/' . $file);
    check(is_string($source) && str_contains($source, 'Csrf::field()'), $file . ' form for ' . $route . ' must include Csrf::field()');
}

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

$scanTpl = (string) file_get_contents($root . '/templates/scan/index.php');
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
    && str_contains((string) $printOk['body'], 'QRCode.toCanvas'),
    'Valid label CSRF should render a QR preview'
);

echo "PASS: {$checks} csrf checks\n";
