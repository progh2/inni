<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;
?>
<h1>라벨 인쇄</h1>
<p class="muted">인쇄할 항목을 고른 뒤 미리보기에서 인쇄하세요. QR은 브라우저에서 생성됩니다.</p>

<form class="card" method="post" action="<?= Support::e(App::url('labels/print')) ?>" style="margin-top:1rem">
  <?= Csrf::field() ?>
  <h2 class="section-title" style="margin-top:0">장비</h2>
  <?php if (!$assets): ?>
    <p class="muted">등록된 장비가 없습니다.</p>
  <?php endif; ?>
  <?php foreach ($assets as $a): ?>
    <label class="list-row" style="cursor:pointer">
      <div>
        <div class="title"><?= Support::e($a['name']) ?></div>
        <div class="meta"><?= Support::e($a['management_number']) ?></div>
      </div>
      <input type="checkbox" name="asset_ids[]" value="<?= Support::e($a['id']) ?>">
    </label>
  <?php endforeach; ?>

  <h2 class="section-title">실·보관함</h2>
  <?php if (!$rooms): ?>
    <p class="muted">등록된 실·보관함이 없습니다.</p>
  <?php endif; ?>
  <?php foreach ($rooms as $r): ?>
    <label class="list-row" style="cursor:pointer">
      <div>
        <div class="title"><?= Support::e($r['name']) ?></div>
        <div class="meta"><?= Support::e($r['code'] ?? $r['qr_code']) ?></div>
      </div>
      <input type="checkbox" name="location_ids[]" value="<?= Support::e($r['id']) ?>">
    </label>
  <?php endforeach; ?>

  <button class="btn btn-primary btn-block" style="margin-top:1rem" type="submit">미리보기 · 인쇄</button>
</form>
