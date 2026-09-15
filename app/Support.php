<?php

declare(strict_types=1);

namespace Inni;

final class Support
{
    public static function id(string $prefix = ''): string
    {
        $uuid = bin2hex(random_bytes(8));
        return $prefix !== '' ? $prefix . '_' . $uuid : $uuid;
    }

    public static function now(): string
    {
        return gmdate('c');
    }

    public static function qr(string $kind, string $id): string
    {
        return strtoupper($kind) . ':' . $id;
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function jsonDecode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    public static function formatWhen(?string $iso): string
    {
        if (!$iso) {
            return '—';
        }
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        return date('n월 j일 H:i', $ts);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'equipment' => '장비',
            'fixture' => '비품',
            'consumable' => '소모품',
            'part' => '부품',
            default => $type,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'available' => '보관중',
            'on_loan' => '대여중',
            'repair' => '수리중',
            'moving' => '이동중',
            'lost' => '분실',
            'retired' => '폐기',
            'active' => '대여중',
            'returned' => '반납',
            'overdue' => '연체',
            'open' => '접수',
            'in_progress' => '수리중',
            'done' => '완료',
            'impossible' => '불가',
            'confirmed' => '확인',
            'unchecked' => '미확인',
            default => $status,
        };
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'building' => '건물',
            'room' => '실',
            'zone' => '구역',
            'storage' => '보관함',
            'bin' => '칸',
            default => $kind,
        };
    }

    public static function locationPath(\PDO $pdo, string $locationId): string
    {
        $names = [];
        $id = $locationId;
        $guard = 0;
        $stmt = $pdo->prepare('SELECT id, name, parent_id FROM locations WHERE id = ?');
        while ($id && $guard++ < 20) {
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                break;
            }
            array_unshift($names, $row['name']);
            $id = $row['parent_id'];
        }
        return implode(' · ', $names);
    }

    public static function descendantIds(\PDO $pdo, string $rootId): array
    {
        $all = $pdo->query('SELECT id, parent_id FROM locations')->fetchAll();
        $ids = [$rootId => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($all as $row) {
                if ($row['parent_id'] && isset($ids[$row['parent_id']]) && !isset($ids[$row['id']])) {
                    $ids[$row['id']] = true;
                    $changed = true;
                }
            }
        }
        return array_keys($ids);
    }
}
