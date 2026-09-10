<?php

use Inni\App;
use Inni\Support;
?>
<div class="hero-login">
  <div class="panel">
    <h1>inni</h1>
    <p class="muted">Intelligent Inventory Navigation Interface<br>학교 기자재·비품·소모품을 쉽게.</p>

    <?php if (!empty($google)): ?>
      <p style="margin-top:1.25rem">
        <a class="btn btn-ink btn-block" href="<?= Support::e(App::url('auth/google')) ?>">Google로 로그인</a>
      </p>
    <?php endif; ?>

    <?php if (!empty($demo)): ?>
      <p class="muted" style="margin:1rem 0 0.5rem">데모 (로컬/체험용)</p>
      <div class="actions" style="flex-direction:column">
        <a class="btn btn-primary btn-block" href="<?= Support::e(App::url('auth/demo', ['as' => 'owner'])) ?>">담당교사로 들어가기</a>
        <a class="btn btn-ghost btn-block" href="<?= Support::e(App::url('auth/demo', ['as' => 'teacher'])) ?>">일반교사로 들어가기</a>
      </div>
    <?php endif; ?>

    <?php if (empty($google) && empty($demo)): ?>
      <p class="flash error">config.php에서 Google OAuth 또는 demo_login을 설정하세요.</p>
    <?php endif; ?>
  </div>
</div>
