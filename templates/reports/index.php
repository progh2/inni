<?php

use Inni\App;
use Inni\Report;
use Inni\Support;

/** @var array $user */
/** @var list<array<string, mixed>> $reports */
/** @var array{status: ?string} $filters */
/** @var array{open: int, in_progress: int, done: int, rejected: int, active: int} $counts */
$current = $filters['status'] ?? null;
$chips = [
    [null, '대기', $counts['active']],
    ['open', '접수', $counts['open']],
    ['in_progress', '수리중', $counts['in_progress']],
    ['done', '완료', $counts['done']],
    ['rejected', '불가', $counts['rejected']],
];
?>
<p class="muted"><a href="<?= Support::e(App::url('home')) ?>">← 홈</a></p>
<h1>수리 대기</h1>
<p class="muted">접수된 고장·수리 요청을 수리중·완료·불가로 넘깁니다.</p>

<div class="actions" style="margin:1rem 0">
  <?php foreach ($chips as [$status, $label, $n]): ?>
    <?php
      $active = $current === $status;
      $href = $status === null ? App::url('reports') : App::url('reports', ['status' => $status]);
    ?>
    <a class="btn <?= $active ? 'btn-ink' : 'btn-ghost' ?>" href="<?= Support::e($href) ?>">
      <?= Support::e($label) ?> <?= (int) $n ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">
    <?= $current === null ? '대기' : Support::e(Support::statusLabel((string) $current)) ?>
    (<?= count($reports) ?>)
  </h2>
  <?php foreach ($reports as $r): ?>
    <div class="list-row">
      <div>
        <a class="title" href="<?= Support::e(App::url('reports/show', ['id' => $r['id']])) ?>">
          <?= Support::e($r['title']) ?>
        </a>
        <div class="meta">
          <?= Support::e($r['asset_name'] ?? '장비') ?>
          <?= !empty($r['management_number']) ? ' · ' . Support::e((string) $r['management_number']) : '' ?>
          · <?= Support::e($r['reporter_name']) ?>
          · <?= Support::e(Support::formatWhen($r['created_at'] ?? null)) ?>
        </div>
      </div>
      <div class="actions">
        <span class="badge <?= Support::e((string) $r['status']) ?>"><?= Support::e(Support::statusLabel((string) $r['status'])) ?></span>
        <?php if (!empty($r['asset_status'])): ?>
          <span class="badge <?= Support::e((string) $r['asset_status']) ?>"><?= Support::e(Support::statusLabel((string) $r['asset_status'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php
      $report = $r;
      $returnTo = 'reports';
      require dirname(__DIR__) . '/partials/report_status.php';
    ?>
  <?php endforeach; ?>
  <?php if (!$reports): ?>
    <p class="muted">이 상태의 수리 요청이 없습니다.</p>
  <?php endif; ?>
</div>
