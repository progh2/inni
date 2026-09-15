<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;
?>
<h1>실사</h1>
<p class="muted">실을 고르면 그 장소의 예상 장비·품목 목록이 만들어집니다. 스캔 또는 코드 입력으로 확인하고, 끝나면 미확인 목록을 봅니다. 지난 실사는 <a href="<?= Support::e(App::url('inventory/report')) ?>">사업예산별 리포트</a>에서 차이 목록·CSV로 봅니다.</p>

<form class="card" style="margin-top:1rem" method="post" action="<?= Support::e(App::url('inventory/start')) ?>">
  <?= Csrf::field() ?>
  <div class="field">
    <label for="inventory-room">실</label>
    <select id="inventory-room" name="location_id" required>
      <option value="">실을 선택하세요</option>
      <?php foreach ($rooms as $room): ?>
        <option value="<?= Support::e($room['id']) ?>">
          <?= Support::e($room['name']) ?>
          <?= $room['parent_name'] ? ' · ' . Support::e($room['parent_name']) : '' ?>
          <?= $room['code'] ? ' · ' . Support::e($room['code']) : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary btn-block" type="submit">실사 시작</button>
</form>
