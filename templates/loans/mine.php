<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Loan;
use Inni\Support;

/** @var array $user */
/** @var list<array<string, mixed>> $loans */
?>
<p class="muted"><a href="<?= Support::e(App::url('home')) ?>">← 홈</a></p>
<h1>내 대여함</h1>
<p class="muted">내가 빌린 장비와 반납 기한을 한곳에서 확인하세요.</p>

<div class="actions" style="margin:1rem 0">
  <a class="btn btn-primary" href="<?= Support::e(App::url('scan')) ?>">스캔해서 반납</a>
  <a class="btn btn-ghost" href="<?= Support::e(App::url('loans')) ?>">학교 전체 대여</a>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">진행 중 (<?= count($loans) ?>)</h2>
  <?php foreach ($loans as $loan): ?>
    <?php $overdue = Loan::isOverdue($loan); ?>
    <div class="list-row<?= $overdue ? ' is-overdue' : '' ?>">
      <div>
        <a class="title" href="<?= Support::e(App::url('assets/show', ['id' => $loan['asset_id']])) ?>">
          <?= Support::e($loan['asset_name'] ?? '품목') ?>
        </a>
        <div class="meta">
          예정 <?= Support::e(Support::formatWhen($loan['due_at'] ?? null)) ?>
          <?= !empty($loan['management_number']) ? ' · ' . Support::e((string) $loan['management_number']) : '' ?>
          <?= !empty($loan['purpose']) ? ' · ' . Support::e((string) $loan['purpose']) : '' ?>
        </div>
      </div>
      <div class="actions">
        <span class="badge <?= Support::e((string) $loan['status']) ?>"><?= Support::e(Support::statusLabel((string) $loan['status'])) ?></span>
        <?php if (Auth::canReturn($user, $loan)): ?>
          <form method="post" action="<?= Support::e(App::url('loans/return')) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="loan_id" value="<?= Support::e((string) $loan['id']) ?>">
            <input type="hidden" name="return_to" value="mine">
            <button class="btn <?= $overdue ? 'btn-primary' : 'btn-ghost' ?>" type="submit">반납</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$loans): ?><p class="muted">내가 빌린 진행 중 대여가 없습니다.</p><?php endif; ?>
</div>
