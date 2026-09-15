<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\ReportCost;
use Inni\Support;

/** @var array $user */
/** @var array $report */
/** @var list<array<string, mixed>> $logs */
/** @var string $path */
?>
<p class="muted">
  <a href="<?= Support::e(App::url('reports')) ?>">← 수리 대기</a>
  · <a href="<?= Support::e(App::url('reports/costs')) ?>">수리비 합계</a>
</p>
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

<?php
  $costAmount = $report['cost_amount'] ?? null;
  $costAmount = $costAmount !== null && $costAmount !== '' ? (float) $costAmount : null;
  $costAmountText = '';
  if ($costAmount !== null) {
      $costAmountText = abs($costAmount - round($costAmount)) < 0.0001
          ? (string) (int) round($costAmount)
          : (string) $costAmount;
  }
  $costVendor = isset($report['cost_vendor']) ? (string) $report['cost_vendor'] : '';
  $costBudget = isset($report['cost_budget_line']) ? (string) $report['cost_budget_line'] : '';
  $costAt = isset($report['cost_at']) ? (string) $report['cost_at'] : '';
?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">수리비</h2>
  <p class="muted">금액·업체·예산과목을 남기면 월·연 합계에 들어갑니다. 에듀파인·감가상각은 없습니다.</p>
  <?php if (Auth::canWrite($user)): ?>
    <form method="post" action="<?= Support::e(App::url('reports/cost')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="report_id" value="<?= Support::e((string) $report['id']) ?>">
      <div class="grid-2">
        <div class="field">
          <label for="cost-amount">금액 (원)</label>
          <input id="cost-amount" name="cost_amount" inputmode="decimal" placeholder="85000" value="<?= Support::e($costAmountText) ?>">
        </div>
        <div class="field">
          <label for="cost-at">비용일</label>
          <input id="cost-at" name="cost_at" type="date" value="<?= Support::e($costAt) ?>">
        </div>
      </div>
      <div class="field">
        <label for="cost-vendor">업체</label>
        <input id="cost-vendor" name="cost_vendor" maxlength="200" placeholder="대한용접" value="<?= Support::e($costVendor) ?>">
      </div>
      <div class="field">
        <label for="cost-budget">예산과목</label>
        <input id="cost-budget" name="cost_budget_line" maxlength="200" placeholder="시설유지비" value="<?= Support::e($costBudget) ?>">
      </div>
      <div class="actions">
        <button class="btn btn-primary" type="submit">수리비 저장</button>
      </div>
    </form>
  <?php else: ?>
    <p class="muted">
      <?php if ($costAmount !== null): ?>
        <?= Support::e(ReportCost::formatAmount($costAmount)) ?>
        <?= $costVendor !== '' ? ' · ' . Support::e($costVendor) : '' ?>
        <?= $costBudget !== '' ? ' · ' . Support::e($costBudget) : '' ?>
        <?= $costAt !== '' ? ' · ' . Support::e($costAt) : '' ?>
      <?php else: ?>
        기록된 수리비가 없습니다.
      <?php endif; ?>
    </p>
  <?php endif; ?>
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
