<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Asset;
use Inni\AssetLife;
use Inni\PpsUsefulLife;

$checks = 0;

function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$catalog = PpsUsefulLife::catalog();
check(isset($catalog['notice']['id']) && $catalog['notice']['id'] === '제2024-30호', 'Notice id');
check(($catalog['notice']['effective'] ?? '') === '2025-01-01', 'Notice effective date');
check(isset($catalog['extend']) && is_string($catalog['extend']) && str_contains($catalog['extend'], 'items'), 'Extend howto');
check(is_array($catalog['items']) && count($catalog['items']) === 1711, 'Full 1711-row seed');

$seen = [];
foreach ($catalog['items'] as $i => $row) {
    check(is_array($row), 'Row ' . $i . ' is array');
    $class = PpsUsefulLife::normalizeClassNumber($row['class_number'] ?? '');
    $name = isset($row['name']) ? trim((string) $row['name']) : '';
    $years = (int) ($row['years'] ?? 0);
    check(strlen($class) === 8, 'Class number 8 digits: ' . $class);
    check($name !== '', 'Name present at ' . $class);
    check($years >= AssetLife::YEARS_MIN && $years <= AssetLife::YEARS_MAX, 'Years in range for ' . $name);
    check(!isset($seen[$class]), 'Unique class ' . $class);
    $seen[$class] = true;
}

$nb = PpsUsefulLife::suggest('노트북컴퓨터');
check($nb !== [] && $nb[0]['name'] === '노트북컴퓨터' && $nb[0]['years'] === 6, 'Exact 노트북컴퓨터 → 6');
check($nb[0]['class_number'] === '43211503', '노트북컴퓨터 class number');
check(str_contains($nb[0]['source'], '조달청고시 제2024-30호'), 'Source includes 고시');
check(str_contains($nb[0]['source'], '노트북컴퓨터'), 'Source includes 품명');

$partial = PpsUsefulLife::suggest('노트북');
$partialNames = array_column($partial, 'name');
check(in_array('노트북컴퓨터', $partialNames, true), 'Prefix 노트북 includes 노트북컴퓨터');

$desk = PpsUsefulLife::suggest('데스크톱컴퓨터');
check($desk !== [] && $desk[0]['years'] === 5, '데스크톱컴퓨터 → 5');

$meter = PpsUsefulLife::suggest('디지털 멀티미터');
check($meter !== [] && in_array('멀티미터', array_column($meter, 'name'), true), '디지털 멀티미터 matches 멀티미터');
$meterHit = null;
foreach ($meter as $hit) {
    if ($hit['name'] === '멀티미터') {
        $meterHit = $hit;
        break;
    }
}
check(is_array($meterHit) && $meterHit['years'] === 11, '멀티미터 → 11');

$scope = PpsUsefulLife::suggest('오실로스코프');
check($scope !== [] && $scope[0]['years'] === 13, '오실로스코프 → 13');

$deskClass = PpsUsefulLife::suggest('', '43211507');
check($deskClass !== [] && $deskClass[0]['name'] === '데스크톱컴퓨터' && $deskClass[0]['match'] === 'class', 'Class number exact');

$dashed = PpsUsefulLife::suggest('', '4321-1503');
check($dashed !== [] && $dashed[0]['class_number'] === '43211503', 'Dashed class number normalizes');

$weld = PpsUsefulLife::suggest('용접기');
check(count($weld) >= 2, '용접기 returns multiple candidates');
foreach ($weld as $hit) {
    check(str_contains(PpsUsefulLife::normalizeName($hit['name']), '용접'), '용접기 candidate is welding-related');
}

$school = PpsUsefulLife::suggest('창의융합 특화키트-3학년');
check($school === [], 'School-specific name is not forced to a match');

$short = PpsUsefulLife::suggest('기');
check($short === [], 'Single-character name does not suggest');

$empty = PpsUsefulLife::suggest('', '');
check($empty === [], 'Empty query suggests nothing');

$payload = PpsUsefulLife::response('책상', '');
check(isset($payload['notice'], $payload['query'], $payload['suggestions']), 'JSON payload shape');
check($payload['suggestions'] !== [] && $payload['suggestions'][0]['years'] === 9, '책상 → 9');
check($payload['query']['name'] === '책상', 'Query echoes name');

$root = dirname(__DIR__);
$itemSave = (string) file_get_contents($root . '/app/Controllers/ItemController.php');
check(!str_contains($itemSave, 'PpsUsefulLife'), 'Item save does not auto-apply PPS years');
$assetLife = (string) file_get_contents($root . '/app/Asset.php');
check(!str_contains($assetLife, 'PpsUsefulLife'), 'Asset::updateLife does not auto-apply PPS years');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','now','now')");
$pdo->prepare('INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?)')
    ->execute(['ci-eq', '창의융합 특화키트', 'equipment', 'CAT:ci-eq', 'now', 'now']);
$pdo->prepare('INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute(['ast-1', 'ci-eq', '창의융합 특화키트', '전장-1', 'available', 'room', 'AST:1', 'now', 'now']);

$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];
Asset::updateLife($pdo, $owner, 'ast-1', '2024-03-01', '7');
$row = $pdo->query("SELECT useful_life_years FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check((int) $row['useful_life_years'] === 7, 'Manual years save without catalog match');

Asset::updateLife($pdo, $owner, 'ast-1', '2024-03-01', '');
$row = $pdo->query("SELECT useful_life_years FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check($row['useful_life_years'] === null, 'Empty years stays empty even if name could match later');

$suggestSrc = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
check(str_contains($suggestSrc, 'function suggestLife'), 'Suggest endpoint exists');
if (preg_match('/function suggestLife\(\): void\s*\{(.*?)\n    public function /s', $suggestSrc, $m) !== 1) {
    throw new RuntimeException('Could not isolate suggestLife');
}
check(!str_contains($m[1], 'Csrf::requirePost()'), 'Suggest is GET and not a CSRF write');
check(!str_contains($m[1], 'UPDATE ') && !str_contains($m[1], 'INSERT '), 'Suggest does not write');
check(str_contains($m[1], 'PpsUsefulLife::response'), 'Suggest returns lookup payload');

$router = (string) file_get_contents($root . '/app/Router.php');
check(str_contains($router, "'assets/life-suggest'"), 'Router maps life-suggest');

$newTpl = (string) file_get_contents($root . '/templates/items/new.php');
$showTpl = (string) file_get_contents($root . '/templates/assets/show.php');
$partial = (string) file_get_contents($root . '/templates/partials/life_suggest.php');
check(str_contains($newTpl, 'life_suggest.php') && str_contains($showTpl, 'life_suggest.php'), 'Create/edit include suggest UI');
check(str_contains($partial, '조달청고시') || str_contains($partial, 'noticeLabel'), 'Partial shows 고시 source');
check(str_contains($partial, '강제') && str_contains($partial, 'type="button"'), 'Accept is a non-submit button');
check(!str_contains($partial, 'name="useful_life_years"'), 'Suggest partial does not own the years field');

$js = (string) file_get_contents($root . '/public/assets/js/app.js');
check(str_contains($js, 'useful_life_years') && str_contains($js, 'life-suggest-accept'), 'JS fills years on accept');

echo "PASS: {$checks} pps-useful-life checks\n";
