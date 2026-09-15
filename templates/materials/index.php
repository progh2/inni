<?php

use Inni\App;
use Inni\Budget;
use Inni\MaterialBoard;
use Inni\Support;

/** @var list<array<string, mixed>> $items */
/** @var list<array<string, mixed>> $rooms */
/** @var array{type: ?string, low_stock: bool, room: ?string, budget_program: ?string, budget_year: ?int} $filters */
/** @var array{total: int, low_stock: int, by_type: array<string, int>} $summary */
/** @var list<array<string, mixed>> $waitingRestock */
/** @var list<array<string, mixed>> $issueLogs */
/** @var ?string $issueRoom */
/** @var array|null $user */

$filterQuery = static function (array $extra = []) use ($filters): array {
    $query = [];
    $type = array_key_exists('type', $extra) ? $extra['type'] : $filters['type'];
    $room = array_key_exists('room', $extra) ? $extra['room'] : $filters['room'];
    $lowStock = array_key_exists('low_stock', $extra) ? $extra['low_stock'] : $filters['low_stock'];
    $program = array_key_exists('budget_program', $extra) ? $extra['budget_program'] : $filters['budget_program'];
    $year = array_key_exists('budget_year', $extra) ? $extra['budget_year'] : $filters['budget_year'];
    if (is_string($type) && $type !== '') {
        $query['type'] = $type;
    }
    if (is_string($room) && $room !== '') {
        $query['room'] = $room;
    }
    if ($lowStock) {
        $query['low_stock'] = '1';
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
$waitingRestock = $waitingRestock ?? [];
$issueLogs = $issueLogs ?? [];
$issueRoom = $issueRoom ?? null;
?>
<h1>실험실습재료 현황</h1>
<p class="muted">부족·최근 분출·재입고 대기를 한 화면에서 봅니다. 검색 없이 소모품·부품 재고를 훑고, 보드에서 분출·재입고로 바로 이동합니다. 장비·비품은 <a href="<?= Support::e(App::url('items')) ?>">품목 목록</a>·<a href="<?= Support::e(App::url('assets')) ?>">기자재 현황</a>을 사용하세요.</p>

<div class="stats" style="margin:1rem 0">
  <a class="stat" href="<?= Support::e(App::url('materials')) ?>">
    <b><?= (int) $summary['total'] ?></b>전체
  </a>
  <a class="stat" href="<?= Support::e(App::url('materials', $filterQuery(['type' => 'consumable', 'low_stock' => false]))) ?>">
    <b><?= (int) ($summary['by_type']['consumable'] ?? 0) ?></b>소모품
  </a>
  <a class="stat" href="<?= Support::e(App::url('materials', $filterQuery(['type' => null, 'low_stock' => true]))) ?>#restock-wait">
    <b><?= (int) $summary['low_stock'] ?></b>재입고 대기
  </a>
</div>

<div class="actions" style="margin-bottom:1rem">
  <a class="btn btn-ghost" href="#restock-wait">재입고 대기</a>
  <a class="btn btn-ghost" href="#recent-issues">최근 분출</a>
</div>

<div class="card" id="restock-wait">
  <h2 class="section-title" style="margin-top:0">재입고 대기 (<?= count($waitingRestock) ?>)</h2>
  <p class="muted">최소재고보다 적은 품목입니다. 홈 부족 위젯과 같은 조건입니다.</p>
  <?php foreach ($waitingRestock as $item): ?>
    <?php $itemId = (string) $item['id']; ?>
    <div class="list-row is-low-stock">
      <div>
        <a class="title" href="<?= Support::e(App::url('items/show', ['id' => $itemId])) ?>"><?= Support::e((string) $item['name']) ?></a>
        <div class="meta">
          <?= Support::e(Support::typeLabel((string) $item['type'])) ?>
          · <?= Support::e((string) $item['stock_qty']) ?><?= !empty($item['unit']) ? ' ' . Support::e((string) $item['unit']) : '' ?>
          <?php if ($item['min_stock'] !== null && $item['min_stock'] !== ''): ?>
            / 최소 <?= Support::e((string) $item['min_stock']) ?>
          <?php endif; ?>
          <?php if (($item['rooms_label'] ?? '') !== ''): ?>
            · <?= Support::e((string) $item['rooms_label']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <span class="badge overdue">부족</span>
        <?php require __DIR__ . '/../partials/material_actions.php'; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$waitingRestock): ?><p class="muted">재입고 대기 품목이 없습니다.</p><?php endif; ?>
</div>

<form method="get" action="<?= Support::e(App::url('materials')) ?>" class="card">
  <input type="hidden" name="r" value="materials">
  <div class="field">
    <label for="material-type">유형</label>
    <select id="material-type" name="type">
      <option value="">전체 (소모품·부품)</option>
      <?php foreach (MaterialBoard::MATERIAL_TYPES as $type): ?>
        <option value="<?= Support::e($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>>
          <?= Support::e(Support::typeLabel($type)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="material-room">실</label>
    <select id="material-room" name="room">
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
    <label for="material-budget-program">사업명</label>
    <input id="material-budget-program" name="budget_program" maxlength="200" placeholder="자유 입력 (선택)" value="<?= Support::e($budgetProgram) ?>">
  </div>
  <div class="field">
    <label for="material-budget-year">예산연도</label>
    <input id="material-budget-year" name="budget_year" type="number" inputmode="numeric" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= Support::e($budgetYear) ?>">
  </div>
  <div class="check-row">
    <input id="material-low-stock" type="checkbox" name="low_stock" value="1" <?= !empty($filters['low_stock']) ? 'checked' : '' ?>>
    <label for="material-low-stock">재고부족만</label>
  </div>
  <button class="btn btn-primary" type="submit">걸러보기</button>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0">실험실습재료 (<?= count($items) ?>)</h2>
  <?php foreach ($items as $item): ?>
    <?php
      $low = !empty($item['low_stock']);
      $itemId = (string) $item['id'];
    ?>
    <div class="list-row<?= $low ? ' is-low-stock' : '' ?>">
      <div>
        <a class="title" href="<?= Support::e(App::url('items/show', ['id' => $itemId])) ?>"><?= Support::e((string) $item['name']) ?></a>
        <div class="meta">
          <?= Support::e(Support::typeLabel((string) $item['type'])) ?>
          · <?= Support::e((string) $item['stock_qty']) ?><?= !empty($item['unit']) ? ' ' . Support::e((string) $item['unit']) : '' ?>
          <?php if ($item['min_stock'] !== null && $item['min_stock'] !== ''): ?>
            / 최소 <?= Support::e((string) $item['min_stock']) ?>
          <?php endif; ?>
          <?php
            $rowBudget = Budget::format(
                isset($item['budget_program']) ? (string) $item['budget_program'] : null,
                $item['budget_year'] ?? null,
            );
          ?>
          <?php if ($rowBudget !== ''): ?> · <?= Support::e($rowBudget) ?><?php endif; ?>
          <?php if (($item['rooms_label'] ?? '') !== ''): ?>
            · <?= Support::e((string) $item['rooms_label']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <?php if ($low): ?>
          <span class="badge overdue">부족</span>
        <?php endif; ?>
        <?php require __DIR__ . '/../partials/material_actions.php'; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$items): ?><p class="muted">조건에 맞는 실험실습재료가 없습니다.</p><?php endif; ?>
</div>

<div class="card" id="recent-issues">
  <h2 class="section-title" style="margin-top:0">최근 분출</h2>
  <form method="get" action="<?= Support::e(App::url('materials')) ?>">
    <input type="hidden" name="r" value="materials">
    <?php if (is_string($filters['type'] ?? null) && $filters['type'] !== ''): ?>
      <input type="hidden" name="type" value="<?= Support::e((string) $filters['type']) ?>">
    <?php endif; ?>
    <?php if (is_string($filters['room'] ?? null) && $filters['room'] !== ''): ?>
      <input type="hidden" name="room" value="<?= Support::e((string) $filters['room']) ?>">
    <?php endif; ?>
    <?php if (!empty($filters['low_stock'])): ?>
      <input type="hidden" name="low_stock" value="1">
    <?php endif; ?>
    <?php if (is_string($filters['budget_program'] ?? null) && $filters['budget_program'] !== ''): ?>
      <input type="hidden" name="budget_program" value="<?= Support::e((string) $filters['budget_program']) ?>">
    <?php endif; ?>
    <?php if (isset($filters['budget_year']) && $filters['budget_year'] !== null): ?>
      <input type="hidden" name="budget_year" value="<?= Support::e((string) (int) $filters['budget_year']) ?>">
    <?php endif; ?>
    <div class="field">
      <label for="material-issue-room">분출 실</label>
      <select id="material-issue-room" name="issue_room">
        <option value="">전체</option>
        <?php foreach ($rooms as $room): ?>
          <option value="<?= Support::e((string) $room['id']) ?>" <?= ($issueRoom ?? null) === $room['id'] ? 'selected' : '' ?>>
            <?= Support::e((string) $room['name']) ?>
            <?= !empty($room['parent_name']) ? ' · ' . Support::e((string) $room['parent_name']) : '' ?>
            <?= !empty($room['code']) ? ' · ' . Support::e((string) $room['code']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-ghost" type="submit">이력 걸러보기</button>
  </form>
  <?php foreach ($issueLogs as $log): ?>
    <?php $itemId = (string) $log['entity_id']; ?>
    <div class="list-row">
      <div>
        <a class="title" href="<?= Support::e(App::url('items/show', ['id' => $itemId])) ?>"><?= Support::e((string) $log['summary']) ?></a>
        <div class="meta"><?= Support::e((string) $log['actor_name']) ?> · <?= Support::e(Support::formatWhen($log['created_at'])) ?></div>
      </div>
      <?php require __DIR__ . '/../partials/material_actions.php'; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$issueLogs): ?><p class="muted">최근 분출 이력이 없습니다.</p><?php endif; ?>
</div>
