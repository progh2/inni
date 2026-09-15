<?php

use Inni\App;
use Inni\Support;

/** @var array $user */
/** @var array $report */
/** @var list<array<string, mixed>> $logs */
/** @var string $path */
?>
<p class="muted"><a href="<?= Support::e(App::url('reports')) ?>">← 수리 대기</a></p>
<h1><?= Support::e($report['title']) ?></h1>
<p class="muted">
  <span class="badge <?= Support::e((string) $report['status']) ?>"><?= Support::e(Support::statusLabel((string) $report['status'])) ?></span>
  · <?= Support::e($report['reporter_name']) ?>
  · <?= Support::e(Support::formatWhen($report['created_at'] ?? null)) ?>
</p>

<?php if (!empty($report['asset_name'])): ?>
  <p>
    <a href="<?= Support::e(App::url('assets/show', ['id' => $report['target_id']])) ?>">
      <?= Support::e($report['asset_name']) ?>
      <?php if (!empty($report['management_number'])): ?>
        <span class="muted">· <?= Support::e((string) $report['management_number']) ?></span>
      <?php endif; ?>
    </a>
    <?php if (!empty($report['asset_status'])): ?>
      <span class="badge <?= Support::e((string) $report['asset_status']) ?>"><?= Support::e(Support::statusLabel((string) $report['asset_status'])) ?></span>
    <?php endif; ?>
  </p>
  <?php if ($path !== ''): ?>
    <p class="muted"><?= Support::e($path) ?></p>
  <?php endif; ?>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">증상</h2>
  <p style="white-space:pre-wrap"><?= Support::e($report['body']) ?></p>
  <?php if (!empty($report['image_path'])): ?>
    <p><img class="thumb" src="<?= Support::e(App::baseUrl() . $report['image_path']) ?>" alt=""></p>
  <?php endif; ?>
  <?php
    $returnTo = 'show';
    require dirname(__DIR__) . '/partials/report_status.php';
  ?>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">이력</h2>
  <?php foreach ($logs as $log): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($log['summary']) ?></div>
        <div class="meta"><?= Support::e($log['actor_name']) ?> · <?= Support::e(Support::formatWhen($log['created_at'])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$logs): ?><p class="muted">이력이 없습니다.</p><?php endif; ?>
</div>
