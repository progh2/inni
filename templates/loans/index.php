<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Support;
?>
<h1>대여 현황</h1>
<div class="card" style="margin-top:1rem">
  <?php foreach ($loans as $loan): ?>
    <div class="list-row">
      <div>
        <a class="title" href="<?= Support::e(App::url('assets/show', ['id' => $loan['asset_id']])) ?>">
          <?= Support::e($loan['asset_name'] ?? '품목') ?>
        </a>
        <div class="meta">
          <?= Support::e($loan['borrower_name']) ?>
          · 예정 <?= Support::e(Support::formatWhen($loan['due_at'])) ?>
          <?= $loan['management_number'] ? ' · ' . Support::e($loan['management_number']) : '' ?>
        </div>
      </div>
      <div class="actions">
        <span class="badge <?= Support::e($loan['status']) ?>"><?= Support::e(Support::statusLabel($loan['status'])) ?></span>
        <?php if (Auth::canReturn($user ?? Auth::user(), $loan)): ?>
          <form method="post" action="<?= Support::e(App::url('loans/return')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="loan_id" value="<?= Support::e($loan['id']) ?>">
            <button class="btn btn-ghost" type="submit">반납</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$loans): ?><p class="muted">진행 중 대여가 없습니다.</p><?php endif; ?>
</div>
