<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Report;
use Inni\Support;

/** @var array $user */
/** @var array $report */
$returnTo = $returnTo ?? 'reports';
$next = Report::nextStatuses((string) ($report['status'] ?? ''));
if (!Auth::canWrite($user) || !$next) {
    return;
}
?>
<div class="actions">
  <?php foreach ($next as $status): ?>
    <form method="post" action="<?= Support::e(App::url('reports/status')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="report_id" value="<?= Support::e((string) $report['id']) ?>">
      <input type="hidden" name="status" value="<?= Support::e($status) ?>">
      <input type="hidden" name="return_to" value="<?= Support::e((string) $returnTo) ?>">
      <?php if ($returnTo === 'asset' && !empty($report['target_id'])): ?>
        <input type="hidden" name="asset_id" value="<?= Support::e((string) $report['target_id']) ?>">
      <?php endif; ?>
      <button
        class="btn <?= $status === Report::STATUS_REJECTED ? 'btn-ghost' : ($status === Report::STATUS_DONE ? 'btn-ink' : 'btn-primary') ?>"
        type="submit"
      ><?= Support::e(Report::actionLabel($status)) ?></button>
    </form>
  <?php endforeach; ?>
</div>
