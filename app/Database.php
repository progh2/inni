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
            self::ensureGuards($pdo);
        }

        return self::$pdo;
    }

    /**
     * Idempotent guards for databases created before later schema additions.
     */
    private static function ensureGuards(PDO $pdo): void
    {
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
}
