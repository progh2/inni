<?php

use Inni\App;
use Inni\Auth;
use Inni\Support;
?>
<h1><?= Support::e($item['name']) ?></h1>
<p class="muted"><?= Support::e(Support::typeLabel($item['type'])) ?> · QR <code><?= Support::e($item['qr_code']) ?></code></p>

<?php if (!empty($item['image_path'])): ?>
  <p><img class="thumb" src="<?= Support::e(App::baseUrl() . $item['image_path']) ?>" alt=""></p>
<?php endif; ?>

<?php if ($assets): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">개체</h2>
  <?php foreach ($assets as $a): ?>
    <a class="list-row" href="<?= Support::e(App::url('assets/show', ['id' => $a['id']])) ?>">
      <div>
        <div class="title"><?= Support::e($a['name']) ?></div>
        <div class="meta"><?= Support::e($a['management_number']) ?> · <?= Support::e($a['location_name']) ?></div>
      </div>
      <span class="badge <?= Support::e($a['status']) ?>"><?= Support::e(Support::statusLabel($a['status'])) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($lots): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">위치별 재고</h2>
  <?php foreach ($lots as $lot): ?>
    <div class="list-row">
      <div class="title"><?= Support::e($lot['location_name']) ?></div>
      <div><strong><?= Support::e((string) $lot['quantity']) ?></strong> <?= Support::e($item['unit']) ?></div>
    </div>
    <?php if (in_array($item['type'], ['consumable', 'part'], true) && Auth::canLoan($user) && (float) $lot['quantity'] > 0): ?>
    <form method="post" action="<?= Support::e(App::url('items/issue')) ?>">
      <input type="hidden" name="csrf_token" value="<?= Support::e($_SESSION['stock_csrf']) ?>">
      <input type="hidden" name="item_id" value="<?= Support::e($item['id']) ?>">
      <input type="hidden" name="lot_id" value="<?= Support::e($lot['id']) ?>">
      <div class="field">
        <label for="quantity-<?= Support::e($lot['id']) ?>">출고 수량 (<?= Support::e($item['unit']) ?>)</label>
        <input id="quantity-<?= Support::e($lot['id']) ?>" name="quantity" type="number" min="0" max="<?= Support::e((string) $lot['quantity']) ?>" step="any" inputmode="decimal" required>
      </div>
      <div class="field">
        <label for="purpose-<?= Support::e($lot['id']) ?>">사용 사유</label>
        <input id="purpose-<?= Support::e($lot['id']) ?>" name="purpose" placeholder="예: 5교시 실습, 프로젝트 제작" required>
      </div>
      <button class="btn btn-primary btn-block" type="submit">사용 출고</button>
      <p class="muted">선택한 위치의 재고에서 차감됩니다.</p>
    </form>
    <?php elseif (in_array($item['type'], ['consumable', 'part'], true) && (float) $lot['quantity'] <= 0): ?>
      <p class="muted">출고할 재고가 없습니다.</p>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">이력</h2>
  <?php foreach ($logs as $log): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($log['summary']) ?></div>
        <div class="meta"><?= Support::e($log['actor_name']) ?> · <?= Support::e(Support::formatWhen($log['created_at'])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$logs): ?><p class="muted">이력이 없습니다.</p><?php endif; ?>
</div>
