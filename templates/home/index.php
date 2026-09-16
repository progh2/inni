<?php

use Inni\App;
use Inni\Auth;
use Inni\Support;
?>
<h1>홈</h1>
<p class="muted">고장 나면 수리 요청, 빌린 장비는 내 대여함에서 보세요.</p>
<?php if (!empty($telegramConnectNeeded) && ($lowStock || $activeLoans)): ?>
  <p class="flash error">알림 연결 필요. 재고 부족·연체 푸시는 설정에서 텔레그램 봇 토큰을 연결한 뒤에 보내집니다.</p>
<?php endif; ?>

<div class="stats" style="margin:1rem 0">
  <a class="stat" href="<?= Support::e(App::url('assets')) ?>"><b><?= (int) $assetCount ?></b>장비</a>
  <div class="stat"><b><?= (int) $roomCount ?></b>실</div>
  <?php if (Auth::canWrite($user)): ?>
    <a class="stat" href="<?= Support::e(App::url('reports')) ?>"><b><?= (int) $openReports ?></b>수리</a>
  <?php elseif (Auth::canLoan($user)): ?>
    <a class="stat" href="<?= Support::e(App::url('reports/request')) ?>"><b><?= (int) $openReports ?></b>수리</a>
  <?php else: ?>
    <div class="stat"><b><?= (int) $openReports ?></b>수리</div>
  <?php endif; ?>
</div>

<div class="actions" style="margin-bottom:1rem">
  <a class="btn btn-primary" href="<?= Support::e(App::url('scan')) ?>">스캔하기</a>
  <?php if (Auth::canLoan($user)): ?>
    <a class="btn btn-primary" href="<?= Support::e(App::url('reports/request')) ?>">수리 요청</a>
    <a class="btn btn-primary" href="<?= Support::e(App::url('loans/mine')) ?>">내 대여함</a>
    <a class="btn btn-ghost" href="<?= Support::e(App::url('loans/desk')) ?>">대여 데스크</a>
  <?php endif; ?>
  <a class="btn btn-ghost" href="<?= Support::e(App::url('items')) ?>">품목 목록</a>
  <?php if (Auth::canWrite($user)): ?>
    <a class="btn btn-ghost" href="<?= Support::e(App::url('items/new')) ?>">빠른 등록</a>
  <?php endif; ?>
  <a class="btn btn-ghost" href="<?= Support::e(App::url('assets')) ?>">기자재 현황</a>
  <a class="btn btn-ghost" href="<?= Support::e(App::url('materials')) ?>">실험실습재료</a>
  <?php if (Auth::canWrite($user)): ?>
    <a class="btn btn-ghost" href="<?= Support::e(App::url('reports')) ?>">수리 대기</a>
  <?php endif; ?>
  <a class="btn btn-ghost" href="<?= Support::e(App::url('loans')) ?>">대여 현황</a>
</div>
<?php if (Auth::canLoan($user)): ?>
  <p class="muted">대여 데스크는 장비를 빌려주고 받아주는 창구입니다.</p>
<?php endif; ?>

<?php if (Auth::canLoan($user)): ?>
<div class="card">
  <a class="list-row<?= !empty($myOverdueCount) ? ' is-overdue' : '' ?>" href="<?= Support::e(App::url('loans/mine')) ?>">
    <div>
      <div class="title">내 대여함</div>
      <div class="meta">
        <?php if (!$myLoans): ?>
          내가 빌린 진행 중 대여가 없습니다.
        <?php elseif (!empty($myOverdueCount)): ?>
          연체 <?= (int) $myOverdueCount ?>건 · 진행 <?= count($myLoans) ?>건
        <?php else: ?>
          진행 <?= count($myLoans) ?>건
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($myOverdueCount)): ?>
      <span class="badge overdue">연체</span>
    <?php else: ?>
      <span class="badge active">내 대여</span>
    <?php endif; ?>
  </a>
  <a class="list-row" href="<?= Support::e(App::url('reports/request')) ?>">
    <div>
      <div class="title">수리 요청</div>
      <div class="meta">고장난 장비를 찾아 증상을 남기세요.</div>
    </div>
    <span class="badge repair">수리</span>
  </a>
</div>
<?php endif; ?>

<?php if (Auth::canWrite($user)): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">담당 작업</h2>
  <a class="list-row" href="<?= Support::e(App::url('assets/aging')) ?>">
    <div>
      <div class="title">파기</div>
      <div class="meta">노후·파손 장비는 상세에서 폐기 처리합니다.</div>
    </div>
  </a>
  <a class="list-row" href="<?= Support::e(App::url('inventory')) ?>">
    <div>
      <div class="title">장부 보정</div>
      <div class="meta">종료된 실사의 미확인을 장부에 맞춥니다.</div>
    </div>
  </a>
  <a class="list-row" href="<?= Support::e(App::url('inventory/report')) ?>">
    <div>
      <div class="title">사업예산 실사</div>
      <div class="meta">장부 vs 실물 차이 · CSV</div>
    </div>
  </a>
  <a class="list-row" href="<?= Support::e(App::url('reports/costs')) ?>">
    <div>
      <div class="title">수리비</div>
      <div class="meta">월·연 수리비 · 업체·예산과목</div>
    </div>
  </a>
</div>
<?php endif; ?>

<?php if (!empty($activeCheck)): ?>
<div class="card">
  <a class="list-row" href="<?= Support::e(App::url('inventory/show')) ?>">
    <div>
      <div class="title">진행 중 실사 · <?= Support::e($activeCheck['location_name']) ?></div>
      <div class="meta">스캔으로 확인한 뒤 종료하면 미확인 목록이 나옵니다.</div>
    </div>
    <span class="badge active">실사</span>
  </a>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">진행 중 대여</h2>
  <?php if (!$activeLoans): ?>
    <p class="muted">진행 중인 대여가 없습니다.</p>
  <?php else: ?>
    <?php foreach ($activeLoans as $loan): ?>
      <a class="list-row" href="<?= Support::e(App::url('assets/show', ['id' => $loan['asset_id']])) ?>">
        <div>
          <div class="title"><?= Support::e($loan['asset_name'] ?? '품목') ?></div>
          <div class="meta"><?= Support::e($loan['borrower_name']) ?> · 예정 <?= Support::e(Support::formatWhen($loan['due_at'])) ?></div>
        </div>
        <span class="badge <?= Support::e($loan['status']) ?>"><?= Support::e(Support::statusLabel($loan['status'])) ?></span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($lowStock): ?>
<div class="card">
  <h2 class="section-title" style="margin-top:0">재고 부족</h2>
  <?php foreach ($lowStock as $row): ?>
    <a class="list-row is-low-stock" href="<?= Support::e(App::url('items/show', ['id' => $row['id']])) ?>">
      <div>
        <div class="title"><?= Support::e($row['name']) ?></div>
        <div class="meta"><?= Support::e((string) $row['qty']) ?> / 최소 <?= Support::e((string) $row['min_stock']) ?> <?= Support::e($row['unit']) ?></div>
      </div>
      <span class="badge overdue">부족</span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">최근 이력</h2>
  <?php foreach ($recent as $log): ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($log['summary']) ?></div>
        <div class="meta"><?= Support::e($log['actor_name']) ?> · <?= Support::e(Support::formatWhen($log['created_at'])) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
