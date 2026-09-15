<?php

use Inni\App;
use Inni\Auth;
use Inni\Csrf;
use Inni\Support;

/** @var array $user */
/** @var array<string, mixed>|null $asset */
/** @var array<string, mixed>|null $loan */
/** @var list<array<string, mixed>> $hits */
/** @var string $q */
/** @var list<array{id: string, display_name: string, role: string}> $teachers */
/** @var string $defaultDue */
/** @var string $selectedBorrower */

$scanAction = App::url('loans/desk/resolve');
$scanSubmitLabel = '확인';
$scanHintText = '카메라로 찍거나 관리번호를 입력하면 바로 빌려주기·받아주기로 이어집니다.';
?>
<p class="muted"><a href="<?= Support::e(App::url('home')) ?>">← 홈</a></p>
<h1>대여 데스크</h1>
<p class="muted">스캔하거나 검색한 뒤, 교사만 고르고 빌려주거나 받아주세요.</p>

<?php require App::root() . '/templates/partials/scan_input.php'; ?>

<form method="get" action="<?= Support::e(App::url('loans/desk')) ?>" class="card" style="margin-top:1rem">
  <input type="hidden" name="r" value="loans/desk">
  <div class="field" style="margin:0">
    <label for="desk-search">이름 · 관리번호로 찾기</label>
    <input id="desk-search" type="search" name="q" value="<?= Support::e($q) ?>" placeholder="예: 오실로, 전장-2024" autocomplete="off">
  </div>
  <button class="btn btn-ghost" style="margin-top:0.75rem" type="submit">검색</button>
</form>

<?php if ($q !== '' && !$asset): ?>
  <div class="card">
    <h2 class="section-title" style="margin-top:0">검색 결과 (<?= count($hits) ?>)</h2>
    <?php foreach ($hits as $hit): ?>
      <a class="list-row" href="<?= Support::e(App::url('loans/desk', ['id' => $hit['id']])) ?>">
        <div>
          <div class="title"><?= Support::e((string) $hit['name']) ?></div>
          <div class="meta">
            <?= Support::e((string) ($hit['management_number'] ?? '')) ?>
            <?= !empty($hit['location_name']) ? ' · ' . Support::e((string) $hit['location_name']) : '' ?>
          </div>
        </div>
        <span class="badge <?= Support::e((string) ($hit['status'] ?? '')) ?>"><?= Support::e(Support::statusLabel((string) ($hit['status'] ?? ''))) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$hits): ?><p class="muted">장비를 찾지 못했습니다. 코드를 다시 찍거나 검색어를 바꿔 보세요.</p><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($asset): ?>
  <?php
    $status = (string) ($asset['status'] ?? '');
    $canLend = $status === 'available' && Auth::canLoan($user);
    $canTake = is_array($loan) && Auth::canReturn($user, $loan);
  ?>
  <div class="card">
    <h2 class="section-title" style="margin-top:0"><?= Support::e((string) $asset['name']) ?></h2>
    <p class="muted">
      <?= Support::e((string) ($asset['management_number'] ?? '')) ?>
      · <span class="badge <?= Support::e($status) ?>"><?= Support::e(Support::statusLabel($status)) ?></span>
      <?= !empty($asset['location_name']) ? ' · ' . Support::e((string) $asset['location_name']) : '' ?>
    </p>

    <?php if ($canLend): ?>
      <form method="post" action="<?= Support::e(App::url('assets/loan')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="asset_id" value="<?= Support::e((string) $asset['id']) ?>">
        <input type="hidden" name="return_to" value="desk">
        <div class="field">
          <label for="desk-borrower">빌리는 교사</label>
          <select id="desk-borrower" name="borrower_user_id" required>
            <?php foreach ($teachers as $teacher): ?>
              <option value="<?= Support::e($teacher['id']) ?>" <?= $teacher['id'] === $selectedBorrower ? 'selected' : '' ?>>
                <?= Support::e($teacher['display_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="desk-due">반납 예정</label>
          <input id="desk-due" type="datetime-local" name="due_at" value="<?= Support::e($defaultDue) ?>" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">빌려주기</button>
      </form>
    <?php elseif ($canTake): ?>
      <p>
        <strong><?= Support::e((string) ($loan['borrower_name'] ?? '')) ?></strong>
        <span class="badge <?= Support::e((string) ($loan['status'] ?? '')) ?>"><?= Support::e(Support::statusLabel((string) ($loan['status'] ?? ''))) ?></span>
      </p>
      <p class="muted">예정 <?= Support::e(Support::formatWhen(isset($loan['due_at']) ? (string) $loan['due_at'] : null)) ?></p>
      <form method="post" action="<?= Support::e(App::url('loans/return')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="loan_id" value="<?= Support::e((string) $loan['id']) ?>">
        <input type="hidden" name="return_to" value="desk">
        <button class="btn btn-ink btn-block" type="submit">받아주기</button>
      </form>
    <?php elseif ($status === 'on_loan'): ?>
      <p class="muted">진행 중 대여를 찾지 못해 받아줄 수 없습니다. 장비 상세에서 확인하세요.</p>
    <?php else: ?>
      <p class="muted">보관중이 아니라 빌려줄 수 없습니다. <?= Support::e(Support::statusLabel($status)) ?> 상태입니다.</p>
    <?php endif; ?>

    <p class="muted" style="margin-top:0.75rem">
      <a href="<?= Support::e(App::url('assets/show', ['id' => $asset['id']])) ?>">장비 상세 보기</a>
    </p>
  </div>
<?php endif; ?>
