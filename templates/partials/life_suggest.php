<?php

use Inni\App;
use Inni\PpsUsefulLife;
use Inni\Support;

/** @var string $lifeSuggestNameFrom */
/** @var string $lifeSuggestName */
/** @var list<array<string, mixed>> $lifeSuggestions */

$lifeSuggestNameFrom = $lifeSuggestNameFrom ?? '';
$lifeSuggestName = $lifeSuggestName ?? '';
$lifeSuggestions = $lifeSuggestions ?? [];
$noticeLabel = PpsUsefulLife::noticeLabel();
?>
<div class="life-suggest-box"
     data-life-suggest
     data-suggest-url="<?= Support::e(App::url('assets/life-suggest')) ?>"
     data-name-from="<?= Support::e($lifeSuggestNameFrom) ?>"
     data-name-value="<?= Support::e($lifeSuggestName) ?>">
  <div class="field">
    <label>물품분류번호 (제안용)</label>
    <input data-life-class type="text" inputmode="numeric" autocomplete="off" maxlength="16" placeholder="예: 43211503">
    <p class="muted">이름 또는 분류번호로 조달청 내용연수를 제안합니다. 저장하지 않으며 강제 아닙니다.</p>
  </div>
  <div class="life-suggest" <?= $lifeSuggestions ? '' : 'hidden' ?>>
    <p class="muted"><?= Support::e($noticeLabel) ?> 제안 · 수락하면 내용연한만 채워집니다</p>
    <ul class="life-suggest-list">
      <?php foreach ($lifeSuggestions as $hit): ?>
        <li>
          <button type="button" class="btn btn-ghost life-suggest-accept"
                  data-years="<?= (int) $hit['years'] ?>"
                  data-source="<?= Support::e((string) $hit['source']) ?>">
            <?= Support::e((string) $hit['name']) ?> · <?= (int) $hit['years'] ?>년
            <span class="muted"><?= Support::e((string) $hit['class_number']) ?></span>
          </button>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <p class="life-suggest-source muted" hidden></p>
  <noscript>
    <p class="muted">자바스크립트가 꺼져 있으면 위 후보의 년수를 내용연한에 직접 입력하세요. 학교 특화 품목은 비워 두고 수동 입력합니다.</p>
  </noscript>
</div>
