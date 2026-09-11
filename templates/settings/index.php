<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Support;
?>
<h1>학교 설정</h1>
<form class="card" method="post" action="<?= Support::e(App::url('settings/save')) ?>" style="margin-top:1rem">
  <?= Csrf::field() ?>
  <div class="field">
    <label>학교명</label>
    <input name="school_name" value="<?= Support::e($school) ?>" required>
  </div>
  <button class="btn btn-primary" type="submit">저장</button>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0">Google 로그인</h2>
  <?php if (!empty($google)): ?>
    <p class="muted">config.php에 클라이언트 ID/시크릿이 설정되어 있습니다. 로그인 화면에 Google 버튼이 표시됩니다.</p>
  <?php elseif (($googleStatus ?? '') === Auth::GOOGLE_STATUS_PARTIAL): ?>
    <p class="flash error">설정이 불완전합니다. <code>google.client_id</code>와 <code>client_secret</code>을 모두 넣어야 합니다. 한쪽만 있으면 버튼이 나타나지 않습니다.</p>
  <?php else: ?>
    <p class="muted">아직 비활성입니다. <code>config.php</code>의 <code>google.client_id</code> / <code>client_secret</code>이 비어 있습니다. 둘 다 채우면 로그인 화면에 버튼이 나타납니다.</p>
  <?php endif; ?>

  <p class="muted" style="margin-top:1rem">Google Cloud Console → 사용자 인증 정보 → OAuth 클라이언트(웹)의 <strong>승인된 리디렉션 URI</strong>에 아래 문자열을 <strong>그대로</strong> 넣으세요. <code>/auth/google/callback</code> 같은 예쁜 경로는 라우트가 아닙니다.</p>
  <code class="redirect-uri"><?= Support::e($googleRedirectUri ?? Auth::googleRedirectUri()) ?></code>

  <?php if (empty($baseUrlConfigured) && empty($redirectUriOverride)): ?>
    <p class="muted" style="margin-top:0.75rem">운영에서는 <code>base_url</code>을 공개 URL로 고정하세요. 비어 있으면 호스트/프록시에 따라 URI가 달라져 Console과 어긋날 수 있습니다.</p>
  <?php endif; ?>

  <?php if (!empty($allowedDomains)): ?>
    <p class="muted" style="margin-top:0.75rem">허용 도메인(정확히 일치, 대소문자 무시): <?= Support::e(implode(', ', $allowedDomains)) ?>. 목록에 없는 메일은 거부됩니다.</p>
  <?php else: ?>
    <p class="muted" style="margin-top:0.75rem"><code>allowed_domains</code>가 비어 있으면 Google이 인증한 모든 이메일 도메인을 받습니다. 학교 메일만 받으려면 예: <code>['school.go.kr']</code>.</p>
  <?php endif; ?>

  <?php if (!empty($demoLogin)): ?>
    <p class="flash error" style="margin-top:0.85rem">운영에서는 <code>demo_login =&gt; false</code>로 데모 로그인을 끄세요.</p>
  <?php endif; ?>
</div>
