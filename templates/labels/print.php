<?php

use Inni\Support;
?>
<div class="sheet" id="labels">
  <?php foreach ($labels as $i => $label): ?>
    <div class="label">
      <canvas id="qr-<?= $i ?>" width="84" height="84"></canvas>
      <div>
        <div class="name"><?= Support::e($label['name']) ?></div>
        <div class="code"><?= Support::e($label['code']) ?></div>
        <div class="school"><?= Support::e($school) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$labels): ?>
  <p>선택된 항목이 없습니다. 뒤로 가 다시 선택하세요.</p>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const items = <?= json_encode(array_values($labels), JSON_UNESCAPED_UNICODE) ?>;
  items.forEach((item, i) => {
    const canvas = document.getElementById('qr-' + i);
    if (canvas && window.QRCode) {
      QRCode.toCanvas(canvas, item.qr_code, { width: 84, margin: 1, color: { dark: '#1a2a28', light: '#ffffff' } });
    }
  });
});
</script>
