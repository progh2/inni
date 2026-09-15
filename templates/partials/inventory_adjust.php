<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\InventoryAdjust;
use Inni\Support;

/** @var array<string, mixed> $line */
/** @var list<array<string, mixed>> $locations */
/** @var list<array<string, mixed>> $approvers */
/** @var string $returnTo */
/** @var string $checkId */
/** @var array<string, string> $returnQuery */

$user = $user ?? Auth::user();
$returnQuery = $returnQuery ?? [];
$already = !empty($line['adjusted_at']);
$kind = (string) ($line['kind'] ?? '');
if (!$user || !Auth::canWrite($user)) {
    return;
}
?>
<?php if ($already): ?>
  <p class="muted" style="margin:0.4rem 0 0">
    보정됨 · <?= Support::e((string) ($line['adjust_reason'] ?? '')) ?>
    · 승인자 <?= Support::e((string) ($line['adjust_approver'] ?? '')) ?>
  </p>
<?php endif; ?>
<form class="adjust-form" method="post" action="<?= Support::e(App::url('inventory/adjust')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="line_id" value="<?= Support::e((string) $line['id']) ?>">
  <input type="hidden" name="check_id" value="<?= Support::e($checkId) ?>">
  <input type="hidden" name="return_to" value="<?= Support::e($returnTo) ?>">
  <?php foreach ($returnQuery as $key => $value): ?>
    <input type="hidden" name="<?= Support::e((string) $key) ?>" value="<?= Support::e((string) $value) ?>">
  <?php endforeach; ?>
  <?php if ($kind === 'asset'): ?>
    <div class="grid-2">
      <div class="field">
        <label>위치</label>
        <select name="location_id">
          <option value="">위치 유지</option>
          <?php foreach ($locations as $l): ?>
            <option value="<?= Support::e((string) $l['id']) ?>">
              <?= Support::e(Support::kindLabel((string) ($l['kind'] ?? ''))) ?> · <?= Support::e((string) $l['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>상태</label>
        <select name="status">
          <option value="">상태 유지</option>
          <?php foreach (InventoryAdjust::ASSET_STATUSES as $st): ?>
            <option value="<?= Support::e($st) ?>"><?= Support::e(Support::statusLabel($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  <?php else: ?>
    <div class="field">
      <label>실물 수량</label>
      <input name="quantity" type="number" inputmode="decimal" min="0" step="any" required value="0">
    </div>
  <?php endif; ?>
  <div class="grid-2">
    <div class="field">
      <label>사유</label>
      <input name="reason" required maxlength="200" placeholder="실사 불일치">
    </div>
    <div class="field">
      <label>승인자</label>
      <select name="approver_id" required>
        <option value="">선택</option>
        <?php foreach ($approvers as $who): ?>
          <option value="<?= Support::e((string) $who['id']) ?>" <?= ($who['id'] ?? '') === ($user['id'] ?? '') ? 'selected' : '' ?>>
            <?= Support::e((string) $who['display_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <button class="btn btn-ink" type="submit"><?= $already ? '다시 보정' : '장부 보정' ?></button>
</form>
