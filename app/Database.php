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
     * Idempotent guards for databases created before the open-loan unique index.
     */
    private static function ensureGuards(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_loans_one_open_asset
             ON loans(asset_id)
             WHERE asset_id IS NOT NULL AND status IN ('active','overdue')"
        );
    }
}
