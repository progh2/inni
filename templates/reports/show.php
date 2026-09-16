<?php

use Inni\App;
use Inni\Asset;
use Inni\Auth;
use Inni\Csrf;
use Inni\Report;
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
  <?php
    $discovered = isset($report['discovered_at']) ? (string) $report['discovered_at'] : '';
    $urg = Report::urgencyLabel(isset($report['urgency']) ? (string) $report['urgency'] : null);
    $pref = Report::preferLabel(isset($report['prefer']) ? (string) $report['prefer'] : null);
  ?>
  <?php if ($discovered !== '' || $urg !== '' || $pref !== ''): ?>
    <p class="muted">
      <?= $discovered !== '' ? '발견 ' . Support::e($discovered) : '' ?>
      <?= $urg !== '' ? ($discovered !== '' ? ' · ' : '') . '긴급도 ' . Support::e($urg) : '' ?>
      <?= $pref !== '' ? (($discovered !== '' || $urg !== '') ? ' · ' : '') . '희망 ' . Support::e($pref) : '' ?>
    </p>
  <?php endif; ?>
  <?php if (!empty($report['image_path'])): ?>
    <p><img class="thumb" src="<?= Support::e(App::baseUrl() . $report['image_path']) ?>" alt=""></p>
  <?php endif; ?>
  <?php
    $returnTo = 'show';
    require dirname(__DIR__) . '/partials/report_status.php';
  ?>
  <?php if (($report['status'] ?? '') === Report::STATUS_REJECTED && ($report['target_type'] ?? '') === 'asset' && ($report['asset_status'] ?? '') !== 'retired'): ?>
    <p>
      불용 결정: 수리불가. 파기로 이어가려면 장비 상세에서 처리하세요.
    </p>
    <p>
      <a class="btn btn-ink" href="<?= Support::e(App::url('assets/show', [
          'id' => $report['target_id'],
          'retire_from' => Asset::RETIRE_SOURCE_REPAIR,
          'report_id' => $report['id'],
      ])) ?>#retire">불용·파기 진행</a>
    </p>
  <?php endif; ?>
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
  $costEstimate = $report['cost_estimate'] ?? null;
  $costEstimate = $costEstimate !== null && $costEstimate !== '' ? (float) $costEstimate : null;
  $costEstimateText = '';
  if ($costEstimate !== null) {
      $costEstimateText = abs($costEstimate - round($costEstimate)) < 0.0001
          ? (string) (int) round($costEstimate)
          : (string) $costEstimate;
  }
  $costProgram = isset($report['cost_budget_program']) && (string) $report['cost_budget_program'] !== ''
      ? (string) $report['cost_budget_program']
      : (isset($report['asset_budget_program']) ? (string) $report['asset_budget_program'] : '');
  $costEvidence = isset($report['cost_evidence']) ? (string) $report['cost_evidence'] : '';
?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">수리비</h2>
  <p class="muted">견적·금액·업체·사업예산을 남기면 월·연 합계에 들어갑니다. 에듀파인·감가상각은 없습니다.</p>
  <?php if (Auth::canWrite($user)): ?>
    <form method="post" action="<?= Support::e(App::url('reports/cost')) ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <input type="hidden" name="report_id" value="<?= Support::e((string) $report['id']) ?>">
      <div class="grid-2">
        <div class="field">
          <label for="cost-estimate">견적 (원)</label>
          <input id="cost-estimate" name="cost_estimate" inputmode="decimal" placeholder="120000" value="<?= Support::e($costEstimateText) ?>">
        </div>
        <div class="field">
          <label for="cost-amount">금액 (원)</label>
          <input id="cost-amount" name="cost_amount" inputmode="decimal" placeholder="85000" value="<?= Support::e($costAmountText) ?>">
        </div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="cost-at">비용일</label>
          <input id="cost-at" name="cost_at" type="date" value="<?= Support::e($costAt) ?>">
        </div>
        <div class="field">
          <label for="cost-vendor">업체</label>
          <input id="cost-vendor" name="cost_vendor" maxlength="200" placeholder="대한용접" value="<?= Support::e($costVendor) ?>">
        </div>
      </div>
      <div class="field">
        <label for="cost-program">사업예산</label>
        <input id="cost-program" name="cost_budget_program" maxlength="200" placeholder="방과후" value="<?= Support::e($costProgram) ?>">
      </div>
      <div class="field">
        <label for="cost-budget">예산과목</label>
        <input id="cost-budget" name="cost_budget_line" maxlength="200" placeholder="시설유지비" value="<?= Support::e($costBudget) ?>">
      </div>
      <div class="field">
        <label for="cost-evidence">증빙 메모</label>
        <input id="cost-evidence" name="cost_evidence" maxlength="200" placeholder="견적서 번호 또는 메모" value="<?= str_starts_with($costEvidence, '/uploads/') ? '' : Support::e($costEvidence) ?>">
      </div>
      <div class="field">
        <label>증빙 사진 (선택)</label>
        <input type="file" name="cost_evidence_photo" accept="image/*" capture="environment">
      </div>
      <?php if ($costEvidence !== '' && str_starts_with($costEvidence, '/uploads/')): ?>
        <p><img class="thumb" src="<?= Support::e(App::baseUrl() . $costEvidence) ?>" alt="수리비 증빙"></p>
      <?php elseif ($costEvidence !== ''): ?>
        <p class="muted">증빙 <?= Support::e($costEvidence) ?></p>
      <?php endif; ?>
      <div class="actions">
        <button class="btn btn-primary" type="submit">수리비 저장</button>
      </div>
    </form>
  <?php else: ?>
    <p class="muted">
      <?php if ($costAmount !== null || $costEstimate !== null): ?>
        <?= $costEstimate !== null ? '견적 ' . Support::e(ReportCost::formatAmount($costEstimate)) : '' ?>
        <?= $costAmount !== null ? (($costEstimate !== null ? ' · ' : '') . Support::e(ReportCost::formatAmount($costAmount))) : '' ?>
        <?= $costVendor !== '' ? ' · ' . Support::e($costVendor) : '' ?>
        <?= $costProgram !== '' ? ' · ' . Support::e($costProgram) : '' ?>
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
