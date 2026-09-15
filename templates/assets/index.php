<?php

use Inni\App;
use Inni\AssetBoard;
use Inni\AssetLife;
use Inni\Budget;
use Inni\Support;

/** @var list<array<string, mixed>> $assets */
/** @var list<array<string, mixed>> $rooms */
/** @var array{status: ?string, room: ?string, overdue: bool, budget_program: ?string, budget_year: ?int} $filters */
/** @var array{total: int, overdue: int, by_status: array<string, int>} $summary */

$filterQuery = static function (array $extra = []) use ($filters): array {
    $query = [];
    $status = array_key_exists('status', $extra) ? $extra['status'] : $filters['status'];
    $room = array_key_exists('room', $extra) ? $extra['room'] : $filters['room'];
    $overdue = array_key_exists('overdue', $extra) ? $extra['overdue'] : $filters['overdue'];
    $program = array_key_exists('budget_program', $extra) ? $extra['budget_program'] : $filters['budget_program'];
    $year = array_key_exists('budget_year', $extra) ? $extra['budget_year'] : $filters['budget_year'];
    if (is_string($status) && $status !== '') {
        $query['status'] = $status;
    }
    if (is_string($room) && $room !== '') {
        $query['room'] = $room;
    }
    if ($overdue) {
        $query['overdue'] = '1';
    }
    if (is_string($program) && $program !== '') {
        $query['budget_program'] = $program;
    }
    if ($year !== null && $year !== '') {
        $query['budget_year'] = (string) (int) $year;
    }
    return $query;
};
$budgetProgram = is_string($filters['budget_program'] ?? null) ? $filters['budget_program'] : '';
$budgetYear = isset($filters['budget_year']) && $filters['budget_year'] !== null ? (string) (int) $filters['budget_year'] : '';
?>
<h1>기자재 현황</h1>
<p class="muted">검색 없이 전체 장비를 훑습니다. 상태·실·연체·사업예산으로 거를 수 있습니다. 내용연한 임박·초과는 <a href="<?= Support::e(App::url('assets/aging')) ?>">연한·노후 기자재</a>에서 봅니다.</p>

<div class="stats" style="margin:1rem 0">
  <a class="stat" href="<?= Support::e(App::url('assets')) ?>">
    <b><?= (int) $summary['total'] ?></b>전체
  </a>
  <a class="stat" href="<?= Support::e(App::url('assets', $filterQuery(['status' => 'on_loan', 'overdue' => false]))) ?>">
    <b><?= (int) ($summary['by_status']['on_loan'] ?? 0) ?></b>대여중
  </a>
  <a class="stat" href="<?= Support::e(App::url('assets', $filterQuery(['status' => null, 'overdue' => true]))) ?>">
    <b><?= (int) $summary['overdue'] ?></b>연체
  </a>
</div>

<form method="get" action="<?= Support::e(App::url('assets')) ?>" class="card">
  <input type="hidden" name="r" value="assets">
  <div class="field">
    <label for="asset-status">상태</label>
    <select id="asset-status" name="status">
      <option value="">전체</option>
      <?php foreach (AssetBoard::ASSET_STATUSES as $status): ?>
        <option value="<?= Support::e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
          <?= Support::e(Support::statusLabel($status)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="asset-room">실</label>
    <select id="asset-room" name="room">
      <option value="">전체</option>
      <?php foreach ($rooms as $room): ?>
        <option value="<?= Support::e($room['id']) ?>" <?= $filters['room'] === $room['id'] ? 'selected' : '' ?>>
          <?= Support::e($room['name']) ?>
          <?= !empty($room['parent_name']) ? ' · ' . Support::e((string) $room['parent_name']) : '' ?>
          <?= !empty($room['code']) ? ' · ' . Support::e((string) $room['code']) : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="asset-budget-program">사업명</label>
    <input id="asset-budget-program" name="budget_program" maxlength="200" placeholder="자유 입력 (선택)" value="<?= Support::e($budgetProgram) ?>">
  </div>
  <div class="field">
    <label for="asset-budget-year">예산연도</label>
    <input id="asset-budget-year" name="budget_year" type="number" inputmode="numeric" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= Support::e($budgetYear) ?>">
  </div>
  <div class="check-row">
    <input id="asset-overdue" type="checkbox" name="overdue" value="1" <?= !empty($filters['overdue']) ? 'checked' : '' ?>>
    <label for="asset-overdue">연체만</label>
  </div>
  <button class="btn btn-primary" type="submit">걸러보기</button>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0">장비 (<?= count($assets) ?>)</h2>
  <?php foreach ($assets as $asset): ?>
    <?php $badge = AssetBoard::displayStatus($asset); ?>
    <a class="list-row" href="<?= Support::e(App::url('assets/show', ['id' => $asset['id']])) ?>">
      <div>
        <div class="title"><?= Support::e((string) $asset['name']) ?></div>
        <div class="meta">
          <?= Support::e((string) $asset['management_number']) ?>
          · <?= Support::e((string) ($asset['location_name'] ?? '')) ?>
          <?php
            $rowBudget = Budget::format(
                isset($asset['budget_program']) ? (string) $asset['budget_program'] : null,
                $asset['budget_year'] ?? null,
            );
          ?>
          <?php if ($rowBudget !== ''): ?> · <?= Support::e($rowBudget) ?><?php endif; ?>
          <?php
            $rowLife = AssetLife::format(
                isset($asset['purchase_date']) ? (string) $asset['purchase_date'] : null,
                $asset['useful_life_years'] ?? null,
            );
          ?>
          <?php if ($rowLife !== ''): ?> · <?= Support::e($rowLife) ?><?php endif; ?>
          <?php if (($asset['loan_status'] ?? '') !== ''): ?>
            · <?= Support::e((string) ($asset['borrower_name'] ?? '')) ?>
            · 예정 <?= Support::e(Support::formatWhen(isset($asset['due_at']) ? (string) $asset['due_at'] : null)) ?>
          <?php endif; ?>
        </div>
      </div>
      <span class="badge <?= Support::e($badge) ?>"><?= Support::e(Support::statusLabel($badge)) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$assets): ?><p class="muted">조건에 맞는 장비가 없습니다.</p><?php endif; ?>
</div>
