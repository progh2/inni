<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Support;
?>
<h1>실 · 위치</h1>
<p class="muted">실을 열어 그 장소의 장비 목록을 확인하세요.</p>

<div class="card" style="margin-top:1rem">
  <?php foreach ($rooms as $room): ?>
    <a class="list-row" href="<?= Support::e(App::url('rooms/show', ['id' => $room['id']])) ?>">
      <div>
        <div class="title"><?= Support::e($room['name']) ?></div>
        <div class="meta">
          <?= Support::e($room['parent_name'] ?? '') ?>
          <?= $room['code'] ? ' · ' . Support::e($room['code']) : '' ?>
          · 장비 <?= (int) $room['asset_count'] ?>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php if (Auth::canWrite($user ?? Auth::user())): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">위치 추가</h2>
  <form method="post" action="<?= Support::e(App::url('rooms/save')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="field">
      <label>이름</label>
      <input name="name" required placeholder="예: 제어실습실">
    </div>
    <div class="field">
      <label>종류</label>
      <select name="kind">
        <option value="room">실</option>
        <option value="building">건물</option>
        <option value="storage">보관함</option>
        <option value="zone">구역</option>
        <option value="bin">칸</option>
      </select>
    </div>
    <div class="field">
      <label>상위 위치</label>
      <select name="parent_id">
        <option value="">없음</option>
        <?php foreach ($buildings as $b): ?>
          <option value="<?= Support::e($b['id']) ?>"><?= Support::e($b['name']) ?> (건물)</option>
        <?php endforeach; ?>
        <?php foreach ($rooms as $r): ?>
          <option value="<?= Support::e($r['id']) ?>"><?= Support::e($r['name']) ?> (실)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>코드</label>
      <input name="code" placeholder="E-201">
    </div>
    <div class="field">
      <label>사진</label>
      <input type="file" name="photo" accept="image/*" capture="environment">
    </div>
    <div class="field">
      <label>메모</label>
      <textarea name="notes" rows="2"></textarea>
    </div>
    <button class="btn btn-primary" type="submit">저장</button>
  </form>
</div>
<?php endif; ?>
