<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Support;
?>
<p class="muted"><a href="<?= Support::e(App::url('rooms')) ?>">← 실 목록</a></p>
<h1><?= Support::e($location['name']) ?></h1>
<p class="muted"><?= Support::e($path) ?> · <?= Support::e(Support::kindLabel($location['kind'])) ?><?= $location['code'] ? ' · ' . Support::e($location['code']) : '' ?></p>
<p class="muted">QR: <code><?= Support::e($location['qr_code']) ?></code></p>

<?php if (($location['kind'] ?? '') === 'room' && Auth::canInventory($user ?? Auth::user())): ?>
<form class="actions" method="post" action="<?= Support::e(App::url('inventory/start')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="location_id" value="<?= Support::e($location['id']) ?>">
  <button class="btn btn-primary" type="submit">이 실 실사</button>
</form>
<?php endif; ?>

<?php if (!empty($location['image_path'])): ?>
  <p><img class="thumb" src="<?= Support::e(App::baseUrl() . $location['image_path']) ?>" alt=""></p>
<?php endif; ?>

<?php if ($children): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">하위 위치</h2>
  <?php foreach ($children as $c): ?>
    <a class="list-row" href="<?= Support::e(App::url('rooms/show', ['id' => $c['id']])) ?>">
      <div>
        <div class="title"><?= Support::e($c['name']) ?></div>
        <div class="meta"><?= Support::e(Support::kindLabel($c['kind'])) ?></div>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">장비 (<?= count($assets) ?>)</h2>
  <?php foreach ($assets as $a): ?>
    <a class="list-row" href="<?= Support::e(App::url('assets/show', ['id' => $a['id']])) ?>">
      <div>
        <div class="title"><?= Support::e($a['name']) ?></div>
        <div class="meta"><?= Support::e($a['management_number']) ?></div>
      </div>
      <span class="badge <?= Support::e($a['status']) ?>"><?= Support::e(Support::statusLabel($a['status'])) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$assets): ?><p class="muted">이 위치에 등록된 장비가 없습니다.</p><?php endif; ?>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">비품·소모품 재고</h2>
  <?php foreach ($stock as $s): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($s['item_name']) ?></div>
        <div class="meta"><?= Support::e(Support::typeLabel($s['type'])) ?></div>
      </div>
      <div><strong><?= Support::e((string) $s['quantity']) ?></strong> <?= Support::e($s['unit']) ?></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$stock): ?><p class="muted">재고 기록 없음</p><?php endif; ?>
</div>

<?php if ($reports): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">시설 신고</h2>
  <?php foreach ($reports as $r): ?>
    <a class="list-row" href="<?= Support::e(App::url('reports/show', ['id' => $r['id']])) ?>">
      <div>
        <div class="title"><?= Support::e($r['title']) ?></div>
        <div class="meta"><?= Support::e($r['reporter_name']) ?> · <?= Support::e(Support::statusLabel($r['status'])) ?></div>
      </div>
      <span class="badge <?= Support::e($r['status']) ?>"><?= Support::e(Support::statusLabel($r['status'])) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
