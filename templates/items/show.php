<?php

use Inni\App;
use Inni\AssetLife;
use Inni\Auth;
use Inni\Budget;
use Inni\Csrf;
use Inni\StockLot;
use Inni\Support;

$tags = Support::jsonDecode($item['tags'] ?? null);
$stockable = in_array($item['type'], ['fixture', 'consumable', 'part'], true);
$issueable = in_array($item['type'], ['consumable', 'part'], true);
?>
<p class="muted"><a href="<?= Support::e(App::url('items')) ?>">← 품목 목록</a> · <a href="<?= Support::e(App::url('search')) ?>">찾기</a></p>
<h1><?= Support::e($item['name']) ?></h1>
<p class="muted">
  <?= Support::e(Support::typeLabel($item['type'])) ?>
  · QR <code><?= Support::e($item['qr_code']) ?></code>
  <?php if (!empty($item['favorite'])): ?> · 즐겨찾기<?php endif; ?>
</p>
<?php
$budgetLabel = Budget::format(
    isset($item['budget_program']) ? (string) $item['budget_program'] : null,
    $item['budget_year'] ?? null,
);
?>
<?php if ($item['manufacturer'] || $item['unit'] || $item['min_stock'] !== null || $tags || $item['description'] || $budgetLabel !== ''): ?>
  <p class="muted">
    <?php if ($item['manufacturer']): ?>제조사 <?= Support::e($item['manufacturer']) ?> · <?php endif; ?>
    단위 <?= Support::e($item['unit']) ?>
    <?php if ($item['min_stock'] !== null && $item['min_stock'] !== ''): ?> · 최소재고 <?= Support::e((string) $item['min_stock']) ?><?php endif; ?>
    <?php if ($budgetLabel !== ''): ?> · 구입 <?= Support::e($budgetLabel) ?><?php endif; ?>
    <?php if ($tags): ?> · <?= Support::e(implode(', ', $tags)) ?><?php endif; ?>
  </p>
  <?php if (!empty($item['description'])): ?>
    <p class="muted"><?= Support::e($item['description']) ?></p>
  <?php endif; ?>
<?php endif; ?>

<?php if (Auth::canWrite($user)): ?>
  <p class="actions" style="margin-top:0">
    <a class="btn btn-ghost" href="<?= Support::e(App::url('items/edit', ['id' => $item['id']])) ?>">품목 수정</a>
  </p>
<?php endif; ?>

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
        <div class="meta">
          <?= Support::e($a['management_number']) ?> · <?= Support::e($a['location_name']) ?>
          <?php
            $assetLife = AssetLife::format(
                isset($a['purchase_date']) ? (string) $a['purchase_date'] : null,
                $a['useful_life_years'] ?? null,
            );
          ?>
          <?php if ($assetLife !== ''): ?> · <?= Support::e($assetLife) ?><?php endif; ?>
        </div>
      </div>
      <span class="badge <?= Support::e($a['status']) ?>"><?= Support::e(Support::statusLabel($a['status'])) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($lots || ($stockable && Auth::canWrite($user))): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">위치별 재고</h2>
  <?php foreach ($lots as $lot): ?>
    <?php
      $lotLabel = StockLot::format(
          isset($lot['lot_code']) ? (string) $lot['lot_code'] : null,
          isset($lot['expires_at']) ? (string) $lot['expires_at'] : null,
          isset($lot['received_at']) ? (string) $lot['received_at'] : null,
      );
    ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($lot['location_name']) ?></div>
        <?php if ($lotLabel !== ''): ?>
          <div class="meta"><?= Support::e($lotLabel) ?></div>
        <?php endif; ?>
      </div>
      <div><strong><?= Support::e((string) $lot['quantity']) ?></strong> <?= Support::e($item['unit']) ?></div>
    </div>
    <?php if ($issueable && Auth::canLoan($user) && (float) $lot['quantity'] > 0): ?>
    <form method="post" action="<?= Support::e(App::url('items/issue')) ?>">
      <?= Csrf::field() ?>
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
    <?php elseif ($issueable && (float) $lot['quantity'] <= 0): ?>
      <p class="muted">출고할 재고가 없습니다.</p>
    <?php endif; ?>
    <?php if ($stockable && Auth::canWrite($user)): ?>
    <form method="post" action="<?= Support::e(App::url('items/restock')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="item_id" value="<?= Support::e($item['id']) ?>">
      <input type="hidden" name="location_id" value="<?= Support::e($lot['location_id']) ?>">
      <div class="field">
        <label for="restock-<?= Support::e($lot['id']) ?>">재입고 수량 (<?= Support::e($item['unit']) ?>)</label>
        <input id="restock-<?= Support::e($lot['id']) ?>" name="quantity" type="number" min="0" step="any" inputmode="decimal" required>
      </div>
      <div class="field">
        <label for="restock-note-<?= Support::e($lot['id']) ?>">입고 메모</label>
        <input id="restock-note-<?= Support::e($lot['id']) ?>" name="note" placeholder="예: 학기 초 보충">
      </div>
      <?php
        $idSuffix = 'restock-' . $lot['id'];
        $lotCode = '';
        $expiresAt = null;
        $receivedAt = null;
        require dirname(__DIR__) . '/partials/lot_fields.php';
      ?>
      <p class="muted">로트·날짜를 비우면 기존 값을 유지합니다.</p>
      <button class="btn btn-ink btn-block" type="submit">재입고</button>
    </form>
    <form method="post" action="<?= Support::e(App::url('items/lot')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="item_id" value="<?= Support::e($item['id']) ?>">
      <input type="hidden" name="lot_id" value="<?= Support::e($lot['id']) ?>">
      <?php
        $idSuffix = 'edit-' . $lot['id'];
        $lotCode = isset($lot['lot_code']) ? (string) $lot['lot_code'] : '';
        $expiresAt = isset($lot['expires_at']) ? (string) $lot['expires_at'] : null;
        $receivedAt = isset($lot['received_at']) ? (string) $lot['received_at'] : null;
        require dirname(__DIR__) . '/partials/lot_fields.php';
      ?>
      <button class="btn btn-ghost" type="submit">로트 정보 저장</button>
    </form>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if ($stockable && Auth::canWrite($user) && $openLocations): ?>
    <h3 class="section-title">다른 위치에 입고</h3>
    <form method="post" action="<?= Support::e(App::url('items/restock')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="item_id" value="<?= Support::e($item['id']) ?>">
      <div class="field">
        <label for="restock-new-location">위치</label>
        <select id="restock-new-location" name="location_id" required>
          <?php foreach ($openLocations as $location): ?>
            <option value="<?= Support::e($location['id']) ?>">
              <?= Support::e(Support::kindLabel($location['kind'])) ?> · <?= Support::e($location['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="restock-new-qty">입고 수량 (<?= Support::e($item['unit']) ?>)</label>
        <input id="restock-new-qty" name="quantity" type="number" min="0" step="any" inputmode="decimal" required>
      </div>
      <div class="field">
        <label for="restock-new-note">입고 메모</label>
        <input id="restock-new-note" name="note" placeholder="예: 새 보관함">
      </div>
      <?php
        $idSuffix = 'restock-new';
        $lotCode = '';
        $expiresAt = null;
        $receivedAt = null;
        require dirname(__DIR__) . '/partials/lot_fields.php';
      ?>
      <button class="btn btn-ink btn-block" type="submit">이 위치에 입고</button>
    </form>
  <?php endif; ?>
  <?php if (!$lots && !Auth::canWrite($user)): ?><p class="muted">위치별 재고가 없습니다.</p><?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">이력</h2>
  <?php foreach ($logs as $log): ?>
    <?php $cancel = $cancels[$log['id']] ?? null; ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($log['summary']) ?></div>
        <div class="meta"><?= Support::e($log['actor_name']) ?> · <?= Support::e(Support::formatWhen($log['created_at'])) ?></div>
        <?php if ($log['action'] === 'issue' && $cancel): ?>
          <div class="meta">취소됨 · <?= Support::e($cancel['actor_name']) ?> · <?= Support::e($cancel['reason']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($log['action'] === 'issue' && $cancel): ?>
        <span class="badge cancelled">취소됨</span>
      <?php endif; ?>
    </div>
    <?php if ($log['action'] === 'issue' && !$cancel && Auth::canLoan($user)): ?>
    <form method="post" action="<?= Support::e(App::url('items/cancel-issue')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="item_id" value="<?= Support::e($item['id']) ?>">
      <input type="hidden" name="issue_log_id" value="<?= Support::e($log['id']) ?>">
      <div class="field">
        <label for="cancel-reason-<?= Support::e($log['id']) ?>">취소 사유</label>
        <input id="cancel-reason-<?= Support::e($log['id']) ?>" name="reason" required placeholder="예: 수량 오입력">
      </div>
      <button class="btn btn-ghost" type="submit">출고 취소</button>
    </form>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$logs): ?><p class="muted">이력이 없습니다.</p><?php endif; ?>
</div>
