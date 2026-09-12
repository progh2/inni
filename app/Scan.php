<?php

declare(strict_types=1);

namespace Inni;

use PDO;

final class Scan
{
    /**
     * Resolve a scanned or typed payload the same way as issue #7.
     *
     * @return array{kind: 'asset'|'catalog'|'location', id: string}|null
     */
    public static function lookup(PDO $pdo, string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        if (preg_match('/^(AST|CAT|LOC):(.+)$/i', $code, $m)) {
            $kind = strtoupper($m[1]);
            $id = $m[2];
            if ($kind === 'AST') {
                return ['kind' => 'asset', 'id' => $id];
            }
            if ($kind === 'CAT') {
                return ['kind' => 'catalog', 'id' => $id];
            }
            return ['kind' => 'location', 'id' => $id];
        }

        $stmt = $pdo->prepare('SELECT id FROM assets WHERE management_number = ? OR qr_code = ? LIMIT 1');
        $stmt->execute([$code, $code]);
        if ($id = $stmt->fetchColumn()) {
            return ['kind' => 'asset', 'id' => (string) $id];
        }

        $stmt = $pdo->prepare('SELECT id FROM locations WHERE code = ? OR qr_code = ? LIMIT 1');
        $stmt->execute([$code, $code]);
        if ($id = $stmt->fetchColumn()) {
            return ['kind' => 'location', 'id' => (string) $id];
        }

        $stmt = $pdo->prepare('SELECT id FROM catalog_items WHERE qr_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if ($id = $stmt->fetchColumn()) {
            return ['kind' => 'catalog', 'id' => (string) $id];
        }

        return null;
    }
}
