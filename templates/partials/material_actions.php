<?php

use Inni\Auth;
use Inni\MaterialBoard;
use Inni\Support;

/** @var array|null $user */
/** @var string $itemId */

$itemId = trim((string) $itemId);
if ($itemId === '') {
    return;
}
?>
<?php if (Auth::canLoan($user) || Auth::canWrite($user)): ?>
  <div class="actions" style="margin-top:0">
    <?php if (Auth::canLoan($user)): ?>
      <a class="btn btn-primary" href="<?= Support::e(MaterialBoard::itemActionUrl($itemId, MaterialBoard::ACTION_ISSUE)) ?>">분출</a>
    <?php endif; ?>
    <?php if (Auth::canWrite($user)): ?>
      <a class="btn btn-ink" href="<?= Support::e(MaterialBoard::itemActionUrl($itemId, MaterialBoard::ACTION_RESTOCK)) ?>">재입고</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
