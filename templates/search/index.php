<?php

use Inni\App;
use Inni\Support;
?>
<h1>찾기</h1>
<form method="get" action="<?= Support::e(App::url('search')) ?>" class="card" style="margin:1rem 0">
  <input type="hidden" name="r" value="search">
  <div class="field" style="margin:0">
    <label>이름 · 관리번호 · 시리얼 · 실</label>
    <input type="search" name="q" value="<?= Support::e($q) ?>" placeholder="예: 오실로, 전장-2024, 용접실" autofocus>
  </div>
  <button class="btn btn-primary" style="margin-top:0.75rem" type="submit">검색</button>
</form>

<?php if ($q === ''): ?>
  <p class="muted">검색어를 입력하거나 스캔 탭을 사용하세요.</p>
<?php else: ?>
  <div class="card">
    <h2 class="section-title" style="margin-top:0">장비 (<?= count($assets) ?>)</h2>
    <?php foreach ($assets as $a): ?>
      <a class="list-row" href="<?= Support::e(App::url('assets/show', ['id' => $a['id']])) ?>">
        <div>
          <div class="title"><?= Support::e($a['name']) ?></div>
          <div class="meta"><?= Support::e($a['management_number']) ?> · <?= Support::e($a['location_name']) ?></div>
        </div>
        <span class="badge <?= Support::e($a['status']) ?>"><?= Support::e(Support::statusLabel($a['status'])) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$assets): ?><p class="muted">결과 없음</p><?php endif; ?>
  </div>

  <div class="card">
    <h2 class="section-title" style="margin-top:0">품목 (<?= count($catalog) ?>)</h2>
    <?php foreach ($catalog as $c): ?>
      <a class="list-row" href="<?= Support::e(App::url('items/show', ['id' => $c['id']])) ?>">
        <div>
          <div class="title"><?= Support::e($c['name']) ?></div>
          <div class="meta"><?= Support::e(Support::typeLabel($c['type'])) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$catalog): ?><p class="muted">결과 없음</p><?php endif; ?>
  </div>

  <div class="card">
    <h2 class="section-title" style="margin-top:0">위치 (<?= count($locations) ?>)</h2>
    <?php foreach ($locations as $l): ?>
      <a class="list-row" href="<?= Support::e(App::url('rooms/show', ['id' => $l['id']])) ?>">
        <div>
          <div class="title"><?= Support::e($l['name']) ?></div>
          <div class="meta"><?= Support::e(Support::kindLabel($l['kind'])) ?><?= $l['code'] ? ' · ' . Support::e($l['code']) : '' ?></div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$locations): ?><p class="muted">결과 없음</p><?php endif; ?>
  </div>
<?php endif; ?>
