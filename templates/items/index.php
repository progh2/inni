<?php

use Inni\App;
use Inni\Catalog;
use Inni\Support;

/** @var list<array<string, mixed>> $items */
/** @var string $type */
/** @var bool $lowStock */
$type = is_string($type ?? null) ? $type : '';
?>
<h1>품목 목록</h1>
<p class="muted">검색 없이 전체를 훑을 수 있습니다. 찾기는 그대로 둡니다.</p>

<form method="get" action="<?= Support::e(App::url('items')) ?>" class="card" style="margin:1rem 0">
  <input type="hidden" name="r" value="items">
  <div class="field" style="margin:0">
    <label>유형</label>
    <select name="type">
      <option value="" <?= $type === '' ? 'selected' : '' ?>>전체</option>
      <?php foreach (Catalog::TYPES as $t): ?>
        <option value="<?= Support::e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= Support::e(Support::typeLabel($t)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="margin-top:0.75rem">
    <label>
      <input type="checkbox" name="low_stock" value="1" <?= !empty($lowStock) ? 'checked' : '' ?>>
      재고부족
    </label>
  </div>
  <?php // TODO(#32): add budget_program / budget_year display + filters once those columns exist. ?>
  <button class="btn btn-primary" style="margin-top:0.75rem" type="submit">필터</button>
  <?php if ($type !== '' || !empty($lowStock)): ?>
    <a class="btn btn-ghost" style="margin-top:0.75rem" href="<?= Support::e(App::url('items')) ?>">초기화</a>
  <?php endif; ?>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0">품목 (<?= count($items) ?>)</h2>
  <?php foreach ($items as $c): ?>
    <a class="list-row" href="<?= Support::e(App::url('items/show', ['id' => $c['id']])) ?>">
      <div>
        <div class="title"><?= Support::e((string) $c['name']) ?></div>
        <div class="meta">
          <?= Support::e(Support::typeLabel((string) $c['type'])) ?>
          <?php if (in_array((string) $c['type'], ['consumable', 'part', 'fixture'], true)): ?>
            · <?= Support::e((string) $c['stock_qty']) ?><?= $c['unit'] ? ' ' . Support::e((string) $c['unit']) : '' ?>
            <?php if ($c['min_stock'] !== null && $c['min_stock'] !== ''): ?>
              / 최소 <?= Support::e((string) $c['min_stock']) ?>
            <?php endif; ?>
          <?php else: ?>
            · 장비 <?= (int) $c['asset_count'] ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($c['low_stock'])): ?>
        <span class="badge overdue">부족</span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if (!$items): ?><p class="muted">결과 없음</p><?php endif; ?>
</div>
<p class="muted" style="margin-top:1rem"><a href="<?= Support::e(App::url('search')) ?>">찾기로 이름·관리번호 검색</a></p>
