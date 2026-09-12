<?php

declare(strict_types=1);

namespace Inni;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Catalog CSV template / import / export.
 * XLSX is out of scope: no Composer, and spreadsheet XML is not worth a hand-rolled parser.
 */
final class CatalogCsv
{
    public const MAX_ROWS = 5000;
    public const BOM = "\xEF\xBB\xBF";

    /** @var list<string> */
    public const COLUMNS = [
        'id',
        'name',
        'type',
        'description',
        'tags',
        'unit',
        'min_stock',
        'edufine_number',
        'manufacturer',
        'favorite',
        'location_name',
        'quantity',
        'management_number',
        'budget_program',
        'budget_year',
    ];

    /** @var array<string, string> */
    public const HEADER_LABELS = [
        'id' => '품목ID',
        'name' => '품명',
        'type' => '유형',
        'description' => '설명',
        'tags' => '태그',
        'unit' => '단위',
        'min_stock' => '최소재고',
        'edufine_number' => '에듀파인번호',
        'manufacturer' => '제조사',
        'budget_program' => '사업명',
        'budget_year' => '예산연도',
        'favorite' => '즐겨찾기',
        'location_name' => '위치',
        'quantity' => '수량',
        'management_number' => '관리번호',
    ];

    /**
     * @return list<string>
     */
    public static function headerRow(): array
    {
        $out = [];
        foreach (self::COLUMNS as $col) {
            $out[] = self::HEADER_LABELS[$col];
        }
        return $out;
    }

    public static function template(): string
    {
        return self::encode([self::headerRow()]);
    }

    /**
     * @return array{
     *   csv: string,
     *   count: int
     * }
     */
    public static function export(PDO $pdo, ?string $type = null, ?string $locationId = null): array
    {
        $type = $type !== null ? trim($type) : '';
        $locationId = $locationId !== null ? trim($locationId) : '';
        if ($type !== '') {
            $normalized = self::normalizeType($type);
            if ($normalized === null) {
                throw new InvalidArgumentException('내보낼 유형을 확인하세요.');
            }
            $type = $normalized;
        }

        $sql = 'SELECT c.* FROM catalog_items c';
        $params = [];
        if ($locationId !== '') {
            $sql .= ' WHERE (
                EXISTS (SELECT 1 FROM stock_lots s WHERE s.catalog_item_id = c.id AND s.location_id = ?)
                OR EXISTS (SELECT 1 FROM assets a WHERE a.catalog_item_id = c.id AND a.location_id = ?)
            )';
            $params[] = $locationId;
            $params[] = $locationId;
            if ($type !== '') {
                $sql .= ' AND c.type = ?';
                $params[] = $type;
            }
        } elseif ($type !== '') {
            $sql .= ' WHERE c.type = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY c.name, c.id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = [self::headerRow()];
        foreach ($items as $item) {
            $place = self::primaryPlace($pdo, (string) $item['id'], (string) $item['type']);
            $tags = Support::jsonDecode(isset($item['tags']) ? (string) $item['tags'] : '[]');
            $tagList = [];
            foreach ($tags as $tag) {
                if (is_string($tag) && trim($tag) !== '') {
                    $tagList[] = trim($tag);
                }
            }
            $rows[] = [
                (string) $item['id'],
                (string) $item['name'],
                Support::typeLabel((string) $item['type']),
                (string) ($item['description'] ?? ''),
                implode(',', $tagList),
                (string) ($item['unit'] ?? 'ea'),
                $item['min_stock'] === null || $item['min_stock'] === '' ? '' : (string) $item['min_stock'],
                (string) ($item['edufine_number'] ?? ''),
                (string) ($item['manufacturer'] ?? ''),
                !empty($item['favorite']) ? '1' : '0',
                $place['location_name'],
                $place['quantity'] === '' ? '' : (string) $place['quantity'],
                $place['management_number'],
                (string) ($item['budget_program'] ?? ''),
                $item['budget_year'] === null || $item['budget_year'] === '' ? '' : (string) $item['budget_year'],
            ];
        }

        return ['csv' => self::encode($rows), 'count' => count($items)];
    }

    /**
     * @return array{
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   rows: int,
     *   skipped_rows: list<array{line: int, name: string, reason: string}>
     * }
     */
    public static function import(PDO $pdo, array $actor, string $csv, string $filename = 'upload.csv'): array
    {
        if (!Auth::canWrite($actor) || ($actor['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('가져오기 권한이 없습니다. 담당교사만 사용할 수 있습니다.');
        }

        $filename = strtolower(trim($filename));
        if (preg_match('/\.(xlsx|xls)$/', $filename) === 1) {
            throw new InvalidArgumentException('xlsx/xls는 지원하지 않습니다. Excel에서 CSV UTF-8로 저장한 뒤 올리세요.');
        }
        if (str_starts_with($csv, 'PK')) {
            throw new InvalidArgumentException('xlsx/xls는 지원하지 않습니다. Excel에서 CSV UTF-8로 저장한 뒤 올리세요.');
        }

        $text = self::toUtf8($csv);
        $matrix = self::decode($text);
        if ($matrix === []) {
            throw new InvalidArgumentException('CSV에 읽을 행이 없습니다.');
        }

        $header = array_shift($matrix);
        $map = self::mapHeader($header);
        if (!isset($map['name'])) {
            throw new InvalidArgumentException('첫 행에 품명(name) 열이 필요합니다.');
        }
        if (count($matrix) > self::MAX_ROWS) {
            throw new InvalidArgumentException('한 번에 ' . self::MAX_ROWS . '행까지 가져올 수 있습니다.');
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $skippedRows = [];
        $seenIds = [];

        foreach ($matrix as $offset => $cells) {
            $line = $offset + 2;
            $row = self::rowAssoc($map, $cells);
            if (self::isBlankRow($row)) {
                continue;
            }

            $id = trim((string) ($row['id'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            try {
                if ($id !== '') {
                    if (isset($seenIds[$id])) {
                        throw new InvalidArgumentException('같은 파일에서 품목 ID가 중복됩니다.');
                    }
                    $seenIds[$id] = true;
                    self::updateRow($pdo, $actor, $id, $row);
                    $updated++;
                } else {
                    self::createRow($pdo, $actor, $row);
                    $created++;
                }
            } catch (InvalidArgumentException $e) {
                $skipped++;
                $skippedRows[] = [
                    'line' => $line,
                    'name' => $name !== '' ? $name : $id,
                    'reason' => $e->getMessage(),
                ];
            } catch (Throwable $e) {
                $skipped++;
                $skippedRows[] = [
                    'line' => $line,
                    'name' => $name !== '' ? $name : $id,
                    'reason' => '이 행은 저장하지 못했습니다.',
                ];
            }
        }

        $t = Support::now();
        $pdo->prepare(
            'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        )->execute([
            Support::id('log'),
            'import',
            'catalog',
            'csv',
            $actor['id'] ?? null,
            $actor['display_name'] ?? '시스템',
            "품목 CSV 가져오기 · 신규 {$created} · 수정 {$updated} · 건너뜀 {$skipped}",
            json_encode([
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'filename' => $filename,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $t,
        ]);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'rows' => $created + $updated + $skipped,
            'skipped_rows' => $skippedRows,
        ];
    }

    public static function encode(array $rows): string
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new InvalidArgumentException('CSV를 만들지 못했습니다.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            fputcsv($fp, array_map(static fn (mixed $v): string => (string) $v, $row), ',', '"', '\\');
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return is_string($csv) ? $csv : '';
    }

    /**
     * @return list<list<string>>
     */
    public static function decode(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $separator = ',';
        $firstLine = strtok($text, "\n") ?: '';
        if (!str_contains($firstLine, ',') && str_contains($firstLine, ';')) {
            $separator = ';';
        }

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new InvalidArgumentException('CSV를 읽지 못했습니다.');
        }
        fwrite($fp, $text);
        rewind($fp);
        $rows = [];
        while (($row = fgetcsv($fp, 0, $separator, '"', '\\')) !== false) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = is_string($cell) ? trim($cell) : '';
            }
            $rows[] = $cells;
        }
        fclose($fp);
        return $rows;
    }

    public static function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, self::BOM)) {
            $raw = substr($raw, strlen(self::BOM));
        }
        if ($raw !== '' && mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'CP949');
        if (!is_string($converted) || $converted === '') {
            throw new InvalidArgumentException('파일 인코딩을 읽지 못했습니다. CSV UTF-8로 저장하세요.');
        }
        return $converted;
    }

    /**
     * @param list<string> $header
     * @return array<string, int>
     */
    public static function mapHeader(array $header): array
    {
        $aliases = self::headerAliases();
        $map = [];
        foreach ($header as $index => $label) {
            $key = self::normalizeHeader((string) $label);
            if ($key === '' || !isset($aliases[$key])) {
                continue;
            }
            $canonical = $aliases[$key];
            if (!isset($map[$canonical])) {
                $map[$canonical] = (int) $index;
            }
        }
        return $map;
    }

    /**
     * @param array<string, int> $map
     * @param list<string> $cells
     * @return array<string, string>
     */
    private static function rowAssoc(array $map, array $cells): array
    {
        $row = [];
        foreach ($map as $col => $index) {
            $row[$col] = isset($cells[$index]) ? (string) $cells[$index] : '';
        }
        return $row;
    }

    /**
     * @param array<string, string> $row
     */
    private static function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, string> $row
     */
    private static function updateRow(PDO $pdo, array $actor, string $itemId, array $row): void
    {
        $stmt = $pdo->prepare('SELECT * FROM catalog_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new InvalidArgumentException('품목 ID를 찾을 수 없습니다.');
        }

        $typeIn = trim((string) ($row['type'] ?? ''));
        if ($typeIn !== '') {
            $type = self::normalizeType($typeIn);
            if ($type === null) {
                throw new InvalidArgumentException('유형은 장비/비품/소모품/부품만 사용할 수 있습니다.');
            }
            if ($type !== (string) $item['type']) {
                throw new InvalidArgumentException('유형은 바꿀 수 없습니다.');
            }
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = (string) $item['name'];
        }

        $tags = array_key_exists('tags', $row)
            ? self::splitTags($row['tags'])
            : Support::jsonDecode((string) ($item['tags'] ?? '[]'));
        $unit = array_key_exists('unit', $row) && trim($row['unit']) !== ''
            ? trim($row['unit'])
            : (string) ($item['unit'] ?? 'ea');
        $description = array_key_exists('description', $row)
            ? (trim($row['description']) === '' ? null : trim($row['description']))
            : (isset($item['description']) ? (string) $item['description'] : null);
        $minStock = array_key_exists('min_stock', $row) ? $row['min_stock'] : ($item['min_stock'] ?? '');
        $edufine = array_key_exists('edufine_number', $row)
            ? (trim($row['edufine_number']) === '' ? null : trim($row['edufine_number']))
            : (isset($item['edufine_number']) ? (string) $item['edufine_number'] : null);
        $manufacturer = array_key_exists('manufacturer', $row)
            ? (trim($row['manufacturer']) === '' ? null : trim($row['manufacturer']))
            : (isset($item['manufacturer']) ? (string) $item['manufacturer'] : null);
        $favorite = array_key_exists('favorite', $row)
            ? self::parseFavorite($row['favorite'])
            : !empty($item['favorite']);
        $budgetProgram = array_key_exists('budget_program', $row)
            ? (trim($row['budget_program']) === '' ? null : trim($row['budget_program']))
            : (isset($item['budget_program']) ? (string) $item['budget_program'] : null);
        $budgetYear = array_key_exists('budget_year', $row)
            ? $row['budget_year']
            : ($item['budget_year'] ?? '');

        Catalog::update(
            $pdo,
            $actor,
            $itemId,
            $name,
            $description,
            is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
            $unit,
            $minStock,
            $edufine,
            $manufacturer,
            null,
            $favorite,
            $budgetProgram,
            $budgetYear,
        );
    }

    /**
     * @param array<string, string> $row
     */
    private static function createRow(PDO $pdo, array $actor, array $row): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        $typeIn = trim((string) ($row['type'] ?? ''));
        if ($name === '' || $typeIn === '') {
            throw new InvalidArgumentException('신규 행은 품명과 유형이 필요합니다.');
        }
        $type = self::normalizeType($typeIn);
        if ($type === null) {
            throw new InvalidArgumentException('유형은 장비/비품/소모품/부품만 사용할 수 있습니다.');
        }

        $locationName = trim((string) ($row['location_name'] ?? ''));
        $locationId = null;
        if ($locationName !== '') {
            $locationId = self::resolveLocation($pdo, $locationName);
        }

        $quantityRaw = trim((string) ($row['quantity'] ?? ''));
        $quantity = 1;
        if ($quantityRaw !== '') {
            if (!is_numeric($quantityRaw)) {
                throw new InvalidArgumentException('수량은 0보다 큰 숫자로 입력하세요.');
            }
            $quantity = (float) $quantityRaw;
            if (!is_finite($quantity) || $quantity <= 0) {
                throw new InvalidArgumentException('수량은 0보다 큰 숫자로 입력하세요.');
            }
        } elseif ($locationId === null) {
            $quantity = 0.0;
        }

        if ($locationId !== null && $quantity <= 0) {
            throw new InvalidArgumentException('위치가 있으면 수량은 1 이상이어야 합니다.');
        }

        $unit = trim((string) ($row['unit'] ?? ''));
        if ($unit === '') {
            $unit = 'ea';
        }
        $description = trim((string) ($row['description'] ?? ''));
        $description = $description === '' ? null : $description;
        $edufine = trim((string) ($row['edufine_number'] ?? ''));
        $edufine = $edufine === '' ? null : $edufine;
        $manufacturer = trim((string) ($row['manufacturer'] ?? ''));
        $manufacturer = $manufacturer === '' ? null : $manufacturer;
        $minStock = array_key_exists('min_stock', $row) ? $row['min_stock'] : '';
        $minStock = self::parseOptionalMinStock($minStock);
        $tags = self::splitTags((string) ($row['tags'] ?? ''));
        $favorite = self::parseFavorite((string) ($row['favorite'] ?? ''));
        $budgetProgram = Budget::parseProgram($row['budget_program'] ?? null);
        $budgetYear = Budget::parseYear($row['budget_year'] ?? null);
        $mgmt = trim((string) ($row['management_number'] ?? ''));

        $t = Support::now();
        $catalogId = Support::id('ci');

        self::beginImmediate($pdo);
        try {
            $pdo->prepare(
                'INSERT INTO catalog_items(id,name,type,description,tags,unit,min_stock,edufine_number,manufacturer,budget_program,budget_year,favorite,qr_code,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $catalogId,
                $name,
                $type,
                $description,
                json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $unit,
                $minStock,
                $edufine,
                $manufacturer,
                $budgetProgram,
                $budgetYear,
                $favorite ? 1 : 0,
                Support::qr('CAT', $catalogId),
                $t,
                $t,
            ]);

            if ($locationId !== null) {
                if ($type === 'equipment') {
                    $count = max(1, (int) round($quantity));
                    for ($i = 0; $i < $count; $i++) {
                        $aid = Support::id('ast');
                        $number = $mgmt !== ''
                            ? ($count === 1 ? $mgmt : $mgmt . '-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT))
                            : 'MGMT-' . strtoupper(substr($aid, -8));
                        $aname = $count === 1 ? $name : $name . ' #' . ($i + 1);
                        $pdo->prepare(
                            'INSERT INTO assets(id,catalog_item_id,name,management_number,edufine_number,status,location_id,tags,budget_program,budget_year,notes,qr_code,created_at,updated_at)
                             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
                        )->execute([
                            $aid,
                            $catalogId,
                            $aname,
                            $number,
                            $edufine,
                            'available',
                            $locationId,
                            json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            $budgetProgram,
                            $budgetYear,
                            $description,
                            Support::qr('AST', $aid),
                            $t,
                            $t,
                        ]);
                    }
                } else {
                    $pdo->prepare(
                        'INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES(?,?,?,?,?)'
                    )->execute([Support::id('lot'), $catalogId, $locationId, $quantity, $t]);
                }
            }

            $pdo->prepare(
                'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
            )->execute([
                Support::id('log'),
                'create',
                'catalog',
                $catalogId,
                $actor['id'] ?? null,
                $actor['display_name'] ?? '시스템',
                "«{$name}» CSV 등록",
                json_encode([
                    'source' => 'csv',
                    'type' => $type,
                    'location_id' => $locationId,
                    'quantity' => $quantity,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $t,
            ]);
            self::commitImmediate($pdo);
        } catch (Throwable $e) {
            self::rollBackImmediate($pdo);
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            $msg = $e->getMessage();
            if (str_contains($msg, 'UNIQUE') || str_contains($msg, 'unique')) {
                throw new InvalidArgumentException('관리번호 또는 QR이 이미 있습니다.');
            }
            throw new InvalidArgumentException('이 행은 저장하지 못했습니다.');
        }
    }

    private static function resolveLocation(PDO $pdo, string $needle): string
    {
        $stmt = $pdo->prepare(
            'SELECT id, name, code FROM locations WHERE name = ? OR code = ? ORDER BY kind, name'
        );
        $stmt->execute([$needle, $needle]);
        $hits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($hits) === 1) {
            return (string) $hits[0]['id'];
        }
        if ($hits === []) {
            throw new InvalidArgumentException('위치를 찾을 수 없습니다: ' . $needle);
        }
        throw new InvalidArgumentException('위치 이름이 여러 곳과 맞습니다: ' . $needle);
    }

    /**
     * @return array{location_name: string, quantity: string, management_number: string}
     */
    private static function primaryPlace(PDO $pdo, string $itemId, string $type): array
    {
        if ($type === 'equipment') {
            $stmt = $pdo->prepare(
                'SELECT a.management_number, l.name AS location_name
                 FROM assets a JOIN locations l ON l.id = a.location_id
                 WHERE a.catalog_item_id = ? ORDER BY a.management_number LIMIT 1'
            );
            $stmt->execute([$itemId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE catalog_item_id = ?');
            $cntStmt->execute([$itemId]);
            $count = (int) $cntStmt->fetchColumn();
            return [
                'location_name' => is_array($row) ? (string) $row['location_name'] : '',
                'quantity' => $count > 0 ? (string) $count : '',
                'management_number' => is_array($row) ? (string) $row['management_number'] : '',
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT s.quantity, l.name AS location_name
             FROM stock_lots s JOIN locations l ON l.id = s.location_id
             WHERE s.catalog_item_id = ? ORDER BY s.quantity DESC, l.name LIMIT 1'
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock_lots WHERE catalog_item_id = ?');
        $sumStmt->execute([$itemId]);
        $sum = (float) $sumStmt->fetchColumn();
        return [
            'location_name' => is_array($row) ? (string) $row['location_name'] : '',
            'quantity' => $sum > 0 ? (string) $sum : '',
            'management_number' => '',
        ];
    }

    /**
     * @return list<string>
     */
    private static function splitTags(string $raw): array
    {
        $parts = preg_split('/[,;|]/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    public static function normalizeType(string $type): ?string
    {
        $key = self::normalizeHeader($type);
        return self::typeAliases()[$key] ?? null;
    }

    private static function parseFavorite(string $value): bool
    {
        $key = self::normalizeHeader($value);
        if (in_array($key, ['1', 'true', 'yes', 'y', 'on', '예', 'ㅇ'], true)) {
            return true;
        }
        return false;
    }

    private static function parseOptionalMinStock(mixed $minStock): ?float
    {
        if ($minStock === null || $minStock === '') {
            return null;
        }
        if ((!is_string($minStock) && !is_int($minStock) && !is_float($minStock)) || !is_numeric($minStock)) {
            throw new InvalidArgumentException('최소재고는 0 이상의 숫자로 입력하세요.');
        }
        $minStock = (float) $minStock;
        if (!is_finite($minStock) || $minStock < 0) {
            throw new InvalidArgumentException('최소재고는 0 이상의 숫자로 입력하세요.');
        }
        return $minStock;
    }

    private static function normalizeHeader(string $label): string
    {
        $label = trim($label);
        $label = str_replace(["\u{00A0}", ' '], '', $label);
        return mb_strtolower($label);
    }

    /**
     * @return array<string, string>
     */
    private static function headerAliases(): array
    {
        $aliases = [];
        foreach (self::COLUMNS as $col) {
            $aliases[self::normalizeHeader($col)] = $col;
            $aliases[self::normalizeHeader(str_replace('_', '', $col))] = $col;
            $aliases[self::normalizeHeader(self::HEADER_LABELS[$col])] = $col;
        }
        foreach ([
            '품목id' => 'id',
            '아이디' => 'id',
            '이름' => 'name',
            '품목명' => 'name',
            '타입' => 'type',
            '종류' => 'type',
            '메모' => 'description',
            '에듀파인' => 'edufine_number',
            '자산번호' => 'edufine_number',
            '실' => 'location_name',
            '장소' => 'location_name',
            '위치명' => 'location_name',
            '구입사업명' => 'budget_program',
            '사업예산' => 'budget_program',
            '예산사업' => 'budget_program',
            '연도' => 'budget_year',
            '년도' => 'budget_year',
            '예산년도' => 'budget_year',
            '구입년도' => 'budget_year',
            '구입연도' => 'budget_year',
        ] as $alias => $col) {
            $aliases[self::normalizeHeader($alias)] = $col;
        }
        return $aliases;
    }

    /**
     * @return array<string, string>
     */
    private static function typeAliases(): array
    {
        return [
            'equipment' => 'equipment',
            '장비' => 'equipment',
            '기자재' => 'equipment',
            'fixture' => 'fixture',
            '비품' => 'fixture',
            'consumable' => 'consumable',
            '소모품' => 'consumable',
            'part' => 'part',
            '부품' => 'part',
            '재료' => 'part',
        ];
    }

    private static function beginImmediate(PDO $pdo): void
    {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('BEGIN IMMEDIATE');
    }

    private static function commitImmediate(PDO $pdo): void
    {
        $pdo->exec('COMMIT');
    }

    private static function rollBackImmediate(PDO $pdo): void
    {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // Transaction may already be closed after a constraint abort.
        }
    }
}
