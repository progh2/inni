<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Asset;
use Inni\Budget;
use Inni\Catalog;
use Inni\CatalogCsv;
use Inni\Database;

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

check(Budget::parseProgram(null) === null && Budget::parseProgram('  ') === null, 'Empty program is null');
check(Budget::parseProgram(' 방과후 ') === '방과후', 'Program is trimmed');
check(Budget::parseYear(null) === null && Budget::parseYear('') === null, 'Empty year is null');
check(Budget::parseYear('2026') === 2026 && Budget::parseYear(2024) === 2024, 'YYYY year parses');
check(Budget::format('방과후', 2026) === '2026년 · 방과후', 'Format both');
check(Budget::format(null, 2026) === '2026년', 'Format year only');
check(Budget::format('방과후', null) === '방과후', 'Format program only');
check(Budget::format(null, null) === '', 'Format empty');

$filter = Budget::filterSql('c', '방과후', 2026);
check($filter['sql'] === ' AND c.budget_program = ? AND c.budget_year = ?', 'Filter SQL both');
check($filter['params'] === ['방과후', 2026], 'Filter params both');
$emptyFilter = Budget::filterSql('a', null, null);
check($emptyFilter['sql'] === '' && $emptyFilter['params'] === [], 'Empty filter is no-op');
check(str_contains(Budget::likeSql('a'), 'a.budget_program'), 'Search like hook mentions program');

reject(static fn () => Budget::parseYear('26'), 'Two-digit year');
reject(static fn () => Budget::parseYear('abcd'), 'Non-numeric year');
reject(static fn () => Budget::parseYear('1899'), 'Year below range');
reject(static fn () => Budget::parseYear('2101'), 'Year above range');
reject(static fn () => Budget::parseProgram(str_repeat('가', 201)), 'Overlong program');
reject(static fn () => Budget::filterSql('a;drop', 'x', null), 'Bad alias');

$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('PRAGMA foreign_keys = ON');
$old->exec('CREATE TABLE users (id TEXT PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE locations (id TEXT PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE catalog_items (id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec('CREATE TABLE assets (id TEXT PRIMARY KEY, catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id), name TEXT NOT NULL, management_number TEXT NOT NULL, status TEXT NOT NULL, location_id TEXT NOT NULL, qr_code TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$old->exec("CREATE TABLE loans (id TEXT PRIMARY KEY, kind TEXT NOT NULL, asset_id TEXT, status TEXT NOT NULL DEFAULT 'active')");
$old->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('old','구품목','consumable','CAT:old','t','t')");
Database::migrate($old);
$catCols = array_column($old->query('PRAGMA table_info(catalog_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$astCols = array_column($old->query('PRAGMA table_info(assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('budget_program', $catCols, true) && in_array('budget_year', $catCols, true), 'Migrate adds catalog budget columns');
check(in_array('budget_program', $astCols, true) && in_array('budget_year', $astCols, true), 'Migrate adds asset budget columns');
$legacy = $old->query("SELECT budget_program, budget_year FROM catalog_items WHERE id='old'")->fetch(PDO::FETCH_ASSOC);
check($legacy['budget_program'] === null && $legacy['budget_year'] === null, 'Old rows stay empty after migrate');
Database::migrate($old);
check(true, 'Migrate is idempotent');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
$pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','now','now')");
$pdo->prepare('INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?)')
    ->execute(['ci-1', '납땜', 'consumable', 'CAT:ci-1', 'now', 'now']);
$pdo->prepare('INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?)')
    ->execute(['ci-eq', '멀티미터', 'equipment', 'CAT:ci-eq', 'now', 'now']);
$pdo->prepare('INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute(['ast-1', 'ci-eq', '멀티미터', '전장-1', 'available', 'room', 'AST:1', 'now', 'now']);

$owner = ['id' => 'owner', 'display_name' => '담당', 'role' => 'owner', 'status' => 'active'];
$teacher = ['id' => 'teacher', 'display_name' => '교사', 'role' => 'teacher', 'status' => 'active'];

Catalog::update($pdo, $owner, 'ci-1', '납땜', null, [], 'm', null, null, null, null, false, '방과후', '2026');
$row = $pdo->query("SELECT budget_program, budget_year FROM catalog_items WHERE id='ci-1'")->fetch(PDO::FETCH_ASSOC);
check($row['budget_program'] === '방과후' && (int) $row['budget_year'] === 2026, 'Catalog save budget');

Catalog::update($pdo, $owner, 'ci-1', '납땜', null, [], 'm', null, null, null, null, false, '', '');
$row = $pdo->query("SELECT budget_program, budget_year FROM catalog_items WHERE id='ci-1'")->fetch(PDO::FETCH_ASSOC);
check($row['budget_program'] === null && $row['budget_year'] === null, 'Catalog clear budget');

reject(
    static fn () => Catalog::update($pdo, $owner, 'ci-1', '납땜', null, [], 'm', null, null, null, null, false, '방과후', '99'),
    'Catalog invalid year'
);
$row = $pdo->query("SELECT budget_program, budget_year FROM catalog_items WHERE id='ci-1'")->fetch(PDO::FETCH_ASSOC);
check($row['budget_program'] === null && $row['budget_year'] === null, 'Invalid year does not write');

Asset::updateBudget($pdo, $owner, 'ast-1', '특화교육', 2025);
$asset = $pdo->query("SELECT budget_program, budget_year FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check($asset['budget_program'] === '특화교육' && (int) $asset['budget_year'] === 2025, 'Asset save budget');
Asset::updateBudget($pdo, $owner, 'ast-1', null, null);
$asset = $pdo->query("SELECT budget_program, budget_year FROM assets WHERE id='ast-1'")->fetch(PDO::FETCH_ASSOC);
check($asset['budget_program'] === null && $asset['budget_year'] === null, 'Asset clear budget');
reject(static fn () => Asset::updateBudget($pdo, $teacher, 'ast-1', '금지', 2024), 'Teacher asset budget');

Catalog::update($pdo, $owner, 'ci-eq', '멀티미터', null, [], 'ea', null, null, null, null, false, 'CSV사업', 2024);
$exported = CatalogCsv::export($pdo);
check(str_contains($exported['csv'], '사업명') && str_contains($exported['csv'], '예산연도'), 'Export headers include budget');
check(str_contains($exported['csv'], 'CSV사업') && str_contains($exported['csv'], '2024'), 'Export includes budget values');

$round = CatalogCsv::import($pdo, $owner, $exported['csv'], 'round-budget.csv');
check($round['created'] === 0 && $round['updated'] === 2 && $round['skipped'] === 0, 'Budget export re-import updates');
$again = $pdo->query("SELECT budget_program, budget_year FROM catalog_items WHERE id='ci-eq'")->fetch(PDO::FETCH_ASSOC);
check($again['budget_program'] === 'CSV사업' && (int) $again['budget_year'] === 2024, 'Round-trip keeps budget');

$createCsv = CatalogCsv::encode([
    CatalogCsv::headerRow(),
    ['', '전선', '소모품', '', '', 'm', '', '', '', '0', '실습실', '3', '', '재료비', '2026'],
    ['', '빈예산', '비품', '', '', 'ea', '', '', '', '0', '', '', '', '', ''],
]);
$created = CatalogCsv::import($pdo, $owner, $createCsv, 'budget-new.csv');
check($created['created'] === 2 && $created['skipped'] === 0, 'CSV create with budget: ' . json_encode($created));
$wire = $pdo->query("SELECT budget_program, budget_year FROM catalog_items WHERE name='전선'")->fetch(PDO::FETCH_ASSOC);
check(is_array($wire) && $wire['budget_program'] === '재료비' && (int) $wire['budget_year'] === 2026, 'CSV create stores budget');
$empty = $pdo->query("SELECT budget_program, budget_year FROM catalog_items WHERE name='빈예산'")->fetch(PDO::FETCH_ASSOC);
check(is_array($empty) && $empty['budget_program'] === null && $empty['budget_year'] === null, 'CSV create allows empty budget');

$koCsv = CatalogCsv::encode([
    ['품명', '유형', '사업명', '년도'],
    ['저항', '부품', '자체', '2023'],
]);
$fromKo = CatalogCsv::import($pdo, $owner, $koCsv, 'ko-budget.csv');
check($fromKo['created'] === 1, 'Korean budget headers import');
$res = $pdo->query("SELECT budget_program, budget_year FROM catalog_items WHERE name='저항'")->fetch(PDO::FETCH_ASSOC);
check(is_array($res) && $res['budget_program'] === '자체' && (int) $res['budget_year'] === 2023, 'Korean year alias maps');

echo "PASS: {$checks} budget checks\n";
