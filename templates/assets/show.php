<?php

use Inni\App;
use Inni\AssetLife;
use Inni\Auth;
use Inni\Budget;
use Inni\Csrf;
use Inni\Support;

/** @var array<string, mixed>|null $retirement */
$retirement = $retirement ?? null;
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

<?php if ($asset['status'] !== 'retired'): ?>
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
<?php endif; ?>

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

<?php if (Auth::canWrite($user)): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">파기</h2>
  <?php if ($asset['status'] === 'retired' && is_array($retirement ?? null)): ?>
    <p>사유 <?= Support::e((string) ($retirement['reason'] ?? '')) ?></p>
    <p class="muted">일자 <?= Support::e((string) ($retirement['retired_on'] ?? '')) ?>
      · <?= Support::e((string) ($retirement['actor_name'] ?? '')) ?></p>
    <?php if (!empty($retirement['evidence_path'])): ?>
      <p><img class="thumb" src="<?= Support::e(App::baseUrl() . $retirement['evidence_path']) ?>" alt="파기 증빙"></p>
    <?php endif; ?>
    <p class="muted">파기는 되돌릴 수 없습니다.</p>
  <?php elseif ($asset['status'] === 'retired'): ?>
    <p class="muted">파기된 장비입니다. 되돌릴 수 없습니다.</p>
  <?php elseif ($asset['status'] === 'on_loan'): ?>
    <p class="muted">대여 중인 장비는 반납 후 파기하세요.</p>
  <?php else: ?>
    <p class="muted">파기는 되돌릴 수 없습니다. 담당교사(manager 이상)만 처리할 수 있습니다.</p>
    <form method="post" action="<?= Support::e(App::url('assets/retire')) ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <input type="hidden" name="asset_id" value="<?= Support::e($asset['id']) ?>">
      <div class="field">
        <label>파기 일자</label>
        <input name="retired_on" type="date" required value="<?= Support::e(date('Y-m-d')) ?>">
      </div>
      <div class="field">
        <label>파기 사유</label>
        <input name="reason" maxlength="200" required placeholder="내용연한 만료 · 실사 미확인">
      </div>
      <div class="field">
        <label>증빙 사진 (선택)</label>
        <input type="file" name="evidence" accept="image/*" capture="environment">
      </div>
      <div class="check-row">
        <input id="retire-confirm" type="checkbox" name="confirm_irreversible" value="1" required>
        <label for="retire-confirm">되돌릴 수 없음을 확인합니다</label>
      </div>
      <button class="btn btn-ink btn-block" type="submit">파기 처리</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">수리 요청</h2>
  <?php if (Auth::canLoan($user) && !in_array($asset['status'], ['lost', 'retired'], true)): ?>
    <form method="post" action="<?= Support::e(App::url('assets/report')) ?>" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <input type="hidden" name="asset_id" value="<?= Support::e($asset['id']) ?>">
      <div class="field">
        <label>증상</label>
        <textarea name="body" rows="3" required placeholder="전원 안 켜짐, 화면이 깜빡임 등"></textarea>
      </div>
      <div class="field">
        <label>사진 (선택)</label>
        <input type="file" name="photo" accept="image/*" capture="environment">
      </div>
      <button class="btn btn-primary btn-block" type="submit">수리 요청</button>
    </form>
  <?php elseif (Auth::canLoan($user)): ?>
    <p class="muted">폐기·분실 장비는 수리 요청할 수 없습니다.</p>
  <?php endif; ?>
  <?php foreach ($reports as $r): ?>
    <div class="list-row">
      <div>
        <?php if (Auth::canWrite($user)): ?>
          <a class="title" href="<?= Support::e(App::url('reports/show', ['id' => $r['id']])) ?>"><?= Support::e($r['title']) ?></a>
        <?php else: ?>
          <div class="title"><?= Support::e($r['title']) ?></div>
        <?php endif; ?>
        <div class="meta"><?= Support::e(Support::statusLabel($r['status'])) ?> · <?= Support::e($r['reporter_name']) ?> · <?= Support::e(Support::formatWhen($r['created_at'])) ?></div>
      </div>
      <span class="badge <?= Support::e($r['status']) ?>"><?= Support::e(Support::statusLabel($r['status'])) ?></span>
    </div>
    <?php
      $report = $r;
      $returnTo = 'asset';
      require dirname(__DIR__) . '/partials/report_status.php';
    ?>
  <?php endforeach; ?>
  <?php if (!$reports): ?><p class="muted">아직 수리 요청이 없습니다.</p><?php endif; ?>
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
