<?php

use Inni\App;
use Inni\AssetLife;
use Inni\Auth;
use Inni\Budget;
use Inni\Csrf;
use Inni\Support;
?>
<p class="muted"><a href="<?= Support::e(App::url('search')) ?>">← 찾기</a></p>
<h1><?= Support::e($asset['name']) ?></h1>
<p class="muted">
  <?= Support::e($asset['management_number']) ?>
  · <span class="badge <?= Support::e($asset['status']) ?>"><?= Support::e(Support::statusLabel($asset['status'])) ?></span>
</p>
<p class="muted"><?= Support::e($path) ?></p>
<p class="muted">QR: <code><?= Support::e($asset['qr_code']) ?></code></p>
<?php
$assetBudget = Budget::format(
    isset($asset['budget_program']) ? (string) $asset['budget_program'] : null,
    $asset['budget_year'] ?? null,
);
$catalogBudget = Budget::format(
    isset($asset['catalog_budget_program']) ? (string) $asset['catalog_budget_program'] : null,
    $asset['catalog_budget_year'] ?? null,
);
$budgetLabel = $assetBudget !== '' ? $assetBudget : $catalogBudget;
$lifeLabel = AssetLife::format(
    isset($asset['purchase_date']) ? (string) $asset['purchase_date'] : null,
    $asset['useful_life_years'] ?? null,
);
?>
<?php if ($lifeLabel !== ''): ?>
  <p class="muted"><?= Support::e($lifeLabel) ?></p>
<?php endif; ?>
<?php if ($budgetLabel !== ''): ?>
  <p class="muted">구입 <?= Support::e($budgetLabel) ?></p>
<?php endif; ?>

<?php if (!empty($asset['image_path'])): ?>
  <p><img class="thumb" src="<?= Support::e(App::baseUrl() . $asset['image_path']) ?>" alt=""></p>
<?php endif; ?>

<?php if ($asset['status'] === 'available' && Auth::canLoan($user)): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">대여</h2>
  <form method="post" action="<?= Support::e(App::url('assets/loan')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="asset_id" value="<?= Support::e($asset['id']) ?>">
    <div class="field">
      <label>빌리는 사람</label>
      <input name="borrower_name" value="<?= Support::e($user['display_name']) ?>" required>
    </div>
    <div class="field">
      <label>학번/비고</label>
      <input name="borrower_note" placeholder="3-2 / 프로젝트명">
    </div>
    <div class="field">
      <label>용도</label>
      <input name="purpose" placeholder="5교시 실습">
    </div>
    <div class="field">
      <label>반납 예정</label>
      <input type="datetime-local" name="due_at">
    </div>
    <button class="btn btn-primary btn-block" type="submit">대여 확정</button>
  </form>
</div>
<?php endif; ?>

<?php if ($loan): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">현재 대여</h2>
  <p><strong><?= Support::e($loan['borrower_name']) ?></strong>
    <span class="badge <?= Support::e($loan['status']) ?>"><?= Support::e(Support::statusLabel($loan['status'])) ?></span>
  </p>
  <p class="muted">예정 <?= Support::e(Support::formatWhen($loan['due_at'])) ?></p>
  <?php if (Auth::canReturn($user, $loan)): ?>
    <form method="post" action="<?= Support::e(App::url('loans/return')) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="loan_id" value="<?= Support::e($loan['id']) ?>">
      <button class="btn btn-ink btn-block" type="submit">반납 처리</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">위치 이동</h2>
  <form method="post" action="<?= Support::e(App::url('assets/move')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="asset_id" value="<?= Support::e($asset['id']) ?>">
    <div class="field">
      <label>새 위치</label>
      <select name="location_id">
        <?php foreach ($locations as $l): ?>
          <option value="<?= Support::e($l['id']) ?>" <?= $l['id'] === $asset['location_id'] ? 'selected' : '' ?>>
            <?= Support::e(Support::kindLabel($l['kind'])) ?> · <?= Support::e($l['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-ghost" type="submit">이동</button>
  </form>
</div>

<?php if (Auth::canWrite($user)): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">도입일 · 내용연한</h2>
  <form method="post" action="<?= Support::e(App::url('assets/life')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="asset_id" value="<?= Support::e($asset['id']) ?>">
    <div class="grid-2">
      <div class="field">
        <label>도입일</label>
        <input name="purchase_date" type="date" value="<?= Support::e(AssetLife::formatDate(isset($asset['purchase_date']) ? (string) $asset['purchase_date'] : null)) ?>">
      </div>
      <div class="field">
        <label>내용연한(년)</label>
        <input name="useful_life_years" type="number" inputmode="numeric" min="1" max="100" step="1" placeholder="예: 5" value="<?= $asset['useful_life_years'] !== null && $asset['useful_life_years'] !== '' ? Support::e((string) $asset['useful_life_years']) : '' ?>">
      </div>
    </div>
    <?php
      $lifeSuggestNameFrom = '';
      $lifeSuggestName = (string) ($asset['name'] ?? '');
      $lifeSuggestions = $lifeSuggestions ?? [];
      require dirname(__DIR__) . '/partials/life_suggest.php';
    ?>
    <?php
      $expiry = AssetLife::expiryDate(
          isset($asset['purchase_date']) ? (string) $asset['purchase_date'] : null,
          $asset['useful_life_years'] ?? null,
      );
    ?>
    <?php if ($expiry !== null): ?>
      <p class="muted">만료 예정일 <?= Support::e($expiry) ?></p>
    <?php endif; ?>
    <button class="btn btn-ghost" type="submit">저장</button>
  </form>
</div>
<div class="card">
  <h2 class="section-title" style="margin-top:0">구입 사업예산</h2>
  <form method="post" action="<?= Support::e(App::url('assets/budget')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="asset_id" value="<?= Support::e($asset['id']) ?>">
    <div class="grid-2">
      <div class="field">
        <label>구입 사업명</label>
        <input name="budget_program" maxlength="200" placeholder="자유 입력 (선택)" value="<?= Support::e($asset['budget_program'] ?? '') ?>">
      </div>
      <div class="field">
        <label>예산 연도</label>
        <input name="budget_year" type="number" inputmode="numeric" min="1900" max="2100" step="1" placeholder="YYYY" value="<?= $asset['budget_year'] !== null && $asset['budget_year'] !== '' ? Support::e((string) $asset['budget_year']) : '' ?>">
      </div>
    </div>
    <button class="btn btn-ghost" type="submit">저장</button>
  </form>
</div>
<div class="card">
  <h2 class="section-title" style="margin-top:0">사진 업로드</h2>
  <form method="post" action="<?= Support::e(App::url('assets/photo')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="asset_id" value="<?= Support::e($asset['id']) ?>">
    <div class="field">
      <label>사진</label>
      <input type="file" name="photo" accept="image/*" capture="environment" required>
    </div>
    <button class="btn btn-ghost" type="submit">업로드</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">고장 · 이상 신고</h2>
  <form method="post" action="<?= Support::e(App::url('assets/report')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="asset_id" value="<?= Support::e($asset['id']) ?>">
    <div class="field">
      <label>제목</label>
      <input name="title" required placeholder="전원 안 켜짐">
    </div>
    <div class="field">
      <label>내용</label>
      <textarea name="body" rows="3" required></textarea>
    </div>
    <div class="field">
      <label>사진</label>
      <input type="file" name="photo" accept="image/*" capture="environment">
    </div>
    <button class="btn btn-ghost" type="submit">신고</button>
  </form>
  <?php foreach ($reports as $r): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($r['title']) ?></div>
        <div class="meta"><?= Support::e(Support::statusLabel($r['status'])) ?> · <?= Support::e(Support::formatWhen($r['created_at'])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

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

<p style="margin-top:1rem">
  <a class="btn btn-ghost" href="<?= Support::e(App::url('labels')) ?>">라벨 인쇄로 이동</a>
</p>
