<?php

use Inni\App;
use Inni\Support;
?>
<h1>홈</h1>
<p class="muted">스캔하고, 찾고, 실별로 확인하세요.</p>

<div class="stats" style="margin:1rem 0">
  <div class="stat"><b><?= (int) $assetCount ?></b>장비</div>
  <div class="stat"><b><?= (int) $roomCount ?></b>실</div>
  <div class="stat"><b><?= (int) $openReports ?></b>신고</div>
</div>

<div class="actions" style="margin-bottom:1rem">
  <a class="btn btn-primary" href="<?= Support::e(App::url('scan')) ?>">스캔하기</a>
  <a class="btn btn-ghost" href="<?= Support::e(App::url('items/new')) ?>">빠른 등록</a>
  <a class="btn btn-ghost" href="<?= Support::e(App::url('loans')) ?>">대여 현황</a>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">진행 중 대여</h2>
  <?php if (!$activeLoans): ?>
    <p class="muted">진행 중인 대여가 없습니다.</p>
  <?php else: ?>
    <?php foreach ($activeLoans as $loan): ?>
      <a class="list-row" href="<?= Support::e(App::url('assets/show', ['id' => $loan['asset_id']])) ?>">
        <div>
          <div class="title"><?= Support::e($loan['asset_name'] ?? '품목') ?></div>
          <div class="meta"><?= Support::e($loan['borrower_name']) ?> · 예정 <?= Support::e(Support::formatWhen($loan['due_at'])) ?></div>
        </div>
        <span class="badge <?= Support::e($loan['status']) ?>"><?= Support::e(Support::statusLabel($loan['status'])) ?></span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($lowStock): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">재고 부족</h2>
  <?php foreach ($lowStock as $row): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($row['name']) ?></div>
        <div class="meta"><?= Support::e((string) $row['qty']) ?> / 최소 <?= Support::e((string) $row['min_stock']) ?> <?= Support::e($row['unit']) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">최근 이력</h2>
  <?php foreach ($recent as $log): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($log['summary']) ?></div>
        <div class="meta"><?= Support::e($log['actor_name']) ?> · <?= Support::e(Support::formatWhen($log['created_at'])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
