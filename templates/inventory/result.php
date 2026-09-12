<?php

use Inni\App;
use Inni\Support;

$total = count($lines);
$missing = count($unchecked);
$confirmed = $total - $missing;
?>
<p class="muted"><a href="<?= Support::e(App::url('inventory')) ?>">← 새 실사</a></p>
<h1>실사 결과 · <?= Support::e($check['location_name']) ?></h1>
<p class="muted">확인 <?= (int) $confirmed ?> · 미확인 <?= (int) $missing ?> · 전체 <?= (int) $total ?></p>

<div class="card" style="margin-top:1rem">
  <h2 class="section-title" style="margin-top:0">미확인 목록</h2>
  <?php foreach ($unchecked as $line): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($line['name']) ?></div>
        <div class="meta">
          <?= $line['kind'] === 'asset' ? '장비' : '품목' ?>
          <?= $line['code'] ? ' · ' . Support::e($line['code']) : '' ?>
          · <?= Support::e($line['location_name']) ?>
        </div>
      </div>
      <span class="badge unchecked">미확인</span>
    </div>
  <?php endforeach; ?>
  <?php if (!$unchecked): ?>
    <p class="muted">미확인 항목이 없습니다. 이 실의 예상 목록을 모두 확인했습니다.</p>
  <?php endif; ?>
</div>

<div class="actions">
  <a class="btn btn-ghost" href="<?= Support::e(App::url('rooms/show', ['id' => $check['location_id']])) ?>">실 보기</a>
  <a class="btn btn-primary" href="<?= Support::e(App::url('inventory')) ?>">다른 실 실사</a>
</div>
