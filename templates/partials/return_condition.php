<?php

use Inni\Loan;
use Inni\Support;

$required = !empty($required);
?>
<div class="field">
  <label>이상유무</label>
  <div class="pick-chips">
    <label class="pick-chip">
      <input type="radio" name="return_condition" value="<?= Support::e(Loan::RETURN_OK) ?>" <?= $required ? 'required' : '' ?> checked>
      이상없음
    </label>
    <label class="pick-chip">
      <input type="radio" name="return_condition" value="<?= Support::e(Loan::RETURN_ISSUE) ?>">
      이상있음
    </label>
  </div>
</div>
<div class="field">
  <label>이상 내용 (이상있음이면 필수)</label>
  <input name="return_note" maxlength="200" placeholder="파손·분실·작동 불량 등">
</div>
