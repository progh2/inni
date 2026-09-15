<?php

use Inni\App;
use Inni\Budget;
use Inni\InventoryBudget;
use Inni\Support;

/** @var array<string, array<string, mixed>> $adjustments */

/** @var list<array<string, mixed>> $checks */
/** @var list<array<string, mixed>> $aggregates */
/** @var list<array<string, mixed>> $lines */
/** @var array{budget_program: ?string, budget_year: ?int, check_status: ?string, check_id: ?string, diff: string} $filters */
/** @var array{expected_count: int, confirmed_count: int, missing_count: int, expected_qty: float, confirmed_qty: float, missing_qty: float, check_count: int} $summary */

$filterQuery = static function (array $extra = []) use ($filters): array {
    $merged = $filters;
    foreach ($extra as $key => $value) {
        $merged[$key] = $value;
    }
    return InventoryBudget::query($merged);
};
$budgetProgram = is_string($filters['budget_program'] ?? null) ? $filters['budget_program'] : '';
$budgetYear = isset($filters['budget_year']) && $filters['budget_year'] !== null ? (string) (int) $filters['budget_year'] : '';
$csvQuery = InventoryBudget::query($filters);
?>
<p class="muted"><a href="<?= Support::e(App::url('inventory')) ?>">← 실사</a></p>
<h1>사업예산 실사</h1>
<p class="muted">종료·진행 중 실사를 사업명·예산연도로 거릅니다. 장부는 실사 시작 수량, 실물은 확인이면 장부와 같고 미확인이면 0입니다. 종료된 실사의 미확인 차이는 아래에서 사유·승인자로 장부 보정할 수 있습니다.</p>

<div class="stats" style="margin:1rem 0">
  <a class="stat" href="<?= Support::e(App::url('inventory/report', $filterQuery(['diff' => 'all']))) ?>">
    <b><?= (int) $summary['expected_count'] ?></b>장부
  </a>
  <a class="stat" href="<?= Support::e(App::url('inventory/report', $filterQuery(['diff' => 'confirmed']))) ?>">
    <b><?= (int) $summary['confirmed_count'] ?></b>확인
  </a>
  <a class="stat" href="<?= Support::e(App::url('inventory/report', $filterQuery(['diff' => 'missing']))) ?>">
    <b><?= (int) $summary['missing_count'] ?></b>미확인
  </a>
</div>

<form method="get" action="<?= Support::e(App::url('inventory/report')) ?>" class="card">
  <input type="hidden" name="r" value="inventory/report">
  <div class="field">
    <label for="inv-budget-program">사업명</label>
    <input id="inv-budget-program" name="budget_program" maxlength="200" placeholder="자유 입력 (선택)" value="<?= Support::e($budgetProgram) ?>">
  </div>
  <div class="field">
    <label for="inv-budget-year">예산연도</label>
    <input id="inv-budget-year" name="budget_year" type="number" inputmode="numeric" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= Support::e($budgetYear) ?>">
  </div>
  <div class="field">
    <label for="inv-check-status">실사 상태</label>
    <select id="inv-check-status" name="check_status">
      <option value="">전체 (종료·진행중)</option>
      <option value="done" <?= $filters['check_status'] === 'done' ? 'selected' : '' ?>>종료</option>
      <option value="active" <?= $filters['check_status'] === 'active' ? 'selected' : '' ?>>진행중</option>
    </select>
  </div>
  <div class="field">
    <label for="inv-check-id">실사 세션</label>
    <select id="inv-check-id" name="check_id">
      <option value="">전체 세션</option>
      <?php foreach ($checks as $check): ?>
        <option value="<?= Support::e((string) $check['id']) ?>" <?= $filters['check_id'] === $check['id'] ? 'selected' : '' ?>>
          <?= Support::e((string) $check['location_name']) ?>
          · <?= Support::e(InventoryBudget::checkStatusLabel((string) $check['status'])) ?>
          · <?= Support::e(Support::formatWhen(isset($check['started_at']) ? (string) $check['started_at'] : null)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="inv-diff">차이</label>
    <select id="inv-diff" name="diff">
      <option value="all" <?= $filters['diff'] === 'all' ? 'selected' : '' ?>>전체</option>
      <option value="missing" <?= $filters['diff'] === 'missing' ? 'selected' : '' ?>>미확인만</option>
      <option value="confirmed" <?= $filters['diff'] === 'confirmed' ? 'selected' : '' ?>>확인만</option>
    </select>
  </div>
  <div class="actions">
    <button class="btn btn-primary" type="submit">걸러보기</button>
    <a class="btn btn-ghost" href="<?= Support::e(App::url('inventory/report/csv', $csvQuery)) ?>">CSV 내보내기</a>
  </div>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0">사업별 집계 (<?= count($aggregates) ?>)</h2>
  <?php if (!$aggregates): ?>
    <p class="muted">필터에 맞는 실사 항목이 없습니다.</p>
  <?php else: ?>
    <div class="report-table-wrap">
      <table class="report-table">
        <thead>
          <tr>
            <th>사업예산</th>
            <th class="num">장부</th>
            <th class="num">확인</th>
            <th class="num">미확인</th>
            <th class="num">장부수량</th>
            <th class="num">실물수량</th>
            <th class="num">차이</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($aggregates as $row): ?>
            <?php
              $label = Budget::format(
                  isset($row['budget_program']) ? (string) $row['budget_program'] : null,
                  $row['budget_year'] ?? null,
              );
              if ($label === '') {
                  $label = '사업예산 없음';
              }
            ?>
            <tr class="<?= ((int) $row['missing_count'] > 0) ? 'is-missing' : '' ?>">
              <td><?= Support::e($label) ?></td>
              <td class="num"><?= (int) $row['expected_count'] ?></td>
              <td class="num"><?= (int) $row['confirmed_count'] ?></td>
              <td class="num"><?= (int) $row['missing_count'] ?></td>
              <td class="num"><?= Support::e(InventoryBudget::formatQty((float) $row['expected_qty'])) ?></td>
              <td class="num"><?= Support::e(InventoryBudget::formatQty((float) $row['confirmed_qty'])) ?></td>
              <td class="num"><?= Support::e(InventoryBudget::formatQty((float) $row['diff_qty'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted" style="margin-top:0.75rem">세션 <?= (int) $summary['check_count'] ?>건</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">장부 vs 실물 (<?= count($lines) ?>)</h2>
  <?php if (!$lines): ?>
    <p class="muted">차이 목록이 없습니다. 실사를 마치거나 필터를 바꿔 보세요.</p>
  <?php else: ?>
    <div class="report-table-wrap">
      <table class="report-table">
        <thead>
          <tr>
            <th>이름</th>
            <th>실사</th>
            <th>사업예산</th>
            <th class="num">장부</th>
            <th class="num">실물</th>
            <th class="num">차이</th>
            <th>결과</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lines as $line): ?>
            <?php
              $rowBudget = Budget::format(
                  isset($line['budget_program']) ? (string) $line['budget_program'] : null,
                  $line['budget_year'] ?? null,
              );
            ?>
            <tr class="<?= !empty($line['is_missing']) ? 'is-missing' : '' ?>">
              <td>
                <div class="title"><?= Support::e((string) $line['name']) ?></div>
                <div class="meta">
                  <?= Support::e(InventoryBudget::kindLabel((string) ($line['kind'] ?? ''))) ?>
                  <?php if (!empty($line['code'])): ?> · <?= Support::e((string) $line['code']) ?><?php endif; ?>
                  <?php if (!empty($line['unit'])): ?> · <?= Support::e((string) $line['unit']) ?><?php endif; ?>
                </div>
                <?php if (!empty($line['is_missing']) && ($line['check_status'] ?? '') === 'done'): ?>
                  <?php
                    $adjustment = ($adjustments ?? [])[$line['id']] ?? null;
                    $returnTo = 'report';
                    $returnQuery = InventoryBudget::query($filters);
                    require dirname(__DIR__) . '/partials/inventory_adjust.php';
                  ?>
                <?php endif; ?>
              </td>
              <td>
                <?= Support::e((string) ($line['check_location_name'] ?? '')) ?>
                <div class="meta"><?= Support::e(InventoryBudget::checkStatusLabel((string) ($line['check_status'] ?? ''))) ?></div>
              </td>
              <td><?= $rowBudget !== '' ? Support::e($rowBudget) : '—' ?></td>
              <td class="num"><?= Support::e(InventoryBudget::formatQty((float) $line['expected_qty'])) ?></td>
              <td class="num"><?= Support::e(InventoryBudget::formatQty((float) $line['physical_qty'])) ?></td>
              <td class="num"><?= Support::e(InventoryBudget::formatQty((float) $line['diff_qty'])) ?></td>
              <td>
                <span class="badge <?= !empty($line['is_confirmed']) ? 'confirmed' : 'unchecked' ?>">
                  <?= Support::e(InventoryBudget::resultLabel(!empty($line['is_confirmed']))) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
