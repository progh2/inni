<?php

use Inni\App;
use Inni\Support;
?>
<h1>사용자</h1>
<div class="card" style="margin-top:1rem">
  <?php foreach ($users as $u): ?>
    <div class="list-row" style="align-items:flex-start">
      <div>
        <div class="title"><?= Support::e($u['display_name']) ?></div>
        <div class="meta"><?= Support::e($u['email']) ?> · <?= Support::e($u['role']) ?> · <?= Support::e($u['status']) ?></div>
      </div>
      <form method="post" action="<?= Support::e(App::url('settings/approve')) ?>" style="display:flex;gap:0.35rem;flex-wrap:wrap">
        <input type="hidden" name="user_id" value="<?= Support::e($u['id']) ?>">
        <select name="role">
          <?php foreach (['owner','manager','teacher','student'] as $role): ?>
            <option value="<?= $role ?>" <?= $u['role'] === $role ? 'selected' : '' ?>><?= $role ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status">
          <?php foreach (['active','pending','disabled'] as $st): ?>
            <option value="<?= $st ?>" <?= $u['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost" type="submit">적용</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
