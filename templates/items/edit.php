<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;

$tags = Support::jsonDecode($item['tags'] ?? null);
$tagText = implode(', ', $tags);
?>
<p class="muted"><a href="<?= Support::e(App::url('items/show', ['id' => $item['id']])) ?>">← 품목</a></p>
<h1>품목 수정</h1>
<p class="muted"><?= Support::e(Support::typeLabel($item['type'])) ?> · 유형은 바꿀 수 없습니다.</p>

<form class="card" method="post" action="<?= Support::e(App::url('items/update')) ?>" enctype="multipart/form-data" style="margin-top:1rem">
  <?= Csrf::field() ?>
  <input type="hidden" name="item_id" value="<?= Support::e($item['id']) ?>">
  <div class="field">
    <label>이름 *</label>
    <input name="name" required value="<?= Support::e($item['name']) ?>">
  </div>
  <div class="field">
    <label>에듀파인 번호 (옵션)</label>
    <input name="edufine_number" value="<?= Support::e($item['edufine_number'] ?? '') ?>">
  </div>
  <div class="field">
    <label>제조사</label>
    <input name="manufacturer" value="<?= Support::e($item['manufacturer'] ?? '') ?>">
  </div>
  <div class="field">
    <label>단위 / 최소재고 (소모품)</label>
    <div style="display:flex;gap:0.5rem">
      <input name="unit" value="<?= Support::e($item['unit'] ?? 'ea') ?>" style="max-width:6rem">
      <input name="min_stock" type="number" step="0.1" min="0" placeholder="최소재고" value="<?= $item['min_stock'] !== null && $item['min_stock'] !== '' ? Support::e((string) $item['min_stock']) : '' ?>">
    </div>
  </div>
  <div class="field">
    <label>태그 (쉼표)</label>
    <input name="tags" value="<?= Support::e($tagText) ?>" placeholder="계측,전자">
  </div>
  <div class="field">
    <label>사진</label>
    <?php if (!empty($item['image_path'])): ?>
      <p class="muted">새 사진을 올리면 교체됩니다.</p>
    <?php endif; ?>
    <input type="file" name="photo" accept="image/*" capture="environment">
  </div>
  <div class="field">
    <label>메모</label>
    <textarea name="notes" rows="2"><?= Support::e($item['description'] ?? '') ?></textarea>
  </div>
  <div class="field">
    <label>
      <input type="checkbox" name="favorite" value="1" <?= !empty($item['favorite']) ? 'checked' : '' ?>>
      즐겨찾기
    </label>
  </div>
  <button class="btn btn-primary btn-block" type="submit">저장</button>
</form>
