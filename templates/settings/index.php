<?php

use Inni\App;
use Inni\Support;
?>
<h1>학교 설정</h1>
<form class="card" method="post" action="<?= Support::e(App::url('settings/save')) ?>" style="margin-top:1rem">
  <div class="field">
    <label>학교명</label>
    <input name="school_name" value="<?= Support::e($school) ?>" required>
  </div>
  <button class="btn btn-primary" type="submit">저장</button>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0">Google 로그인</h2>
  <?php if ($google): ?>
    <p class="muted">config.php에 클라이언트 ID/시크릿이 설정되어 있습니다.</p>
  <?php else: ?>
    <p class="muted">아직 비활성입니다. `config.php`의 `google.client_id` / `client_secret`을 채우면 로그인 화면에 버튼이 나타납니다.</p>
    <p class="muted">리디렉션 URI: <code><?= Support::e(App::url('auth/google/callback')) ?></code></p>
  <?php endif; ?>
</div>
