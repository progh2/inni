<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\App;
use Inni\Auth;
use Inni\MaterialBoard;
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

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

$pdo = memoryDb();
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('bldg','실습동','building',null,null,'LOC:bldg','t','t')");
$pdo->exec("INSERT INTO locations(id,name,kind,parent_id,code,qr_code,created_at,updated_at) VALUES('elec','전자실습실','room','bldg','E-201','LOC:elec','t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('solder','납땜','consumable','CAT:solder','m',5,'t','t')");
$pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,unit,min_stock,created_at,updated_at) VALUES('wire','전선','consumable','CAT:wire','m',2,'t','t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-solder','solder','elec',1,'t')");
$pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-wire','wire','elec',8,'t')");

$teacher = actor('teacher', 'teacher');
Stock::issue($pdo, $teacher, 'solder', 'lot-solder', '1', '5교시 실습', 'elec', '2학년');

$waiting = MaterialBoard::waitingRestock($pdo);
check(array_values(array_map(static fn (array $row): string => (string) $row['id'], $waiting)) === ['solder'], 'waitingRestock lists only qty < min_stock');
check((int) $waiting[0]['low_stock'] === 1, 'waitingRestock rows stay highlighted');

$recent = Stock::issueHistory($pdo);
check($recent !== [] && ($recent[0]['action'] ?? '') === 'issue', 'recent 분출 section can load issueHistory');
check(str_contains((string) $recent[0]['summary'], '분출'), 'recent issue summary uses 분출');

$issueUrl = MaterialBoard::itemActionUrl('solder', MaterialBoard::ACTION_ISSUE);
$restockUrl = MaterialBoard::itemActionUrl('solder', MaterialBoard::ACTION_RESTOCK);
$showBase = App::url('items/show', ['id' => 'solder']);
check(str_starts_with($issueUrl, $showBase) && str_ends_with($issueUrl, '#issue'), '분출 CTA deep-links to item show #issue');
check(str_starts_with($restockUrl, $showBase) && str_ends_with($restockUrl, '#restock'), '재입고 CTA deep-links to item show #restock');
check(MaterialBoard::itemActionUrl(' solder ', 'nope') === MaterialBoard::itemActionUrl('solder', 'issue'), 'unknown action falls back to 분출');
check(MaterialBoard::normalizeAction('restock') === MaterialBoard::ACTION_RESTOCK, 'normalizeAction keeps restock');
check(MaterialBoard::normalizeAction(['issue']) === null && MaterialBoard::normalizeAction('cancel') === null, 'normalizeAction rejects unknown values');

$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$student = actor('student', 'student');
$pending = actor('teacher-p', 'teacher', 'pending');
check(Auth::canLoan($owner) && Auth::canWrite($owner), 'owner can 분출 and 재입고');
check(Auth::canLoan($manager) && Auth::canWrite($manager), 'manager can 분출 and 재입고');
check(Auth::canLoan($teacher) && !Auth::canWrite($teacher), 'teacher can 분출 but not 재입고');
check(!Auth::canLoan($student) && !Auth::canWrite($student), 'student has no board CTAs');
check(!Auth::canLoan($pending) && !Auth::canWrite($pending), 'pending teacher has no board CTAs');

$empty = memoryDb();
check(MaterialBoard::waitingRestock($empty) === [], 'empty 재입고 대기 is empty');
check(Stock::issueHistory($empty) === [], 'empty 최근 분출 is empty');

$ctl = (string) file_get_contents($root . '/app/Controllers/MaterialController.php');
check(str_contains($ctl, 'MaterialBoard::waitingRestock'), 'controller loads 재입고 대기');
check(str_contains($ctl, 'Stock::issueHistory'), 'controller loads 최근 분출');
check(str_contains($ctl, 'Auth::requireLogin()'), 'dashboard still requires login only');
check(!preg_match('/function index\(\): void\s*\{[^}]*canWrite/', $ctl), 'dashboard browse is not write-gated');
check(!str_contains($ctl, 'Csrf::'), 'dashboard stays GET; CTAs are links not writes');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'materials' => [MaterialController::class, 'index']"), 'no new materials dashboard route');
check(!str_contains($router, "'materials/issue'") && !str_contains($router, "'materials/restock'"), 'board does not add parallel issue/restock pages');

$tpl = (string) file_get_contents($root . '/templates/materials/index.php');
check(str_contains($tpl, 'id="restock-wait"') && str_contains($tpl, '재입고 대기'), 'dashboard has 재입고 대기 section');
check(str_contains($tpl, 'id="recent-issues"') && str_contains($tpl, '최근 분출'), 'dashboard has 최근 분출 section');
check(str_contains($tpl, 'material_actions.php'), 'dashboard includes board action links');
check(str_contains($tpl, 'href="#restock-wait"') && str_contains($tpl, 'href="#recent-issues"'), 'dashboard jumps to waiting and recent sections');
check(!str_contains($tpl, 'Csrf::field()'), 'board actions are GET links, not POST forms');
check(!preg_match('/name=["\']q["\']/', $tpl), 'dashboard does not require a search box');
check(!str_contains($tpl, 'new Vue') && !str_contains($tpl, 'createApp'), 'dashboard stays SSR');

$partial = (string) file_get_contents($root . '/templates/partials/material_actions.php');
check(str_contains($partial, 'Auth::canLoan($user)'), '분출 CTA is canLoan-gated');
check(str_contains($partial, 'Auth::canWrite($user)'), '재입고 CTA is canWrite-gated');
check(str_contains($partial, 'MaterialBoard::itemActionUrl') && str_contains($partial, 'ACTION_ISSUE'), '분출 CTA uses itemActionUrl');
check(str_contains($partial, 'ACTION_RESTOCK') && str_contains($partial, '>재입고<'), '재입고 CTA uses Korean copy');
check(str_contains($partial, '>분출<'), '분출 CTA uses Korean copy');
check(!str_contains($partial, 'Csrf::field()') && !str_contains($partial, 'method="post"'), 'CTAs do not post from the board');

$show = (string) file_get_contents($root . '/templates/items/show.php');
check(str_contains($show, 'id="issue"') && str_contains($show, 'id="restock"'), 'item show has #issue and #restock landing anchors');
check(str_contains($show, "App::url('items/issue')") && str_contains($show, "App::url('items/restock')"), 'item show still hosts the write forms');

$boardSrc = (string) file_get_contents($root . '/app/MaterialBoard.php');
check(str_contains($boardSrc, 'function itemActionUrl') && str_contains($boardSrc, 'function waitingRestock'), 'MaterialBoard owns dashboard action helpers');
check(!str_contains($boardSrc, 'Edufine') && !str_contains($boardSrc, 'kit') && !str_contains($boardSrc, 'BOM'), 'board helpers stay scoped to #50');

echo "PASS: {$checks} material-board-action checks\n";
