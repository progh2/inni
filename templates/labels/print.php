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
document.addEventListener('DOMContentLoaded', async () => {
  const items = <?= json_encode(array_values($labels), JSON_UNESCAPED_UNICODE) ?>;
  const waitForQr = async () => {
    const start = Date.now();
    while (!window.QRCode && Date.now() - start < 2500) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    return window.QRCode;
  };
  if (!(await waitForQr())) {
    document.querySelectorAll('#labels canvas').forEach((el) => {
      el.replaceWith(Object.assign(document.createElement('div'), {
        className: 'code',
        textContent: 'QR 스크립트를 불러오지 못했습니다.'
      }));
    });
    return;
  }
  for (let i = 0; i < items.length; i++) {
    const canvas = document.getElementById('qr-' + i);
    if (!canvas) continue;
    const payload = items[i].qr_code || items[i].code || '';
    if (!payload) continue;
    try {
      const url = await QRCode.toDataURL(payload, {
        width: 240,
        margin: 1,
        errorCorrectionLevel: 'M',
        color: { dark: '#1a2a28', light: '#ffffff' }
      });
      const img = document.createElement('img');
      img.src = url;
      img.width = 120;
      img.height = 120;
      img.alt = payload;
      canvas.replaceWith(img);
    } catch (err) {
      canvas.replaceWith(Object.assign(document.createElement('div'), {
        className: 'code',
        textContent: payload
      }));
    }
  }
});
</script>
