<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;
?>
<h1>스캔</h1>
<p class="muted">QR/바코드를 찍거나 관리번호를 직접 입력하세요.</p>

<div class="card scan-stage" style="margin-top:1rem">
  <div id="qr-reader" aria-label="카메라 미리보기"></div>
  <p class="muted" id="scan-hint" style="margin-top:0.5rem">카메라 권한이 필요합니다. HTTPS 또는 localhost에서 동작합니다.</p>
</div>

<form id="scan-manual-form" class="card" method="post" action="<?= Support::e(App::url('scan/resolve')) ?>">
  <?= Csrf::field() ?>
  <div class="field">
    <label for="scan-code-input">코드 직접 입력</label>
    <input id="scan-code-input" name="code" placeholder="AST:… / 전장-2024-017 / E-201" required autocomplete="off">
  </div>
  <button class="btn btn-primary btn-block" type="submit">열기</button>
</form>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(() => {
  const formAction = <?= json_encode(App::url('scan/resolve'), JSON_UNESCAPED_SLASHES) ?>;
  const csrfName = <?= json_encode(Csrf::FIELD_NAME) ?>;
  const csrfToken = <?= json_encode(Csrf::token()) ?>;
  const hint = document.getElementById('scan-hint');
  const codeInput = document.getElementById('scan-code-input');
  const stage = document.querySelector('.scan-stage');

  function useManual(message) {
    if (hint) hint.textContent = message;
    if (stage) stage.classList.add('is-fallback');
    if (codeInput) {
      codeInput.focus({ preventScroll: false });
      codeInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function hintFor(err) {
    const text = [err && err.name, err && err.message].filter(Boolean).join(' ');
    if (!window.isSecureContext) {
      return '카메라는 HTTPS 또는 localhost에서만 됩니다. 코드를 직접 입력하세요.';
    }
    if (/NotAllowed|Permission|Denied|SecurityError/i.test(text)) {
      return '카메라 권한이 거부되었습니다. 브라우저 설정에서 허용하거나 코드를 직접 입력하세요.';
    }
    if (/NotFound|DevicesNotFound/i.test(text)) {
      return '카메라를 찾지 못했습니다. 코드를 직접 입력하세요.';
    }
    if (/NotReadable|TrackStart|AbortError|in use/i.test(text)) {
      return '카메라를 시작할 수 없습니다. 다른 앱을 닫거나 코드를 직접 입력하세요.';
    }
    return '카메라 시작 실패. 권한을 허용하거나 코드를 직접 입력하세요.';
  }

  function go(code) {
    const value = String(code || '').trim();
    if (!value) {
      useManual('코드를 읽지 못했습니다. 다시 찍거나 직접 입력하세요.');
      return;
    }
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = formAction;
    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = csrfName;
    csrf.value = csrfToken;
    f.appendChild(csrf);
    const i = document.createElement('input');
    i.type = 'hidden';
    i.name = 'code';
    i.value = value;
    f.appendChild(i);
    document.body.appendChild(f);
    f.submit();
  }

  if (!window.isSecureContext) {
    useManual('카메라는 HTTPS 또는 localhost에서만 됩니다. 코드를 직접 입력하세요.');
    return;
  }
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    useManual('이 브라우저는 카메라를 지원하지 않습니다. 코드를 직접 입력하세요.');
    return;
  }
  if (!window.Html5Qrcode) {
    useManual('스캔 스크립트를 불러오지 못했습니다. 코드를 직접 입력하세요.');
    return;
  }

  const scanner = new Html5Qrcode('qr-reader');
  const scanConfig = {
    fps: 8,
    qrbox: (viewfinderWidth, viewfinderHeight) => {
      const size = Math.max(160, Math.min(240, Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.72)));
      return { width: size, height: size };
    }
  };

  function startWith(camera) {
    return scanner.start(
      camera,
      scanConfig,
      (decoded) => { scanner.stop().finally(() => go(decoded)); },
      () => {}
    );
  }

  Html5Qrcode.getCameras().then((cams) => {
    if (!cams || !cams.length) {
      useManual('카메라를 찾지 못했습니다. 코드를 직접 입력하세요.');
      return;
    }
    const rear = cams.find((c) => /back|rear|environment|후|뒤/i.test(c.label || ''));
    const preferred = rear
      ? { deviceId: { exact: rear.id } }
      : { facingMode: 'environment' };
    startWith(preferred).catch(() => {
      const fallback = cams[0] && cams[0].id ? { deviceId: { exact: cams[0].id } } : preferred;
      return startWith(fallback);
    }).catch((err) => {
      useManual(hintFor(err));
    });
  }).catch((err) => {
    useManual(hintFor(err));
  });
})();
</script>
