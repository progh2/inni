<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Support;

/** @var array $user */
/** @var string $q */
/** @var ?array $asset */
/** @var ?array $loan */
/** @var list<array<string, mixed>> $hits */
/** @var list<array{id: string, display_name: string, role: string}> $borrowers */
/** @var string $dueLocal */

$scanAction = App::url('loans/desk/resolve');
$scanSubmitLabel = '확인';
$scanHintText = 'QR을 찍으면 바로 빌려주기·받아주기가 뜹니다.';
?>
<p class="muted"><a href="<?= Support::e(App::url('home')) ?>">← 홈</a></p>
<h1>대여 데스크</h1>
<p class="muted">스캔하거나 찾아 한 번에 빌려주고 받아주세요. 기본 차용자는 지금 로그인한 교사, 기한은 오늘 17시입니다.</p>

<?php if ($asset): ?>
  <?php
    $canLoanNow = ($asset['status'] ?? '') === 'available' && Auth::canLoan($user);
    $canReturnNow = is_array($loan) && Auth::canReturn($user, $loan);
  ?>
  <div class="card desk-cta">
    <div class="list-row" style="padding-top:0">
      <div>
        <div class="title"><?= Support::e((string) $asset['name']) ?></div>
        <div class="meta">
          <?= Support::e((string) $asset['management_number']) ?>
          · <?= Support::e((string) ($asset['location_name'] ?? '')) ?>
        </div>
      </div>
      <span class="badge <?= Support::e((string) $asset['status']) ?>"><?= Support::e(Support::statusLabel((string) $asset['status'])) ?></span>
    </div>

    <?php if ($canReturnNow && $loan): ?>
      <p>
        <strong><?= Support::e((string) $loan['borrower_name']) ?></strong>
        · 예정 <?= Support::e(Support::formatWhen($loan['due_at'] ?? null)) ?>
      </p>
      <form method="post" action="<?= Support::e(App::url('loans/return')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="loan_id" value="<?= Support::e((string) $loan['id']) ?>">
        <input type="hidden" name="return_to" value="desk">
        <button class="btn btn-ink btn-block" type="submit">받아주기</button>
      </form>
    <?php elseif ($canLoanNow): ?>
      <form method="post" action="<?= Support::e(App::url('loans/desk/loan')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="asset_id" value="<?= Support::e((string) $asset['id']) ?>">
        <div class="field" style="margin-bottom:0.5rem">
          <label>빌리는 사람</label>
          <div class="pick-chips">
            <?php foreach ($borrowers as $b): ?>
              <?php $selected = ($b['id'] ?? '') === ($user['id'] ?? ''); ?>
              <label class="pick-chip">
                <input type="radio" name="borrower_user_id" value="<?= Support::e((string) $b['id']) ?>" <?= $selected ? 'checked' : '' ?>>
                <?= Support::e((string) $b['display_name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field">
          <label>반납 예정</label>
          <input type="datetime-local" name="due_at" value="<?= Support::e($dueLocal) ?>">
        </div>
        <button class="btn btn-primary btn-block" type="submit">빌려주기</button>
      </form>
    <?php else: ?>
      <p class="muted">지금 상태에서는 대여·반납할 수 없습니다. 장비 상세에서 확인하세요.</p>
    <?php endif; ?>

    <p class="muted" style="margin-top:0.75rem">
      <a href="<?= Support::e(App::url('assets/show', ['id' => $asset['id']])) ?>">장비 상세</a>
      · <a href="<?= Support::e(App::url('loans/desk')) ?>">다시 스캔</a>
    </p>
  </div>
<?php endif; ?>

<?php require App::root() . '/templates/partials/scan_input.php'; ?>

<form method="get" action="<?= Support::e(App::url('loans/desk')) ?>" class="card">
  <input type="hidden" name="r" value="loans/desk">
  <div class="field" style="margin:0">
    <label>이름 · 관리번호로 찾기</label>
    <input type="search" name="q" value="<?= Support::e($q) ?>" placeholder="예: 오실로, 전장-2024" autocomplete="off">
  </div>
  <button class="btn btn-ghost btn-block" style="margin-top:0.75rem" type="submit">검색</button>
</form>

<?php if ($q !== ''): ?>
  <div class="card">
    <h2 class="section-title" style="margin-top:0">검색 결과 (<?= count($hits) ?>)</h2>
    <?php foreach ($hits as $hit): ?>
      <a class="list-row" href="<?= Support::e(App::url('loans/desk', ['id' => $hit['id']])) ?>">
        <div>
          <div class="title"><?= Support::e((string) $hit['name']) ?></div>
          <div class="meta"><?= Support::e((string) $hit['management_number']) ?> · <?= Support::e((string) ($hit['location_name'] ?? '')) ?></div>
        </div>
        <span class="badge <?= Support::e((string) $hit['status']) ?>"><?= Support::e(Support::statusLabel((string) $hit['status'])) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$hits): ?><p class="muted">결과 없음</p><?php endif; ?>
  </div>
<?php endif; ?>
