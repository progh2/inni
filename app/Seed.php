<?php

declare(strict_types=1);

namespace Inni;

use PDO;

final class Seed
{
    public const DEMO_OWNER_ID = 'demo-owner';
    public const DEMO_TEACHER_ID = 'demo-teacher';

    /** Local convenience accounts; they do not occupy the first-Google-owner slot. */
    public static function demoUserIds(): array
    {
        return [self::DEMO_OWNER_ID, self::DEMO_TEACHER_ID];
    }

    public static function isDemoUserId(string $id): bool
    {
        return in_array($id, self::demoUserIds(), true);
    }

    public static function run(PDO $pdo): void
    {
        $t = Support::now();
        $ownerId = self::DEMO_OWNER_ID;
        $teacherId = self::DEMO_TEACHER_ID;

        $pdo->prepare(
            'INSERT INTO settings(key, value) VALUES(?, ?), (?, ?), (?, ?)'
        )->execute([
            'school_name', App::config('school_name', 'inni 데모 마이스터고'),
            'ai_provider', 'none',
            'label_paper', 'a4-grid',
        ]);

        $pdo->prepare(
            'INSERT INTO users(id,email,display_name,role,status,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?), (?,?,?,?,?,?,?)'
        )->execute([
            $ownerId, 'owner@demo.inni', '김담당', 'owner', 'active', $t, $t,
            $teacherId, 'teacher@demo.inni', '이수업', 'teacher', 'active', $t, $t,
        ]);

        $locations = [
            ['loc-silseup', '실습동', 'building', null, null],
            ['loc-main', '본관', 'building', null, null],
            ['loc-elec', '전자실습실', 'room', 'loc-silseup', 'E-201'],
            ['loc-weld', '용접실', 'room', 'loc-silseup', 'W-103'],
            ['loc-auto', '자동차엔진실', 'room', 'loc-silseup', 'A-110'],
            ['loc-tool', '공구실', 'room', 'loc-silseup', 'T-001'],
            ['loc-store', '기자재창고', 'room', 'loc-silseup', 'S-B1'],
            ['loc-office', '교무실', 'room', 'loc-main', '1-12'],
            ['loc-tool-a', '선반 A', 'storage', 'loc-tool', null],
            ['loc-tool-b', '선반 B', 'storage', 'loc-tool', null],
            ['loc-elec-cab', '계측기 캐비닛', 'storage', 'loc-elec', null],
        ];

        $insLoc = $pdo->prepare(
            'INSERT INTO locations(id,name,kind,parent_id,code,sort_order,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        );
        $i = 0;
        foreach ($locations as [$id, $name, $kind, $parent, $code]) {
            $insLoc->execute([$id, $name, $kind, $parent, $code, $i++, Support::qr('LOC', $id), $t, $t]);
        }

        $catalog = [
            ['ci-scope', '디지털 오실로스코프', 'equipment', 'Rigol', '["계측"]'],
            ['ci-dmm', '디지털 멀티미터', 'equipment', 'Fluke', '["계측"]'],
            ['ci-cnc', 'CNC 밀링', 'equipment', '화천', '["가공"]'],
            ['ci-welder', 'CO2 용접기', 'equipment', '현대웰딩', '["용접"]'],
            ['ci-torque', '토크렌치', 'equipment', '토네', '["공구"]'],
            ['ci-drill', '충전드릴', 'equipment', '보쉬', '["공구"]'],
            ['ci-chair', '실습 의자', 'fixture', null, '["가구"]'],
            ['ci-solder', '납땜 실납', 'consumable', null, '["소모"]', 'm', 5],
            ['ci-wire', '전선 1.5sq', 'consumable', null, '["소모"]', 'm', 20],
            ['ci-bit', '드릴비트 세트', 'consumable', null, '["소모"]', 'ea', 2],
            ['ci-res', '저항 1/4W 키트', 'part', null, '["부품"]', 'ea', 1],
        ];

        $insCat = $pdo->prepare(
            'INSERT INTO catalog_items(id,name,type,manufacturer,tags,unit,min_stock,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($catalog as $row) {
            $unit = $row[5] ?? 'ea';
            $min = $row[6] ?? null;
            $insCat->execute([
                $row[0], $row[1], $row[2], $row[3], $row[4], $unit, $min,
                Support::qr('CAT', $row[0]), $t, $t,
            ]);
        }

        $assets = [
            ['ast-scope-1', 'ci-scope', '오실로스코프 #1', '전장-2023-001', 'loc-elec-cab', 'available', null],
            ['ast-scope-2', 'ci-scope', '오실로스코프 #2', '전장-2023-002', 'loc-elec', 'on_loan', null],
            ['ast-dmm-1', 'ci-dmm', '멀티미터 #1', '전장-2024-017', 'loc-elec-cab', 'available', '87V-SN-1001'],
            ['ast-dmm-2', 'ci-dmm', '멀티미터 #2', '전장-2024-018', 'loc-elec', 'on_loan', null],
            ['ast-cnc-1', 'ci-cnc', 'CNC 밀링 1호기', '기공-2021-001', 'loc-store', 'available', null],
            ['ast-weld-1', 'ci-welder', 'CO2 용접기 A', '용접-2022-004', 'loc-weld', 'repair', null],
            ['ast-torque-1', 'ci-torque', '토크렌치 1/2"', '자차-2024-009', 'loc-tool-b', 'available', null],
            ['ast-drill-1', 'ci-drill', '충전드릴 #1', '공통-2024-031', 'loc-tool-a', 'available', null],
            ['ast-drill-2', 'ci-drill', '충전드릴 #2', '공통-2024-032', 'loc-auto', 'on_loan', null],
        ];
        $insAst = $pdo->prepare(
            'INSERT INTO assets(id,catalog_item_id,name,management_number,serial_number,status,location_id,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($assets as $a) {
            $insAst->execute([$a[0], $a[1], $a[2], $a[3], $a[6], $a[5], $a[4], Support::qr('AST', $a[0]), $t, $t]);
        }

        $lots = [
            ['lot-solder', 'ci-solder', 'loc-elec', 18, 'SN-2025-11', '2028-11-30'],
            ['lot-wire', 'ci-wire', 'loc-store', 12, 'W-2026-03', '2027-03-31'],
            ['lot-bit', 'ci-bit', 'loc-tool-a', 1, null, null],
            ['lot-res', 'ci-res', 'loc-elec', 4, null, null],
            ['lot-chair', 'ci-chair', 'loc-elec', 24, null, null],
            ['lot-chair2', 'ci-chair', 'loc-weld', 16, null, null],
        ];
        $insLot = $pdo->prepare(
            'INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,lot_code,expires_at,updated_at)
             VALUES(?,?,?,?,?,?,?)'
        );
        foreach ($lots as $l) {
            $insLot->execute([$l[0], $l[1], $l[2], $l[3], $l[4], $l[5], $t]);
        }

        $dueSoon = gmdate('c', time() + 4 * 3600);
        $duePast = gmdate('c', time() - 26 * 3600);
        $returnedAt = gmdate('c', time() - 3 * 3600);
        $pdo->prepare(
            'INSERT INTO loans(id,kind,asset_id,quantity,borrower_user_id,borrower_name,borrower_note,from_location_id,due_at,returned_at,status,purpose,created_at,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?), (?,?,?,?,?,?,?,?,?,?,?,?,?,?), (?,?,?,?,?,?,?,?,?,?,?,?,?,?), (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            'loan-1', 'asset', 'ast-scope-2', 1, $teacherId, '이수업', '2학년 전자회로', 'loc-elec', $dueSoon, null, 'active', '5교시 실습', $t, $teacherId,
            'loan-2', 'asset', 'ast-drill-2', 1, null, '박학생', '3-2 / 프로젝트', 'loc-auto', $duePast, null, 'overdue', '야간자율 프로젝트', $t, $ownerId,
            'loan-3', 'asset', 'ast-dmm-2', 1, $teacherId, '이수업', '1학년 측정', 'loc-elec', $duePast, null, 'overdue', '방과후 계측', $t, $teacherId,
            'loan-4', 'asset', 'ast-torque-1', 1, $teacherId, '이수업', '반납 이력', 'loc-tool-b', $dueSoon, $returnedAt, 'returned', '수업 시연', $t, $teacherId,
        ]);

        $pdo->prepare(
            'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,created_at)
             VALUES(?,?,?,?,?,?,?,?)'
        )->execute([
            'log-1', 'seed', 'location', 'loc-elec', $ownerId, '시스템', '데모 데이터를 불러왔습니다.', $t,
        ]);

        $issueAt = gmdate('c', time() - 3600);
        $pdo->prepare(
            'INSERT INTO activity_logs(id,action,entity_type,entity_id,actor_id,actor_name,summary,meta_json,created_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        )->execute([
            'log-issue-wire',
            'issue',
            'catalog',
            'ci-wire',
            $teacherId,
            '이수업',
            '«전선 1.5sq» 2 m 분출 · 전자실습실 · 5교시 실습',
            json_encode([
                'lot_id' => 'lot-wire',
                'location_id' => 'loc-store',
                'quantity' => 2,
                'remaining_quantity' => 12,
                'purpose' => '5교시 실습',
                'room_id' => 'loc-elec',
                'room_name' => '전자실습실',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $issueAt,
        ]);

        $pdo->prepare(
            'INSERT INTO reports(id,target_type,target_id,reporter_user_id,reporter_name,title,body,status,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            'rep-1', 'asset', 'ast-weld-1', $ownerId, '김담당', '송급 불량',
            '용접 중 와이어 송급이 끊깁니다. 점검 필요.', 'open', $t, $t,
        ]);
    }
}
