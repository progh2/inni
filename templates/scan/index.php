<?php

use Inni\App;
use Inni\Support;
?>
<h1>스캔</h1>
<p class="muted">QR/바코드를 찍거나 관리번호를 직접 입력하세요.</p>

<div class="card" style="margin-top:1rem">
  <div id="qr-reader"></div>
  <p class="muted" id="scan-hint" style="margin-top:0.5rem">카메라 권한이 필요합니다. HTTPS 또는 localhost에서 동작합니다.</p>
</div>

<form class="card" method="post" action="<?= Support::e(App::url('scan/resolve')) ?>">
  <div class="field">
    <label>코드 직접 입력</label>
    <input name="code" placeholder="AST:… / 전장-2024-017 / E-201" required>
  </div>
  <button class="btn btn-primary btn-block" type="submit">열기</button>
</form>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(() => {
  const formAction = <?= json_encode(App::url('scan/resolve'), JSON_UNESCAPED_SLASHES) ?>;
  function go(code) {
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = formAction;
    const i = document.createElement('input');
    i.name = 'code';
    i.value = code;
    f.appendChild(i);
    document.body.appendChild(f);
    f.submit();
  }
  if (!window.Html5Qrcode) return;
  const scanner = new Html5Qrcode('qr-reader');
  Html5Qrcode.getCameras().then(cams => {
    if (!cams.length) {
      document.getElementById('scan-hint').textContent = '카메라를 찾지 못했습니다. 코드 입력을 사용하세요.';
      return;
    }
    scanner.start(
      { facingMode: 'environment' },
      { fps: 8, qrbox: { width: 240, height: 240 } },
      (decoded) => { scanner.stop().finally(() => go(decoded)); },
      () => {}
    ).catch(() => {
      document.getElementById('scan-hint').textContent = '카메라 시작 실패. 권한을 허용하거나 코드 입력을 사용하세요.';
    });
  }).catch(() => {});
})();
</script>
