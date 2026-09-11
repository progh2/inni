<?php

use Inni\App;
use Inni\Auth;
use Inni\Support;

/** @var bool $google */
/** @var string $googleStatus */
/** @var string $googleRedirectUri */
/** @var bool $demo */
$googleStatus = $googleStatus ?? Auth::GOOGLE_STATUS_EMPTY;
$googleRedirectUri = $googleRedirectUri ?? Auth::googleRedirectUri();
?>
<div class="hero-login">
  <div class="panel">
    <h1>inni</h1>
    <p class="muted">Intelligent Inventory Navigation Interface<br>학교 기자재·비품·소모품을 쉽게.</p>

    <?php if (!empty($google)): ?>
      <p style="margin-top:1.25rem">
        <a class="btn btn-ink btn-block" href="<?= Support::e(App::url('auth/google')) ?>">Google로 로그인</a>
      </p>
    <?php elseif (($googleStatus ?? '') === Auth::GOOGLE_STATUS_PARTIAL): ?>
      <p class="flash error">Google 클라이언트가 불완전합니다. <code>config.php</code>의 <code>google.client_id</code>와 <code>client_secret</code>을 모두 채우세요. 한쪽만 있으면 로그인 버튼이 나타나지 않습니다.</p>
    <?php elseif (empty($demo)): ?>
      <p class="flash error">Google 클라이언트 ID/시크릿이 비어 있습니다. <code>config.php</code>에 넣고, 아래 리디렉션 URI를 Google Cloud Console에 그대로 등록하세요.</p>
    <?php else: ?>
      <p class="muted" style="margin-top:1.25rem">Google 로그인은 <code>config.php</code>의 <code>client_id</code> / <code>client_secret</code>이 비어 있어 꺼져 있습니다.</p>
    <?php endif; ?>

    <?php if (!empty($demo)): ?>
      <p class="muted" style="margin:1rem 0 0.5rem">데모 (로컬/체험용)</p>
      <div class="actions" style="flex-direction:column">
        <a class="btn btn-primary btn-block" href="<?= Support::e(App::url('auth/demo', ['as' => 'owner'])) ?>">담당교사로 들어가기</a>
        <a class="btn btn-ghost btn-block" href="<?= Support::e(App::url('auth/demo', ['as' => 'teacher'])) ?>">일반교사로 들어가기</a>
      </div>
    <?php endif; ?>

    <p class="muted" style="margin:1.25rem 0 0.35rem">Google Cloud Console 승인된 리디렉션 URI (아래 문자열을 그대로 등록)</p>
    <code class="redirect-uri"><?= Support::e($googleRedirectUri) ?></code>
  </div>
</div>
