<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\App;
use Inni\Auth;
use Inni\Database;
use Inni\Loan;
use Inni\Seed;
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

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

function memoryDb(): PDO
{
    global $root;
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
    return $pdo;
}

function insertUser(PDO $pdo, string $id, string $role, string $status): void
{
    $pdo->prepare(
        'INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)'
    )->execute([$id, $id . '@test', $id, $role, $status, 't', 't']);
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, status, created_by FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_id FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function reject(callable $fn, PDO $pdo, string $message): void
{
    $before = snapshot($pdo);
    try {
        $fn();
        throw new RuntimeException($message . ' was accepted');
    } catch (InvalidArgumentException) {
        check(snapshot($pdo) === $before, $message . ' changed state');
    }
}

function appConfig(array $config): void
{
    $ref = new ReflectionClass(App::class);
    $prop = $ref->getProperty('config');
    $prop->setAccessible(true);
    $prop->setValue(null, $config);
}

function usePdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Database::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');
$student = actor('student', 'student');
$pendingTeacher = actor('pending-teacher', 'teacher', 'pending');
$pendingStudent = actor('pending-student', 'student', 'pending');
$pendingOwner = actor('pending-owner', 'owner', 'pending');
$disabledTeacher = actor('disabled-teacher', 'teacher', 'disabled');
$disabledOwner = actor('disabled-owner', 'owner', 'disabled');

check(Auth::canWrite($owner) && Auth::canWrite($manager), 'active owner/manager may register');
check(Auth::canInventory($owner) && Auth::canInventory($manager), 'active owner/manager may run inventory');
check(Auth::canConfigureAlerts($owner) && Auth::canConfigureAlerts($manager), 'active owner/manager may configure alerts');
check(!Auth::canWrite($teacher), 'teacher cannot register');
check(!Auth::canInventory($teacher), 'teacher cannot run inventory');
check(!Auth::canConfigureAlerts($teacher) && !Auth::canConfigureAlerts($student) && !Auth::canConfigureAlerts($pendingOwner) && !Auth::canConfigureAlerts(null), 'teacher/student/pending cannot configure alerts');
check(!Auth::canWrite($student), 'student cannot register');
check(!Auth::canInventory($student) && !Auth::canInventory($pendingOwner) && !Auth::canInventory($disabledOwner) && !Auth::canInventory(null), 'student/pending/disabled cannot run inventory');
check(!Auth::canWrite($pendingOwner) && !Auth::canWrite($pendingTeacher) && !Auth::canWrite($pendingStudent), 'pending cannot register');
check(!Auth::canWrite($disabledOwner) && !Auth::canWrite($disabledTeacher) && !Auth::canWrite(null), 'disabled or missing cannot register');

check(Auth::canLoan($owner) && Auth::canLoan($manager) && Auth::canLoan($teacher), 'active staff may loan/issue');
check(!Auth::canLoan($student), 'student cannot loan/issue');
check(!Auth::canLoan($pendingTeacher) && !Auth::canLoan($pendingOwner) && !Auth::canLoan($pendingStudent), 'pending cannot loan/issue');
check(!Auth::canLoan($disabledTeacher) && !Auth::canLoan($disabledOwner), 'disabled cannot loan/issue');

check(Auth::isOwner($owner), 'active owner is owner');
check(!Auth::isOwner($pendingOwner) && !Auth::isOwner($disabledOwner) && !Auth::isOwner($manager), 'pending/disabled/manager are not owner');

check(Auth::canReturn($student) && !Auth::canReturn($pendingStudent) && !Auth::canReturn($disabledTeacher), 'only active users may return');

$pdo = memoryDb();
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:rb','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('consumable','납땜','consumable','CAT:con','t','t')");
$pdo->exec("INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES('ast-1','eq','스코프','M1','available','room','AST:1','t','t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','consumable','room',10,'t')");
foreach (['owner' => 'owner', 'teacher' => 'teacher', 'student' => 'student'] as $id => $role) {
    insertUser($pdo, $id, $role, 'active');
}
insertUser($pdo, 'pending-teacher', 'teacher', 'pending');
insertUser($pdo, 'pending-student', 'student', 'pending');
insertUser($pdo, 'disabled-teacher', 'teacher', 'disabled');

reject(static function () use ($pdo, $student): void {
    Loan::checkout($pdo, $student, 'ast-1', '학생', null, '신청', null);
}, $pdo, 'Student checkout');
reject(static function () use ($pdo, $pendingTeacher): void {
    Loan::checkout($pdo, $pendingTeacher, 'ast-1', '미승인', null, '신청', null);
}, $pdo, 'Pending teacher checkout');
reject(static function () use ($pdo, $pendingStudent): void {
    Loan::checkout($pdo, $pendingStudent, 'ast-1', '미승인학생', null, '신청', null);
}, $pdo, 'Pending student checkout');
reject(static function () use ($pdo, $disabledTeacher): void {
    Loan::checkout($pdo, $disabledTeacher, 'ast-1', '중지', null, '신청', null);
}, $pdo, 'Disabled checkout');

reject(static function () use ($pdo, $student): void {
    Stock::issue($pdo, $student, 'consumable', 'lot-1', '1', '수업');
}, $pdo, 'Student issue');
reject(static function () use ($pdo, $pendingTeacher): void {
    Stock::issue($pdo, $pendingTeacher, 'consumable', 'lot-1', '1', '수업');
}, $pdo, 'Pending teacher issue');
reject(static function () use ($pdo, $pendingStudent): void {
    Stock::issue($pdo, $pendingStudent, 'consumable', 'lot-1', '1', '수업');
}, $pdo, 'Pending student issue');
reject(static function () use ($pdo, $disabledTeacher): void {
    Stock::issue($pdo, $disabledTeacher, 'consumable', 'lot-1', '1', '수업');
}, $pdo, 'Disabled issue');

usePdo($pdo);
$_SESSION['user_id'] = 'pending-teacher';
check(Auth::user() === null, 'pending session is not an authenticated user');
$_SESSION['user_id'] = 'pending-student';
check(Auth::user() === null, 'pending student session is not authenticated');
$_SESSION['user_id'] = 'disabled-teacher';
check(Auth::user() === null, 'disabled session is not authenticated');
$_SESSION['user_id'] = 'student';
$sessionStudent = Auth::user();
check(is_array($sessionStudent) && $sessionStudent['role'] === 'student', 'active student session resolves');
check(!Auth::canWrite($sessionStudent) && !Auth::canLoan($sessionStudent), 'active student still cannot write or loan');
$_SESSION['user_id'] = 'teacher';
$sessionTeacher = Auth::user();
check(is_array($sessionTeacher) && Auth::canLoan($sessionTeacher) && !Auth::canWrite($sessionTeacher), 'active teacher may loan but not register');
$_SESSION['user_id'] = 'owner';
$sessionOwner = Auth::user();
check(is_array($sessionOwner) && Auth::canWrite($sessionOwner) && Auth::isOwner($sessionOwner), 'active owner may register');
unset($_SESSION['user_id']);

appConfig([]);
check(Auth::isDemoLoginEnabled() === false, 'omitted demo_login defaults off');
check(Auth::demoLoginUserId('owner') === null && Auth::demoLoginUserId('teacher') === null, 'omitted demo_login hides shortcuts');

appConfig(['demo_login' => true]);
check(Auth::isDemoLoginEnabled() === true, 'explicit true keeps demo login');
check(Auth::demoLoginUserId('owner') === Seed::DEMO_OWNER_ID, 'explicit true keeps owner shortcut');
check(Auth::demoLoginUserId('teacher') === Seed::DEMO_TEACHER_ID, 'explicit true keeps teacher shortcut');

appConfig(['demo_login' => false]);
check(Auth::isDemoLoginEnabled() === false, 'explicit false disables demo login');
check(Auth::demoLoginUserId('owner') === null, 'explicit false hides owner shortcut');

$loginTpl = (string) file_get_contents($root . '/templates/auth/login.php');
check(str_contains($loginTpl, 'if (!empty($demo))'), 'login demo buttons stay gated on demo_login');
check(str_contains($loginTpl, "App::url('auth/demo', ['as' => 'owner'])"), 'login keeps owner demo button');
check(str_contains($loginTpl, "App::url('auth/demo', ['as' => 'teacher'])"), 'login keeps teacher demo button');

$item = (string) file_get_contents($root . '/app/Controllers/ItemController.php');
check(str_contains($item, 'function index') && str_contains($item, 'Auth::requireLogin()'), 'item browse requires login');
check(str_contains($item, 'Auth::canWrite($user)') && str_contains($item, 'function save'), 'item register requires canWrite');
check(str_contains($item, 'Auth::canLoan($user)') && str_contains($item, 'function issue'), 'item issue requires canLoan');
if (preg_match('/function index\(\): void\s*\{(.*?)\n    public function /s', $item, $indexMatch)) {
    check(str_contains($indexMatch[1], 'Auth::requireLogin()') && !str_contains($indexMatch[1], 'canWrite'), 'active teacher may browse catalog');
} else {
    check(false, 'ItemController::index body not found');
}

$asset = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
check(str_contains($asset, 'Auth::canLoan($user)') && str_contains($asset, 'function loan'), 'asset loan requires canLoan');
check(str_contains($asset, 'Auth::canWrite($user)') && str_contains($asset, 'function photo'), 'asset photo requires canWrite');

$room = (string) file_get_contents($root . '/app/Controllers/RoomController.php');
check(str_contains($room, 'Auth::canWrite($user)') && str_contains($room, 'function save'), 'room register requires canWrite');

$inventory = (string) file_get_contents($root . '/app/Controllers/InventoryController.php');
check(str_contains($inventory, 'Auth::canInventory($user)'), 'inventory controller requires canInventory');
check(str_contains($inventory, 'function start') && str_contains($inventory, 'function confirm') && str_contains($inventory, 'function finish'), 'inventory has start/confirm/finish');

$catalogCsv = (string) file_get_contents($root . '/app/Controllers/CatalogCsvController.php');
check(str_contains($catalogCsv, 'Auth::canWrite($user)'), 'catalog csv requires canWrite (owner/manager)');
check(str_contains($catalogCsv, 'function import') && str_contains($catalogCsv, 'function export') && str_contains($catalogCsv, 'function template'), 'catalog csv has template/export/import');
check(str_contains($catalogCsv, 'Csrf::requirePost()'), 'catalog csv import requires POST+CSRF');
$more = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($more, "App::url('items')") && str_contains($more, '품목 목록'), 'more menu lists catalog browse for any logged-in role');
check(str_contains($more, "App::url('assets')") && str_contains($more, '기자재 현황'), 'more menu links to assets status board');
check(str_contains($more, 'Auth::canWrite($user)') && str_contains($more, 'catalog/csv'), 'more menu gates catalog csv on canWrite');
check(str_contains($more, 'Auth::canConfigureAlerts($user)') && str_contains($more, 'settings'), 'more menu gates settings on canConfigureAlerts');
check(str_contains($more, '!empty($aiReady)'), 'more menu hides AI helper when not connected');
check(str_contains($more, '재료·품목 목록') && str_contains($more, "App::url('items')"), 'more menu links to 재료·품목 목록');
check(str_contains($more, '기자재 현황') && str_contains($more, "App::url('assets')"), 'more menu links to 기자재 현황');
check(str_contains($more, '내 대여함') && str_contains($more, 'Auth::canLoan($user)'), 'more menu gates 내 대여함 on canLoan');
check(str_contains($more, '수리 요청') && str_contains($more, "App::url('reports')"), 'more menu links to repair queue');

$reportCtl = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
check(str_contains($reportCtl, 'function updateStatus') && str_contains($reportCtl, 'Report::canTransition'), 'report status requires canWrite');
check(str_contains($reportCtl, 'Csrf::requirePost()'), 'report status requires POST+CSRF');
$reportDomain = (string) file_get_contents($root . '/app/Report.php');
check(str_contains($reportDomain, 'Auth::canLoan($user)') && str_contains($reportDomain, 'function canCreate'), 'teachers may create reports via canLoan');
check(str_contains($reportDomain, 'Auth::canWrite($user)') && str_contains($reportDomain, 'function canTransition'), 'only owner/manager change repair status');

$loanCtl = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
check(str_contains($loanCtl, 'function mine') && str_contains($loanCtl, 'Auth::requireLogin()'), 'loan inbox requires login');
check(str_contains($loanCtl, 'Auth::canReturn($user)') && str_contains($loanCtl, 'Csrf::requirePost()'), 'loan return keeps canReturn and POST+CSRF');

$settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
check(str_contains($settings, 'Auth::canConfigureAlerts($user)'), 'telegram settings allow owner/manager');
check(substr_count($settings, 'Auth::isOwner($user)') >= 3, 'school name and user approval stay owner-only');
check(str_contains($settings, 'function saveTelegram'), 'settings has telegram save');
check(str_contains($settings, 'Ai::isReady()'), 'settings exposes AI connection status');
check(!str_contains($settings, 'function saveAi'), 'settings has no AI key save');
check(str_contains($settings, 'Auth::isDemoLoginEnabled()'), 'settings uses fail-closed demo_login helper');

$auth = (string) file_get_contents($root . '/app/Auth.php');
check(str_contains($auth, "App::config('demo_login', false)"), 'missing demo_login key is fail-closed');
check(str_contains($auth, 'isActiveRole($user, [\'owner\', \'manager\'])'), 'canWrite requires active owner/manager');
check(substr_count($auth, "isActiveRole(\$user, ['owner', 'manager'])") >= 3, 'canInventory/canConfigureAlerts use the same owner/manager gate as canWrite');

$example = (string) file_get_contents($root . '/config.example.php');
check(str_contains($example, "'demo_login' => true"), 'local example keeps demo_login true');
check(str_contains($example, 'config.production.example.php'), 'local example points at the production template');
check(str_contains($example, "'client_id' => ''") && str_contains($example, "'client_secret' => ''"), 'local example has no Google secrets');
check(str_contains($example, "'bot_token' => ''"), 'local example has no Telegram bot token');
check(str_contains($example, "'api_key' => ''"), 'local example has no AI api key');

$production = (string) file_get_contents($root . '/config.production.example.php');
check(str_contains($production, "'demo_login' => false"), 'production example shows demo_login false');
check(!preg_match('/client_id\'\s*=>\s*\'(?!\')[^\'].+\'/', $production), 'production example must not ship a real client_id');
check(str_contains($production, "'client_id' => ''") && str_contains($production, "'client_secret' => ''"), 'production example has empty Google secrets');
check(str_contains($production, "'bot_token' => ''"), 'production example has no Telegram bot token');
check(str_contains($production, "'api_key' => ''"), 'production example has no AI api key');

$readme = (string) file_get_contents($root . '/README.md');
check(str_contains($readme, 'config.production.example.php'), 'README documents the production example');
check(str_contains($readme, 'demo_login => false'), 'README requires demo_login false in production');

$compose = (string) file_get_contents($root . '/docker-compose.yml');
check(str_contains($compose, 'config.production.example.php'), 'Compose notes the production config path');

$entry = (string) file_get_contents($root . '/docker/entrypoint.sh');
check(str_contains($entry, 'demo_login stays true'), 'Docker first-boot keeps local demo_login true');
check(str_contains($entry, 'demo_login false'), 'Docker notes production bind-mount is demo_login false');

echo "PASS: {$checks} role-block checks\n";
