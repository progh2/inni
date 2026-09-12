<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;

/** @var array<string, mixed>|null $result */
/** @var list<array<string, mixed>> $locations */
?>
<h1>품목 CSV</h1>
<p class="muted">품목 대장의 템플릿을 받아 채운 뒤 올리면 신규 등록·수정을 한 번에 처리합니다. <strong>CSV UTF-8만</strong> 지원합니다. xlsx는 Composer 없이 다루기 어려워 넣지 않았습니다. Excel에서는 “CSV UTF-8(쉼표로 분리)”로 저장하세요. 에듀파인보내기 파일 동기화·실사 연동은 포함하지 않습니다.</p>

<div class="card" style="margin-top:1rem">
  <h2 class="section-title" style="margin-top:0">내려받기</h2>
  <p class="muted">빈 템플릿 또는 현재 품목 목록. 다시 올릴 때는 <code>품목ID</code>를 그대로 두면 수정되고, 비우면 신규입니다. 수정 행의 위치·수량은 반영하지 않습니다(재고는 화면에서 입고).</p>
  <div class="actions">
    <a class="btn btn-ink" href="<?= Support::e(App::url('catalog/csv/template')) ?>">템플릿 받기</a>
  </div>
  <form method="get" action="<?= Support::e(App::url('catalog/csv/export')) ?>" style="margin-top:0.85rem">
    <div class="grid-2">
      <div class="field" style="margin-bottom:0">
        <label for="csv-type">유형</label>
        <select id="csv-type" name="type">
          <option value="">전체</option>
          <option value="equipment">장비</option>
          <option value="fixture">비품</option>
          <option value="consumable">소모품</option>
          <option value="part">부품</option>
        </select>
      </div>
      <div class="field" style="margin-bottom:0">
        <label for="csv-location">실/보관함</label>
        <select id="csv-location" name="location_id">
          <option value="">전체</option>
          <?php foreach ($locations as $location): ?>
            <option value="<?= Support::e((string) $location['id']) ?>">
              <?= Support::e((string) $location['name']) ?>
              <?= !empty($location['code']) ? ' · ' . Support::e((string) $location['code']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="actions">
      <button class="btn btn-ghost" type="submit">목록 내보내기</button>
    </div>
  </form>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">올리기</h2>
  <p class="muted">담당교사(owner/manager)만 가능합니다. 잘못된 행은 건너뛰고 아래에 이유를 보여 줍니다. 열: 품목ID, 품명, 유형(장비/비품/소모품/부품), 설명, 태그, 단위, 최소재고, 에듀파인번호(옵션 필드), 제조사, 즐겨찾기, 위치, 수량, 관리번호.</p>
  <form method="post" action="<?= Support::e(App::url('catalog/csv/import')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="field">
      <label for="csv-file">CSV 파일</label>
      <input id="csv-file" type="file" name="csv" accept=".csv,text/csv" required>
    </div>
    <button class="btn btn-primary" type="submit">가져오기</button>
  </form>
</div>

<?php if (is_array($result)): ?>
  <div class="card">
    <h2 class="section-title" style="margin-top:0">마지막 가져오기</h2>
    <p>신규 <?= (int) $result['created'] ?> · 수정 <?= (int) $result['updated'] ?> · 건너뜀 <?= (int) $result['skipped'] ?></p>
    <?php if (!empty($result['skipped_rows']) && is_array($result['skipped_rows'])): ?>
      <?php foreach (array_slice($result['skipped_rows'], 0, 50) as $skip): ?>
        <div class="list-row">
          <div>
            <div class="title"><?= Support::e((string) ($skip['name'] ?? '')) ?: '이름 없음' ?></div>
            <div class="meta"><?= Support::e((string) ($skip['reason'] ?? '')) ?></div>
          </div>
          <span class="badge"><?= (int) ($skip['line'] ?? 0) ?>행</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
