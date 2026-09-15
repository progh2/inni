<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Report;
use Inni\Support;

/** @var array<string, mixed> $user */
/** @var array<string, mixed> $report */
/** @var list<array<string, mixed>> $history */
/** @var list<string> $nextStatuses */

$targetUrl = ($report['target_type'] ?? '') === 'asset'
    ? App::url('assets/show', ['id' => $report['target_id']])
    : App::url('rooms/show', ['id' => $report['target_id']]);
?>
<p class="muted"><a href="<?= Support::e(App::url('reports')) ?>">← 수리 요청</a></p>
<h1><?= Support::e((string) $report['title']) ?></h1>
<p class="muted">
  <span class="badge <?= Support::e((string) $report['status']) ?>"><?= Support::e(Support::statusLabel((string) $report['status'])) ?></span>
  · <?= Support::e((string) $report['reporter_name']) ?>
  · <?= Support::e(Support::formatWhen((string) $report['created_at'])) ?>
</p>
<p>
  <a href="<?= Support::e($targetUrl) ?>">
    <?= Support::e((string) ($report['target_name'] ?? $report['target_id'])) ?>
    <?php if (!empty($report['management_number'])): ?>
      · <?= Support::e((string) $report['management_number']) ?>
    <?php endif; ?>
  </a>
</p>

<div class="card">
  <h2 class="section-title" style="margin-top:0">증상</h2>
  <p><?= nl2br(Support::e((string) $report['body'])) ?></p>
  <?php if (!empty($report['image_path'])): ?>
    <p><img class="thumb" src="<?= Support::e(App::baseUrl() . $report['image_path']) ?>" alt=""></p>
  <?php endif; ?>
</div>

<?php if (Report::canTransition($user) && $nextStatuses): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">상태 변경</h2>
  <form method="post" action="<?= Support::e(App::url('reports/status')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="report_id" value="<?= Support::e((string) $report['id']) ?>">
    <div class="field">
      <label>다음 상태</label>
      <select name="status" required>
        <?php foreach ($nextStatuses as $next): ?>
          <option value="<?= Support::e($next) ?>"><?= Support::e(Support::statusLabel($next)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>처리 메모</label>
      <textarea name="note" rows="2" placeholder="담당 메모 (선택)"></textarea>
    </div>
    <button class="btn btn-primary btn-block" type="submit">상태 저장</button>
  </form>
</div>
<?php elseif (Report::canTransition($user)): ?>
<div class="card">
  <p class="muted">이 요청은 완료되었거나 수리 불가라 더 이상 상태를 바꿀 수 없습니다.</p>
</div>
<?php elseif (Auth::canLoan($user)): ?>
<div class="card">
  <p class="muted">상태 변경은 기자재 담당(관리자)만 할 수 있습니다.</p>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">처리 이력</h2>
  <?php foreach ($history as $log): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e((string) $log['summary']) ?></div>
        <div class="meta"><?= Support::e((string) $log['actor_name']) ?> · <?= Support::e(Support::formatWhen((string) $log['created_at'])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$history): ?>
    <p class="muted">이력이 없습니다.</p>
  <?php endif; ?>
</div>
