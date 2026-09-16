<?php

use Inni\Loan;

/** @var bool $compact */
$compact = !empty($compact);
?>
<div class="field">
  <label>이상유무</label>
  <?php if ($compact): ?>
    <select name="return_condition">
      <option value="<?= Loan::RETURN_OK ?>">정상</option>
      <option value="<?= Loan::RETURN_ABNORMAL ?>">이상</option>
    </select>
  <?php else: ?>
    <div class="pick-chips">
      <label class="pick-chip">
        <input type="radio" name="return_condition" value="<?= Loan::RETURN_OK ?>" checked>
        정상
      </label>
      <label class="pick-chip">
        <input type="radio" name="return_condition" value="<?= Loan::RETURN_ABNORMAL ?>">
        이상
      </label>
    </div>
  <?php endif; ?>
</div>
<div class="field">
  <label>이상 내용 (선택)</label>
  <input name="return_note" maxlength="200" placeholder="흠집, 전원 불량 등">
</div>
