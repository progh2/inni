<?php

use Inni\Support;
?>
<div class="sheet" id="labels">
  <?php foreach ($labels as $i => $label): ?>
    <div class="label">
      <canvas id="qr-<?= $i ?>" width="120" height="120"></canvas>
      <div class="text">
        <div class="name"><?= Support::e($label['name']) ?></div>
        <div class="code"><?= Support::e($label['code']) ?></div>
        <div class="school"><?= Support::e($school) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$labels): ?>
  <p class="empty">선택된 항목이 없습니다. 뒤로 가 다시 선택하세요.</p>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const items = <?= json_encode(array_values($labels), JSON_UNESCAPED_UNICODE) ?>;
  items.forEach((item, i) => {
    const canvas = document.getElementById('qr-' + i);
    if (!canvas) return;
    if (!window.QRCode) return;
    const payload = item.qr_code || item.code || '';
    if (!payload) return;
    QRCode.toCanvas(canvas, payload, {
      width: 120,
      margin: 1,
      errorCorrectionLevel: 'M',
      color: { dark: '#1a2a28', light: '#ffffff' }
    });
  });
});
</script>
