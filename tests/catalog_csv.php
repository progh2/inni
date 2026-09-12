<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\CatalogCsv;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,code,qr_code,created_at,updated_at) VALUES('room','전자실습실','room','E-201','ROOM:test','now','now')");
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('tool','공구실','room','ROOM:tool','now','now')");
$pdo->prepare('INSERT INTO catalog_items(id,name,type,description,tags,unit,min_stock,manufacturer,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['ci-1', '납땜', 'consumable', '실습용', '["소모"]', 'm', 5, 'Kester', 'CAT:ci-1', 'now', 'now']);
$pdo->prepare('INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES(?,?,?,?,?)')
    ->execute(['lot-1', 'ci-1', 'room', 18, 'now']);
$pdo->prepare('INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?)')
    ->execute(['ci-eq', '멀티미터', 'equipment', 'CAT:ci-eq', 'now', 'now']);
$pdo->prepare('INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute(['ast-1', 'ci-eq', '멀티미터', '전장-1', 'available', 'room', 'AST:1', 'now', 'now']);

$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];
$manager = ['id' => 'manager', 'display_name' => '매니저', 'role' => 'manager', 'status' => 'active'];
$teacher = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];
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
        $pdo->query('SELECT id, name, type, unit, min_stock FROM catalog_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, management_number FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

$template = CatalogCsv::template();
check(str_contains($template, '품명') && str_contains($template, '유형'), 'Template missing Korean headers');
check(str_contains($template, '사업명') && str_contains($template, '예산연도'), 'Template missing budget headers');
check(str_contains($template, '도입일') && str_contains($template, '내용연한'), 'Template missing 도입일/내용연한 headers');
check(!str_contains($template, CatalogCsv::BOM), 'Template helper should not include BOM (controller adds it)');

$map = CatalogCsv::mapHeader(['id', 'name', 'type']);
check(($map['id'] ?? null) === 0 && ($map['name'] ?? null) === 1, 'English headers should map');
$mapKo = CatalogCsv::mapHeader(['품목ID', '품명', '유형', '위치']);
check(($mapKo['name'] ?? null) === 1 && ($mapKo['location_name'] ?? null) === 3, 'Korean headers should map');

check(CatalogCsv::normalizeType('소모품') === 'consumable', 'Korean type alias');
check(CatalogCsv::normalizeType('equipment') === 'equipment', 'English type alias');
check(CatalogCsv::normalizeType('unknown') === null, 'Bad type is null');

$exported = CatalogCsv::export($pdo);
check($exported['count'] === 2, 'Export should include existing catalog items');
check(str_contains($exported['csv'], '납땜') && str_contains($exported['csv'], '소모품'), 'Export should use Korean type labels');

$round = CatalogCsv::import($pdo, $owner, $exported['csv'], 'round.csv');
check($round['created'] === 0 && $round['updated'] === 2 && $round['skipped'] === 0, 'Re-import of export should update, not create');
check((float) $pdo->query("SELECT quantity FROM stock_lots WHERE id='lot-1'")->fetchColumn() === 18.0, 'Update must not change stock');

$createCsv = CatalogCsv::encode([
    CatalogCsv::headerRow(),
    ['', '전선', '소모품', '1.5sq', '소모,전선', 'm', '20', '', '', '0', '전자실습실', '12', ''],
    ['', '충전드릴', '장비', '', '공구', 'ea', '', '', '보쉬', '1', 'E-201', '2', '드릴-10'],
    ['', '카탈로그만', '비품', '위치 없음', '', 'ea', '', '', '', '', '', '', ''],
]);
$created = CatalogCsv::import($pdo, $manager, $createCsv, 'new.csv');
check($created['created'] === 3 && $created['updated'] === 0 && $created['skipped'] === 0, 'Manager create rows failed: ' . json_encode($created));
$wire = $pdo->query("SELECT c.id, s.quantity FROM catalog_items c JOIN stock_lots s ON s.catalog_item_id=c.id WHERE c.name='전선'")->fetch(PDO::FETCH_ASSOC);
check(is_array($wire) && (float) $wire['quantity'] === 12.0, 'Consumable create should add stock lot');
$drills = (int) $pdo->query("SELECT COUNT(*) FROM assets a JOIN catalog_items c ON c.id=a.catalog_item_id WHERE c.name='충전드릴'")->fetchColumn();
check($drills === 2, 'Equipment create should add assets');
$only = $pdo->query("SELECT id FROM catalog_items WHERE name='카탈로그만'")->fetchColumn();
$onlyLots = (int) $pdo->query("SELECT COUNT(*) FROM stock_lots WHERE catalog_item_id=" . $pdo->quote((string) $only))->fetchColumn();
check($only && $onlyLots === 0, 'Create without location should be catalog-only');

$skipCsv = CatalogCsv::encode([
    CatalogCsv::headerRow(),
    ['ci-1', '납땜', '장비', '', '', 'm', '', '', '', '', '', '', ''],
    ['missing-id', '없는품목', '소모품', '', '', 'ea', '', '', '', '', '', '', ''],
    ['', '', '소모품', '', '', '', '', '', '', '', '전자실습실', '1', ''],
    ['', '유령', '소모품', '', '', 'ea', '', '', '', '', '없는실', '3', ''],
    ['', '나쁜유형', '책상', '', '', 'ea', '', '', '', '', '', '', ''],
    ['ci-1', '두번째', '소모품', '', '', 'm', '', '', '', '', '', '', ''],
    ['ci-1', '납땜', '소모품', '', '', 'm', '-1', '', '', '', '', '', ''],
]);
$beforeSkip = snapshot($pdo);
$skipped = CatalogCsv::import($pdo, $owner, $skipCsv, 'skip.csv');
check($skipped['created'] === 0 && $skipped['updated'] === 0 && $skipped['skipped'] === 7, 'Conflict rows should be skipped: ' . json_encode($skipped));
$reasons = array_column($skipped['skipped_rows'], 'reason');
check(in_array('유형은 바꿀 수 없습니다.', $reasons, true), 'Type change should be reported');
check(in_array('유형은 장비/비품/소모품/부품만 사용할 수 있습니다.', $reasons, true), 'Invalid type should be reported');
check(in_array('품목 ID를 찾을 수 없습니다.', $reasons, true), 'Missing id should be reported');
check(in_array('같은 파일에서 품목 ID가 중복됩니다.', $reasons, true), 'Duplicate id should be reported');
check((bool) array_filter($reasons, static fn (string $r): bool => str_contains($r, '위치를 찾을 수 없습니다')), 'Unknown location should be reported');
check(snapshot($pdo) === $beforeSkip, 'Skipped rows must not change catalog or stock');

$beforeRole = snapshot($pdo);
try {
    CatalogCsv::import($pdo, $teacher, $createCsv, 'nope.csv');
    throw new RuntimeException('Teacher import was accepted');
} catch (InvalidArgumentException) {
    check(snapshot($pdo) === $beforeRole, 'Teacher import changed state');
}

try {
    CatalogCsv::import($pdo, $owner, 'not-csv', 'sheet.xlsx');
    throw new RuntimeException('xlsx filename was accepted');
} catch (InvalidArgumentException $e) {
    check(str_contains($e->getMessage(), 'xlsx'), 'xlsx should be rejected');
}

try {
    CatalogCsv::import($pdo, $owner, 'PK' . str_repeat('x', 20), 'upload.csv');
    throw new RuntimeException('zip/xlsx magic was accepted');
} catch (InvalidArgumentException $e) {
    check(str_contains($e->getMessage(), 'xlsx'), 'xlsx magic should be rejected');
}

$bomCsv = CatalogCsv::BOM . CatalogCsv::encode([
    ['name', 'type', 'location_name', 'quantity'],
    ['페이퍼', '비품', '공구실', '4'],
]);
$bom = CatalogCsv::import($pdo, $owner, $bomCsv, 'bom.csv');
check($bom['created'] === 1, 'UTF-8 BOM + English headers should import');

$cp949 = mb_convert_encoding(
    CatalogCsv::encode([
        ['품명', '유형', '위치', '수량'],
        ['CP납땜', '소모품', '공구실', '2'],
    ]),
    'CP949',
    'UTF-8'
);
$fromCp = CatalogCsv::import($pdo, $owner, $cp949, 'cp949.csv');
check($fromCp['created'] === 1, 'CP949 Excel CSV should import');
check((int) $pdo->query("SELECT COUNT(*) FROM catalog_items WHERE name='CP납땜'")->fetchColumn() === 1, 'CP949 row missing');

$typeExport = CatalogCsv::export($pdo, 'consumable');
check($typeExport['count'] >= 2 && !str_contains($typeExport['csv'], '충전드릴'), 'Type-filtered export');

$roomExport = CatalogCsv::export($pdo, null, 'tool');
check(str_contains($roomExport['csv'], '페이퍼') && !str_contains($roomExport['csv'], '멀티미터'), 'Location-filtered export');

$log = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='import'")->fetchColumn();
check((int) $log >= 1, 'Import should write an activity log');

echo "PASS: {$checks} catalog csv checks\n";
