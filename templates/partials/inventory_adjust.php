<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\InventoryBudget;
use Inni\Support;

/** @var array<string, mixed> $line */
/** @var array<string, mixed>|null $adjustment */
/** @var array<string, mixed> $user */
/** @var string $returnTo */
/** @var array<string, string> $returnQuery */

$adjustment = $adjustment ?? null;
$returnTo = $returnTo ?? 'result';
$returnQuery = $returnQuery ?? [];
$canAdjust = Auth::canInventory($user) && Auth::canWrite($user);
$book = InventoryBudget::formatQty((float) ($line['expected_qty'] ?? 0));
$defaultPhysical = '0';
?>
<?php if (is_array($adjustment)): ?>
  <div class="meta" style="margin-top:0.35rem">
    보정됨 · 장부 <?= Support::e($book) ?>
    → 실물 <?= Support::e(InventoryBudget::formatQty((float) ($adjustment['physical_qty'] ?? 0))) ?>
    · <?= Support::e((string) ($adjustment['reason'] ?? '')) ?>
    · 승인자 <?= Support::e((string) ($adjustment['approver_name'] ?? '')) ?>
  </div>
<?php elseif ($canAdjust): ?>
  <form method="post" action="<?= Support::e(App::url('inventory/adjust')) ?>" class="adjust-form" style="margin-top:0.65rem">
    <?= Csrf::field() ?>
    <input type="hidden" name="line_id" value="<?= Support::e((string) $line['id']) ?>">
    <input type="hidden" name="check_id" value="<?= Support::e((string) $line['check_id']) ?>">
    <input type="hidden" name="return_to" value="<?= Support::e($returnTo) ?>">
    <?php foreach ($returnQuery as $key => $value): ?>
      <input type="hidden" name="<?= Support::e((string) $key) ?>" value="<?= Support::e((string) $value) ?>">
    <?php endforeach; ?>
    <div class="grid-2">
      <div class="field">
        <label>실물 수량</label>
        <input name="physical_qty" type="number" inputmode="decimal" min="0" step="any" value="<?= Support::e($defaultPhysical) ?>" required>
      </div>
      <div class="field">
        <label>승인자</label>
        <input name="approver_name" maxlength="80" value="<?= Support::e((string) ($user['display_name'] ?? '')) ?>" required>
      </div>
    </div>
    <div class="field">
      <label>보정 사유</label>
      <input name="reason" maxlength="200" required placeholder="실사 미확인 · 장부 수량 맞춤">
    </div>
    <button class="btn btn-ink" type="submit">장부 보정</button>
  </form>
<?php endif; ?>
