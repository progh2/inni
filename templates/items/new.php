<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;
?>
<h1>빠른 등록</h1>
<p class="muted">필수만 채우고 저장하세요. 사진은 나중에 추가해도 됩니다.</p>

<form class="card" method="post" action="<?= Support::e(App::url('items/save')) ?>" enctype="multipart/form-data" style="margin-top:1rem">
  <?= Csrf::field() ?>
  <div class="field">
    <label>이름 *</label>
    <input name="name" required placeholder="디지털 멀티미터">
  </div>
  <div class="field">
    <label>유형 *</label>
    <select name="type" id="type">
      <option value="equipment">장비 (개체 추적)</option>
      <option value="fixture">비품</option>
      <option value="consumable">소모품</option>
      <option value="part">부품</option>
    </select>
  </div>
  <div class="field">
    <label>위치 *</label>
    <select name="location_id" required>
      <?php foreach ($locations as $l): ?>
        <option value="<?= Support::e($l['id']) ?>">
          <?= Support::e(Support::kindLabel($l['kind'])) ?> · <?= Support::e($l['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label>수량</label>
    <input type="number" name="quantity" value="1" min="1">
  </div>
  <div class="field" id="mgmt-field">
    <label>관리번호 (장비)</label>
    <input name="management_number" placeholder="전장-2026-001">
  </div>
  <div class="field">
    <label>에듀파인 번호 (옵션)</label>
    <input name="edufine_number">
  </div>
  <div class="field">
    <label>제조사</label>
    <input name="manufacturer">
  </div>
  <div class="field">
    <label>단위 / 최소재고 (소모품)</label>
    <div style="display:flex;gap:0.5rem">
      <input name="unit" value="ea" style="max-width:6rem">
      <input name="min_stock" type="number" step="0.1" placeholder="최소재고">
    </div>
  </div>
  <div class="field">
    <label>태그 (쉼표)</label>
    <input name="tags" placeholder="계측,전자">
  </div>
  <div class="field">
    <label>사진</label>
    <input type="file" name="photo" accept="image/*" capture="environment">
  </div>
  <div class="field">
    <label>메모</label>
    <textarea name="notes" rows="2"></textarea>
  </div>
  <button class="btn btn-primary btn-block" type="submit">저장</button>
</form>
