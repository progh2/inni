<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;

/** @var array $check */
/** @var list<array<string, mixed>> $teachers */
/** @var array $user */
$returnTo = $returnTo ?? 'result';
$checkId = (string) ($check['id'] ?? '');
$attendingId = (string) ($check['attending_teacher_id'] ?? '');
$confirmingId = (string) ($check['confirming_teacher_id'] ?? '');
$returnQuery = $returnQuery ?? [];
?>
<div class="card" id="roster">
  <h2 class="section-title" style="margin-top:0">실사조서 · 입회·확인</h2>
  <p class="muted">종이 실사조서와 같이 입회 교사와 확인 교사를 남깁니다.</p>
  <?php if (!empty($check['attending_teacher_name']) || !empty($check['confirming_teacher_name'])): ?>
    <p>
      입회 <?= Support::e((string) ($check['attending_teacher_name'] ?: '—')) ?>
      · 확인 <?= Support::e((string) ($check['confirming_teacher_name'] ?: '—')) ?>
    </p>
  <?php endif; ?>
  <form method="post" action="<?= Support::e(App::url('inventory/sign')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="check_id" value="<?= Support::e($checkId) ?>">
    <input type="hidden" name="return_to" value="<?= Support::e((string) $returnTo) ?>">
    <?php foreach ($returnQuery as $key => $value): ?>
      <input type="hidden" name="<?= Support::e((string) $key) ?>" value="<?= Support::e((string) $value) ?>">
    <?php endforeach; ?>
    <div class="grid-2">
      <div class="field">
        <label for="attending-teacher">입회 교사</label>
        <select id="attending-teacher" name="attending_teacher_id">
          <option value="">선택</option>
          <?php foreach ($teachers as $t): ?>
            <option value="<?= Support::e((string) $t['id']) ?>" <?= $attendingId === (string) $t['id'] ? 'selected' : '' ?>>
              <?= Support::e((string) $t['display_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="confirming-teacher">확인 교사</label>
        <select id="confirming-teacher" name="confirming_teacher_id">
          <option value="">선택</option>
          <?php foreach ($teachers as $t): ?>
            <option value="<?= Support::e((string) $t['id']) ?>" <?= $confirmingId === (string) $t['id'] ? 'selected' : '' ?>>
              <?= Support::e((string) $t['display_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn btn-ghost" type="submit">실사조서 저장</button>
  </form>
</div>
