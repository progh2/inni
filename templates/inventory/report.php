<?php

use Inni\App;
use Inni\Budget;
use Inni\InventoryReport;
use Inni\Support;

/** @var array{budget_program: ?string, budget_year: ?int, check_id: ?string} $filters */
/** @var list<array<string, mixed>> $sessionOptions */
/** @var list<array<string, mixed>> $sessions */
/** @var list<array<string, mixed>> $aggregates */
/** @var list<array<string, mixed>> $differences */
/** @var array<string, string> $exportQuery */

$budgetProgram = is_string($filters['budget_program'] ?? null) ? $filters['budget_program'] : '';
$budgetYear = isset($filters['budget_year']) && $filters['budget_year'] !== null ? (string) (int) $filters['budget_year'] : '';
$selectedCheck = is_string($filters['check_id'] ?? null) ? $filters['check_id'] : '';
$hasFilters = $budgetProgram !== '' || $budgetYear !== '' || $selectedCheck !== '';
$bookLines = 0;
foreach ($sessions as $session) {
    $bookLines += (int) ($session['book_lines'] ?? 0);
}
?>
<p class="muted"><a href="<?= Support::e(App::url('inventory')) ?>">← 실사</a></p>
<h1>실사 리포트</h1>
<p class="muted">끝난 실사를 사업명·예산연도로 거릅니다. 차이는 장부에 있는데 스캔으로 확인하지 않은 항목입니다. 수량은 다시 입력하지 않습니다.</p>

<div class="stats" style="margin:1rem 0">
  <div class="stat"><b><?= count($sessions) ?></b>실사</div>
  <div class="stat"><b><?= (int) $bookLines ?></b>장부</div>
  <div class="stat"><b><?= count($differences) ?></b>차이</div>
</div>

<form method="get" action="<?= Support::e(App::url('inventory/report')) ?>" class="card">
  <input type="hidden" name="r" value="inventory/report">
  <div class="field">
    <label for="inventory-report-check">실사</label>
    <select id="inventory-report-check" name="check_id">
      <option value="">전체 실사</option>
      <?php foreach ($sessionOptions as $option): ?>
        <option value="<?= Support::e((string) $option['id']) ?>" <?= $selectedCheck === $option['id'] ? 'selected' : '' ?>>
          <?= Support::e((string) $option['location_name']) ?>
          · <?= Support::e(InventoryReport::checkStatusLabel((string) $option['status'])) ?>
          · <?= Support::e(Support::formatWhen($option['started_at'] ?? null)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="inventory-report-program">사업명</label>
    <input id="inventory-report-program" name="budget_program" maxlength="200" placeholder="자유 입력 (선택)" value="<?= Support::e($budgetProgram) ?>">
  </div>
  <div class="field">
    <label for="inventory-report-year">예산연도</label>
    <input id="inventory-report-year" name="budget_year" type="number" inputmode="numeric" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= Support::e($budgetYear) ?>">
  </div>
  <button class="btn btn-primary" type="submit">걸러보기</button>
  <?php if ($hasFilters): ?>
    <a class="btn btn-ghost" href="<?= Support::e(App::url('inventory/report')) ?>">초기화</a>
  <?php endif; ?>
</form>

<div class="actions" style="margin:1rem 0">
  <a class="btn btn-ink" href="<?= Support::e(App::url('inventory/report/csv', $exportQuery)) ?>">차이 CSV</a>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">사업별 집계</h2>
  <?php foreach ($aggregates as $row): ?>
    <?php
      $label = Budget::format(
          isset($row['budget_program']) ? (string) $row['budget_program'] : null,
          $row['budget_year'] ?? null,
      );
      if ($label === '') {
          $label = '미지정';
      }
    ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($label) ?></div>
        <div class="meta">
          장부 <?= (int) $row['book_lines'] ?>건
          · 차이 <?= (int) $row['missing_lines'] ?>건
          · 장부수량 <?= Support::e((string) $row['book_qty']) ?>
          · 차이수량 <?= Support::e((string) $row['missing_qty']) ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$aggregates): ?>
    <p class="muted">집계할 실사 항목이 없습니다.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">실사 세션</h2>
  <?php foreach ($sessions as $session): ?>
    <a class="list-row" href="<?= Support::e(App::url('inventory/report', InventoryReport::queryParams([
        'budget_program' => $filters['budget_program'],
        'budget_year' => $filters['budget_year'],
        'check_id' => $session['id'],
    ]))) ?>">
      <div>
        <div class="title"><?= Support::e((string) $session['location_name']) ?></div>
        <div class="meta">
          <?= Support::e(InventoryReport::checkStatusLabel((string) $session['status'])) ?>
          · <?= Support::e(Support::formatWhen($session['started_at'] ?? null)) ?>
          · 장부 <?= (int) $session['book_lines'] ?>
          · 확인 <?= (int) $session['confirmed_lines'] ?>
          · 차이 <?= (int) $session['missing_lines'] ?>
        </div>
      </div>
      <?php if ((int) $session['missing_lines'] > 0): ?>
        <span class="badge unchecked">차이</span>
      <?php else: ?>
        <span class="badge confirmed">일치</span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if (!$sessions): ?>
    <p class="muted">해당 사업예산의 실사가 없습니다.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">차이 목록 · 장부 vs 실물 (<?= count($differences) ?>)</h2>
  <?php foreach ($differences as $line): ?>
    <?php
      $rowBudget = Budget::format(
          isset($line['budget_program']) ? (string) $line['budget_program'] : null,
          $line['budget_year'] ?? null,
      );
    ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e((string) $line['name']) ?></div>
        <div class="meta">
          <?= Support::e(InventoryReport::lineKindLabel((string) $line['kind'])) ?>
          <?= !empty($line['code']) ? ' · ' . Support::e((string) $line['code']) : '' ?>
          · <?= Support::e((string) $line['check_location']) ?>
          · <?= Support::e((string) $line['location_name']) ?>
          · 장부 <?= Support::e((string) $line['book_qty']) ?>
          · 실물 <?= Support::e((string) $line['physical_qty']) ?>
          · 차이 <?= Support::e((string) $line['diff_qty']) ?>
          <?= $line['unit'] ? ' ' . Support::e((string) $line['unit']) : '' ?>
          <?php if ($rowBudget !== ''): ?> · <?= Support::e($rowBudget) ?><?php endif; ?>
        </div>
      </div>
      <span class="badge unchecked">미확인</span>
    </div>
  <?php endforeach; ?>
  <?php if (!$differences): ?>
    <p class="muted">장부와 실물 차이가 없습니다.</p>
  <?php endif; ?>
</div>
