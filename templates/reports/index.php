<?php

use Inni\App;
use Inni\Support;

/** @var list<array<string, mixed>> $reports */
/** @var string $status */

$filters = [
    'queue' => '대기',
    'open' => '접수',
    'in_progress' => '수리중',
    'done' => '완료',
    'impossible' => '불가',
    'all' => '전체',
];
?>
<h1>수리 요청</h1>
<p class="muted">교사 요청을 접수·수리중·완료·불가로 처리합니다.</p>

<form method="get" action="<?= Support::e(App::url('reports')) ?>" class="card" style="margin-top:1rem">
  <div class="field">
    <label>상태</label>
    <select name="status" onchange="this.form.submit()">
      <?php foreach ($filters as $value => $label): ?>
        <option value="<?= Support::e($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
          <?= Support::e($label) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="btn btn-ghost" type="submit">필터</button></noscript>
</form>

<div class="card">
  <?php foreach ($reports as $report): ?>
    <a class="list-row" href="<?= Support::e(App::url('reports/show', ['id' => $report['id']])) ?>">
      <div>
        <div class="title"><?= Support::e($report['title']) ?></div>
        <div class="meta">
          <?= Support::e((string) ($report['target_name'] ?? $report['target_id'])) ?>
          <?php if (!empty($report['management_number'])): ?>
            · <?= Support::e((string) $report['management_number']) ?>
          <?php endif; ?>
          · <?= Support::e($report['reporter_name']) ?>
          · <?= Support::e(Support::formatWhen($report['created_at'])) ?>
        </div>
      </div>
      <span class="badge <?= Support::e((string) $report['status']) ?>"><?= Support::e(Support::statusLabel((string) $report['status'])) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$reports): ?>
    <p class="muted">해당 상태의 수리 요청이 없습니다.</p>
  <?php endif; ?>
</div>
