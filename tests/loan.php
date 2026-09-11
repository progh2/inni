<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Auth;
use Inni\Loan;

$root = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents($root . '/sql/schema.sql'));
seedLoanFixture($pdo);

$owner = actor('owner', 'owner');
$manager = actor('manager', 'manager');
$teacher = actor('teacher', 'teacher');
$teacherB = actor('teacher-b', 'teacher');
$student = actor('student', 'student');
$disabled = actor('disabled', 'teacher', 'disabled');
$checks = 0;

function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function actor(string $id, string $role, string $status = 'active'): array
{
    return ['id' => $id, 'display_name' => $id, 'role' => $role, 'status' => $status];
}

function seedLoanFixture(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','ROOM:test','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,qr_code,created_at,updated_at) VALUES('eq','오실로스코프','equipment','CAT:eq','t','t')");
    foreach (['owner' => 'owner', 'manager' => 'manager', 'teacher' => 'teacher', 'teacher-b' => 'teacher', 'student' => 'student'] as $id => $role) {
        $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '@test', $id, $role, 'active', 't', 't']);
    }
    $pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')
        ->execute(['disabled', 'disabled@test', 'disabled', 'teacher', 'disabled', 't', 't']);
    foreach (['ast-1', 'ast-2', 'ast-3'] as $id) {
        $pdo->prepare(
            'INSERT INTO assets(id,catalog_item_id,name,management_number,status,location_id,qr_code,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?)'
        )->execute([$id, 'eq', $id, $id, 'available', 'room', 'AST:' . $id, 't', 't']);
    }
}

function snapshot(PDO $pdo): array
{
    return [
        $pdo->query('SELECT id, status FROM assets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT id, asset_id, status, borrower_user_id, created_by FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT action, entity_id FROM activity_logs ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function reject(callable $fn, PDO $pdo, string $message): void
{
    $before = snapshot($pdo);
    try {
        $fn();
        throw new RuntimeException($message . ' was accepted');
    } catch (InvalidArgumentException) {
        check(snapshot($pdo) === $before, $message . ' changed state');
    }
}

function openCounts(PDO $pdo, string $assetId): array
{
    $asset = $pdo->prepare('SELECT status FROM assets WHERE id = ?');
    $asset->execute([$assetId]);
    $open = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE asset_id = ? AND status IN ('active','overdue')");
    $open->execute([$assetId]);
    return [(string) $asset->fetchColumn(), (int) $open->fetchColumn()];
}

function runWorker(string $dbPath, string $op, array $actor, string $target, string $startFile): array
{
    $tmp = sys_get_temp_dir() . '/inni-loan-race-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $runner = $tmp . '/run.php';
    $resultFile = $tmp . '/result.json';
    file_put_contents($tmp . '/payload.json', json_encode([
        'root' => dirname(__DIR__),
        'db' => $dbPath,
        'op' => $op,
        'actor' => $actor,
        'target' => $target,
        'start' => $startFile,
        'result' => $resultFile,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$p = json_decode(file_get_contents(__DIR__ . '/payload.json'), true, 512, JSON_THROW_ON_ERROR);
require $p['root'] . '/app/bootstrap.php';
use Inni\Loan;
for ($i = 0; $i < 2000 && !is_file($p['start']); $i++) {
    usleep(1000);
}
$pdo = new PDO('sqlite:' . $p['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
$ok = false;
$error = null;
try {
    if ($p['op'] === 'checkout') {
        Loan::checkout($pdo, $p['actor'], $p['target'], $p['actor']['display_name'], null, 'race', null);
    } else {
        Loan::checkin($pdo, $p['actor'], $p['target']);
    }
    $ok = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
}
file_put_contents($p['result'], json_encode(['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE));
PHP);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner)
        . ' >' . escapeshellarg($tmp . '/stdout.txt')
        . ' 2>' . escapeshellarg($tmp . '/stderr.txt');
    $proc = proc_open($cmd, [], $pipes, $tmp);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start loan worker');
    }
    return [$proc, $tmp, $resultFile];
}

function collectWorker(array $handle): array
{
    [$proc, $tmp, $resultFile] = $handle;
    $status = proc_get_status($proc);
    $deadline = microtime(true) + 8;
    while ($status['running'] && microtime(true) < $deadline) {
        usleep(20000);
        $status = proc_get_status($proc);
    }
    proc_close($proc);
    $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
    $stderr = is_file($tmp . '/stderr.txt') ? (string) file_get_contents($tmp . '/stderr.txt') : '';
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Loan worker failed: ' . $raw . $stderr);
    }
    return $decoded;
}

$openLoan = ['id' => 'ln', 'status' => 'active', 'borrower_user_id' => 'student', 'created_by' => 'teacher'];
$otherLoan = ['id' => 'ln2', 'status' => 'active', 'borrower_user_id' => 'teacher', 'created_by' => 'teacher'];
$returnedLoan = ['id' => 'ln3', 'status' => 'returned', 'borrower_user_id' => 'student', 'created_by' => 'teacher'];
check(Auth::canReturn($owner, $openLoan) && Auth::canReturn($manager, $openLoan) && Auth::canReturn($teacher, $openLoan), 'Staff must return any open loan');
check(Auth::canReturn($student, $openLoan) && !Auth::canReturn($student, $otherLoan), 'Student may return only own open loan');
check(!Auth::canReturn($student, $returnedLoan) && !Auth::canReturn($teacher, $returnedLoan), 'Returned loan is not returnable');
check(!Auth::canReturn($disabled, $openLoan) && !Auth::canReturn(null, $openLoan), 'Disabled or missing user cannot return');
check(!Auth::canLoan($student) && !Auth::canLoan($disabled) && Auth::canLoan($teacher), 'Loan roles stay owner/manager/teacher');
check(Auth::canReturn($student) && !Auth::canReturn(['id' => 'x', 'role' => 'guest', 'status' => 'active']), 'Unknown roles fail closed');

$loan1 = Loan::checkout($pdo, $teacher, 'ast-1', '이수업', '2학년', '실습', '2026-09-11T16:00:00+09:00');
[$status, $open] = openCounts($pdo, 'ast-1');
check($status === 'on_loan' && $open === 1, 'Checkout should mark asset on_loan with one open loan');
$log = $pdo->query('SELECT action, summary FROM activity_logs WHERE entity_id = ' . $pdo->quote($loan1))->fetch(PDO::FETCH_ASSOC);
check(($log['action'] ?? '') === 'loan' && str_contains((string) $log['summary'], '이수업'), 'Checkout must write an audit row');

reject(static function () use ($pdo, $teacherB): void {
    Loan::checkout($pdo, $teacherB, 'ast-1', '다른교사', null, '이중대여', null);
}, $pdo, 'Double checkout');
[$status, $open] = openCounts($pdo, 'ast-1');
check($status === 'on_loan' && $open === 1, 'Double checkout must leave a single open loan');

$returnedAsset = Loan::checkin($pdo, $teacher, $loan1);
[$status, $open] = openCounts($pdo, 'ast-1');
check($returnedAsset === 'ast-1' && $status === 'available' && $open === 0, 'Checkin should free the asset and close the loan');

reject(static function () use ($pdo, $teacher, $loan1): void {
    Loan::checkin($pdo, $teacher, $loan1);
}, $pdo, 'Double checkin');
[$status, $open] = openCounts($pdo, 'ast-1');
check($status === 'available' && $open === 0, 'Double checkin must not reopen or corrupt the asset');

$loanFallback = Loan::checkout($pdo, $teacher, 'ast-1', '', null, '실습', null);
check($pdo->query('SELECT borrower_name FROM loans WHERE id = ' . $pdo->quote($loanFallback))->fetchColumn() === 'teacher', 'Empty borrower name should fall back to actor');
Loan::checkin($pdo, $owner, $loanFallback);

reject(static function () use ($pdo, $student): void {
    Loan::checkout($pdo, $student, 'ast-2', '학생', null, '신청', null);
}, $pdo, 'Student checkout');
reject(static function () use ($pdo, $disabled): void {
    Loan::checkout($pdo, $disabled, 'ast-2', '중지', null, '신청', null);
}, $pdo, 'Disabled checkout');
reject(static function () use ($pdo, $teacher): void {
    Loan::checkout($pdo, $teacher, 'missing', '교사', null, '없음', null);
}, $pdo, 'Missing asset checkout');

$pdo->prepare("UPDATE assets SET status = 'repair' WHERE id = 'ast-2'")->execute();
reject(static function () use ($pdo, $teacher): void {
    Loan::checkout($pdo, $teacher, 'ast-2', '교사', null, '수리중', null);
}, $pdo, 'Repair checkout');
$pdo->prepare("UPDATE assets SET status = 'available' WHERE id = 'ast-2'")->execute();

$studentLoan = Loan::checkout($pdo, $teacher, 'ast-2', '학생', null, '수업', null);
$pdo->prepare('UPDATE loans SET borrower_user_id = ? WHERE id = ?')->execute(['student', $studentLoan]);
$otherOpen = Loan::checkout($pdo, $teacher, 'ast-1', '교사', null, '다른대여', null);

reject(static function () use ($pdo, $student, $otherOpen): void {
    Loan::checkin($pdo, $student, $otherOpen);
}, $pdo, 'Student returning another loan');
Loan::checkin($pdo, $student, $studentLoan);
check($pdo->query('SELECT status FROM loans WHERE id = ' . $pdo->quote($studentLoan))->fetchColumn() === 'returned', 'Student may return own loan');
Loan::checkin($pdo, $manager, $otherOpen);

$loan3 = Loan::checkout($pdo, $teacherB, 'ast-2', '교사B', null, '실습', null);
Loan::checkin($pdo, $manager, $loan3);
$loan4 = Loan::checkout($pdo, $teacher, 'ast-2', '교사', null, '실습', null);
Loan::checkin($pdo, $owner, $loan4);

$loanStuck = Loan::checkout($pdo, $teacher, 'ast-1', '교사', null, '실습', null);
$pdo->prepare("UPDATE assets SET status = 'repair' WHERE id = 'ast-1'")->execute();
reject(static function () use ($pdo, $teacher, $loanStuck): void {
    Loan::checkin($pdo, $teacher, $loanStuck);
}, $pdo, 'Checkin when asset is not on_loan');
check($pdo->query('SELECT status FROM loans WHERE id = ' . $pdo->quote($loanStuck))->fetchColumn() === 'active', 'Failed checkin must leave the loan open');
$pdo->prepare("UPDATE assets SET status = 'on_loan' WHERE id = 'ast-1'")->execute();
Loan::checkin($pdo, $teacher, $loanStuck);

$before = snapshot($pdo);
$pdo->exec("CREATE TRIGGER fail_log BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT, 'test log failure'); END");
try {
    Loan::checkout($pdo, $teacher, 'ast-3', '롤백', null, '롤백', null);
    throw new RuntimeException('Expected log failure');
} catch (PDOException) {
    check(snapshot($pdo) === $before, 'Log failure did not roll back checkout');
}
$pdo->exec('DROP TRIGGER fail_log');

try {
    $pdo->prepare(
        "INSERT INTO loans(id,kind,asset_id,quantity,borrower_name,status,created_at,created_by)
         VALUES('dup-1','asset','ast-3',1,'A','active','t','teacher'),('dup-2','asset','ast-3',1,'B','overdue','t','teacher')"
    )->execute();
    throw new RuntimeException('Unique open-loan index missing');
} catch (PDOException $e) {
    check(str_contains($e->getMessage(), 'UNIQUE constraint failed'), 'Open-loan unique index should reject a second row');
}

$raceDir = sys_get_temp_dir() . '/inni-loan-db-' . bin2hex(random_bytes(4));
mkdir($raceDir, 0700);
$raceDb = $raceDir . '/inni.sqlite';
$racePdo = new PDO('sqlite:' . $raceDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$racePdo->exec('PRAGMA foreign_keys = ON');
$racePdo->exec('PRAGMA journal_mode = WAL');
$racePdo->exec(file_get_contents($root . '/sql/schema.sql'));
seedLoanFixture($racePdo);
$racePdo = null;

$startCheckout = $raceDir . '/start-checkout';
$w1 = runWorker($raceDb, 'checkout', $teacher, 'ast-1', $startCheckout);
$w2 = runWorker($raceDb, 'checkout', $teacherB, 'ast-1', $startCheckout);
usleep(80000);
file_put_contents($startCheckout, 'go');
$r1 = collectWorker($w1);
$r2 = collectWorker($w2);
$wins = (int) !empty($r1['ok']) + (int) !empty($r2['ok']);
$racePdo = new PDO('sqlite:' . $raceDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
[$status, $open] = openCounts($racePdo, 'ast-1');
check($wins === 1 && $status === 'on_loan' && $open === 1, 'Concurrent checkout must accept exactly one loan');

$openId = (string) $racePdo->query("SELECT id FROM loans WHERE asset_id = 'ast-1' AND status IN ('active','overdue')")->fetchColumn();
$racePdo = null;
$startReturn = $raceDir . '/start-return';
$w3 = runWorker($raceDb, 'checkin', $teacher, $openId, $startReturn);
$w4 = runWorker($raceDb, 'checkin', $manager, $openId, $startReturn);
usleep(80000);
file_put_contents($startReturn, 'go');
$r3 = collectWorker($w3);
$r4 = collectWorker($w4);
$returnWins = (int) !empty($r3['ok']) + (int) !empty($r4['ok']);
$racePdo = new PDO('sqlite:' . $raceDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
[$status, $open] = openCounts($racePdo, 'ast-1');
$returned = (int) $racePdo->query('SELECT COUNT(*) FROM loans WHERE id = ' . $racePdo->quote($openId) . " AND status = 'returned'")->fetchColumn();
check($returnWins === 1 && $returned === 1 && $status === 'available' && $open === 0, 'Concurrent checkin must close the loan once');
$racePdo = null;
foreach (glob($raceDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($raceDir);

$loanSource = (string) file_get_contents($root . '/app/Loan.php');
check(str_contains($loanSource, 'BEGIN IMMEDIATE'), 'Loan writes must start BEGIN IMMEDIATE');
check(substr_count($loanSource, 'rowCount() !== 1') >= 3, 'Loan writes must fail closed on rowCount');
$returnLoan = (string) file_get_contents($root . '/app/Controllers/LoanController.php');
check(str_contains($returnLoan, 'Csrf::requirePost()') && str_contains($returnLoan, 'Auth::canReturn'), 'Return route must keep POST+CSRF and canReturn');
$loanRoute = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
check(str_contains($loanRoute, 'Csrf::requirePost()') && str_contains($loanRoute, 'Loan::checkout'), 'Loan route must keep POST+CSRF and use Loan::checkout');
check(str_contains((string) file_get_contents($root . '/templates/loans/index.php'), 'Auth::canReturn'), 'Loan list return button must use canReturn');
check(str_contains((string) file_get_contents($root . '/templates/assets/show.php'), 'Auth::canReturn'), 'Asset return button must use canReturn');

echo "PASS: {$checks} loan checks\n";
