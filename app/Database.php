<?php

declare(strict_types=1);

namespace Inni;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $path = App::config('db_path') ?: (App::root() . '/data/inni.sqlite');
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create data directory: ' . $dir);
        }

        $isNew = !file_exists($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        if ($isNew) {
            $schema = file_get_contents(App::root() . '/sql/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('schema.sql missing');
            }
            $pdo->exec($schema);
            self::$pdo = $pdo;
            Seed::run($pdo);
        } else {
            self::$pdo = $pdo;
            self::migrate($pdo);
        }

        return self::$pdo;
    }

    /**
     * Idempotent guards for databases created before later schema additions.
     */
    public static function migrate(PDO $pdo): void
    {
        self::ensureGuards($pdo);
    }

    private static function ensureGuards(PDO $pdo): void
    {
        self::ensureColumn($pdo, 'catalog_items', 'budget_program', 'TEXT');
        self::ensureColumn($pdo, 'catalog_items', 'budget_year', 'INTEGER');
        self::ensureColumn($pdo, 'assets', 'budget_program', 'TEXT');
        self::ensureColumn($pdo, 'assets', 'budget_year', 'INTEGER');
        self::ensureColumn($pdo, 'assets', 'useful_life_years', 'INTEGER');
        if (self::tableExists($pdo, 'stock_lots')) {
            self::ensureColumn($pdo, 'stock_lots', 'lot_code', 'TEXT');
            self::ensureColumn($pdo, 'stock_lots', 'expires_at', 'TEXT');
            self::ensureColumn($pdo, 'stock_lots', 'received_at', 'TEXT');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catalog_budget ON catalog_items(budget_year, budget_program)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assets_budget ON assets(budget_year, budget_program)');

        $pdo->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_loans_one_open_asset
             ON loans(asset_id)
             WHERE asset_id IS NOT NULL AND status IN ('active','overdue')"
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS stock_issue_cancels (
              id TEXT PRIMARY KEY,
              issue_log_id TEXT NOT NULL UNIQUE,
              catalog_item_id TEXT NOT NULL REFERENCES catalog_items(id),
              lot_id TEXT NOT NULL,
              location_id TEXT NOT NULL REFERENCES locations(id),
              quantity REAL NOT NULL,
              reason TEXT NOT NULL,
              actor_id TEXT,
              actor_name TEXT NOT NULL,
              created_at TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_stock_issue_cancels_item
             ON stock_issue_cancels(catalog_item_id, created_at)'
        );
        self::ensureReportsRejectedStatus($pdo);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS alert_dispatches (
              event_key TEXT NOT NULL,
              entity_id TEXT NOT NULL,
              sent_at TEXT NOT NULL,
              PRIMARY KEY (event_key, entity_id)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_checks (
              id TEXT PRIMARY KEY,
              location_id TEXT NOT NULL REFERENCES locations(id),
              location_name TEXT NOT NULL,
              status TEXT NOT NULL DEFAULT \'active\' CHECK(status IN (\'active\',\'done\')),
              started_by TEXT NOT NULL REFERENCES users(id),
              started_at TEXT NOT NULL,
              finished_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_check_lines (
              id TEXT PRIMARY KEY,
              check_id TEXT NOT NULL REFERENCES inventory_checks(id) ON DELETE CASCADE,
              kind TEXT NOT NULL CHECK(kind IN (\'asset\',\'item\')),
              asset_id TEXT REFERENCES assets(id),
              catalog_item_id TEXT REFERENCES catalog_items(id),
              stock_lot_id TEXT,
              location_id TEXT NOT NULL REFERENCES locations(id),
              name TEXT NOT NULL,
              code TEXT,
              expected_qty REAL NOT NULL DEFAULT 1,
              unit TEXT,
              confirmed_at TEXT,
              confirmed_by TEXT REFERENCES users(id)
            )'
        );
        $pdo->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_inventory_checks_one_active
             ON inventory_checks(status)
             WHERE status = 'active'"
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_inventory_lines_check
             ON inventory_check_lines(check_id, confirmed_at)'
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_inventory_lines_asset
             ON inventory_check_lines(check_id, asset_id)
             WHERE asset_id IS NOT NULL'
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_inventory_lines_lot
             ON inventory_check_lines(check_id, stock_lot_id)
             WHERE stock_lot_id IS NOT NULL'
        );
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param 'catalog_items'|'assets'|'stock_lots' $table
     */
    private static function ensureColumn(PDO $pdo, string $table, string $column, string $type): void
    {
        $allowed = [
            'catalog_items' => ['budget_program' => 'TEXT', 'budget_year' => 'INTEGER'],
            'assets' => [
                'budget_program' => 'TEXT',
                'budget_year' => 'INTEGER',
                'useful_life_years' => 'INTEGER',
            ],
            'stock_lots' => [
                'lot_code' => 'TEXT',
                'expires_at' => 'TEXT',
                'received_at' => 'TEXT',
            ],
        ];
        if (!isset($allowed[$table][$column]) || $allowed[$table][$column] !== $type) {
            throw new RuntimeException('Refusing unknown schema patch: ' . $table . '.' . $column);
        }
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($cols as $col) {
            if (($col['name'] ?? '') === $column) {
                return;
            }
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
    }

    /**
     * Existing DBs created before #46 only allow open/in_progress/done.
     * SQLite cannot ALTER a CHECK, so rebuild when `rejected` is missing.
     */
    private static function ensureReportsRejectedStatus(PDO $pdo): void
    {
        $exists = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'reports'"
        )->fetchColumn();
        if ($exists === false || $exists === null) {
            return;
        }
        $sql = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'reports'"
        )->fetchColumn();
        if (is_string($sql) && str_contains($sql, "'rejected'")) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_target ON reports(target_type, target_id)');
            return;
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $pdo->exec(
                "CREATE TABLE reports__new (
                  id TEXT PRIMARY KEY,
                  target_type TEXT NOT NULL CHECK(target_type IN ('room','asset')),
                  target_id TEXT NOT NULL,
                  reporter_user_id TEXT REFERENCES users(id),
                  reporter_name TEXT NOT NULL,
                  title TEXT NOT NULL,
                  body TEXT NOT NULL,
                  image_path TEXT,
                  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','done','rejected')),
                  created_at TEXT NOT NULL,
                  updated_at TEXT NOT NULL
                )"
            );
            $pdo->exec(
                'INSERT INTO reports__new(
                    id,target_type,target_id,reporter_user_id,reporter_name,title,body,image_path,status,created_at,updated_at
                 )
                 SELECT id,target_type,target_id,reporter_user_id,reporter_name,title,body,image_path,status,created_at,updated_at
                 FROM reports'
            );
            $pdo->exec('DROP TABLE reports');
            $pdo->exec('ALTER TABLE reports__new RENAME TO reports');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_target ON reports(target_type, target_id)');
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
