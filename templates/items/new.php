<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;
?>
<h1>빠른 등록</h1>
<p class="muted">필수만 채우고 저장하세요. 사진은 나중에 추가해도 됩니다. 소모품·부품은 단위·최소재고·로트/유통기한을 같이 적을 수 있습니다.</p>

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
  <div id="equipment-fields">
    <div class="field" id="mgmt-field">
      <label>관리번호 (장비)</label>
      <input name="management_number" placeholder="전장-2026-001">
    </div>
    <div class="grid-2">
      <div class="field">
        <label>도입일</label>
        <input name="purchase_date" type="date">
      </div>
      <div class="field">
        <label>내용연한(년)</label>
        <p class="muted">이 장비를 쓸 수 있는 햇수</p>
        <input name="useful_life_years" type="number" inputmode="numeric" min="1" max="100" step="1" placeholder="예: 5">
      </div>
    </div>
    <?php
      $lifeSuggestNameFrom = 'name';
      $lifeSuggestName = '';
      $lifeSuggestions = [];
      require dirname(__DIR__) . '/partials/life_suggest.php';
    ?>
  </div>
  <div class="field">
    <label>에듀파인 번호 (옵션)</label>
    <input name="edufine_number">
  </div>
  <div class="field">
    <label>제조사</label>
    <input name="manufacturer">
  </div>
  <div class="grid-2">
    <div class="field">
      <label>구입 사업명</label>
      <input name="budget_program" maxlength="200" placeholder="자유 입력 (선택)">
    </div>
    <div class="field">
      <label>예산 연도</label>
      <input name="budget_year" type="number" inputmode="numeric" min="1900" max="2100" step="1" placeholder="YYYY">
    </div>
  </div>
  <div id="material-fields">
    <div class="grid-2">
      <div class="field">
        <label for="unit">단위</label>
        <input id="unit" name="unit" value="ea" placeholder="ea, m, 개">
      </div>
      <div class="field">
        <label for="min_stock">최소재고</label>
        <input id="min_stock" name="min_stock" type="number" step="0.1" min="0" placeholder="부족 알림 기준">
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label for="lot_code">로트 번호 (선택)</label>
        <input id="lot_code" name="lot_code" maxlength="80" placeholder="예: SN-2026-03">
      </div>
      <div class="field">
        <label for="expires_at">유통기한 (선택)</label>
        <input id="expires_at" name="expires_at" type="date">
      </div>
    </div>
    <p class="muted">최소재고보다 수량이 적으면 목록에 부족 뱃지가 보이고, 알림이 켜져 있으면 같은 조건으로 보냅니다. 로트·유통기한은 위치별 재고에 붙습니다.</p>
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
<script>
(function () {
  var type = document.getElementById('type');
  var equipment = document.getElementById('equipment-fields');
  var material = document.getElementById('material-fields');
  function sync() {
    var value = type ? type.value : 'equipment';
    var isEquipment = value === 'equipment';
    if (equipment) {
      equipment.hidden = !isEquipment;
    }
    if (material) {
      material.hidden = isEquipment;
    }
  }
  if (type) {
    type.addEventListener('change', sync);
    sync();
  }
})();
</script>
