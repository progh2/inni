<?php

use Inni\App;
use Inni\Auth;
use Inni\Support;
?>
<h1>더보기</h1>
<div class="card" style="margin-top:1rem">
  <a class="list-row" href="<?= Support::e(App::url('items')) ?>">
    <div class="title">재료·품목 목록</div>
  </a>
  <a class="list-row" href="<?= Support::e(App::url('assets')) ?>">
    <div class="title">기자재 현황</div>
  </a>
  <a class="list-row" href="<?= Support::e(App::url('materials')) ?>">
    <div class="title">실험실습재료 현황</div>
  </a>
  <a class="list-row" href="<?= Support::e(App::url('items/new')) ?>">
    <div class="title">빠른 등록</div>
  </a>
  <?php if (Auth::canLoan($user)): ?>
    <a class="list-row" href="<?= Support::e(App::url('loans/desk')) ?>">
      <div>
        <div class="title">대여 데스크</div>
        <div class="meta">스캔·검색으로 빌려주기·받아주기</div>
      </div>
    </a>
    <a class="list-row" href="<?= Support::e(App::url('loans/mine')) ?>">
      <div class="title">내 대여함</div>
    </a>
  <?php endif; ?>
  <?php if (Auth::canWrite($user)): ?>
    <a class="list-row" href="<?= Support::e(App::url('reports')) ?>">
      <div class="title">수리 대기</div>
    </a>
  <?php endif; ?>
  <a class="list-row" href="<?= Support::e(App::url('loans')) ?>">
    <div class="title">대여 현황</div>
  </a>
  <a class="list-row" href="<?= Support::e(App::url('labels')) ?>">
    <div class="title">라벨 인쇄</div>
  </a>
  <?php if (Auth::canInventory($user)): ?>
    <a class="list-row" href="<?= Support::e(App::url('inventory')) ?>">
      <div class="title">실사</div>
    </a>
    <a class="list-row" href="<?= Support::e(App::url('inventory/report')) ?>">
      <div>
        <div class="title">실사 리포트</div>
        <div class="meta">사업예산·구입년도로 차이 목록·CSV</div>
      </div>
    </a>
  <?php endif; ?>
  <?php if (Auth::canWrite($user)): ?>
    <a class="list-row" href="<?= Support::e(App::url('catalog/csv')) ?>">
      <div class="title">품목 CSV</div>
    </a>
  <?php endif; ?>
  <?php if (Auth::canConfigureAlerts($user)): ?>
    <a class="list-row" href="<?= Support::e(App::url('settings')) ?>">
      <div class="title">학교 설정</div>
    </a>
  <?php endif; ?>
  <?php if (!empty($aiReady)): ?>
    <a class="list-row" href="<?= Support::e(App::url('settings')) ?>#ai">
      <div>
        <div class="title">AI 도우미</div>
        <div class="meta">제안 전용 · 재고는 바꾸지 않습니다</div>
      </div>
    </a>
  <?php endif; ?>
  <?php if (Auth::isOwner($user)): ?>
    <a class="list-row" href="<?= Support::e(App::url('settings/users')) ?>">
      <div class="title">사용자 승인</div>
    </a>
  <?php endif; ?>
  <a class="list-row" href="<?= Support::e(App::url('logout')) ?>">
    <div class="title">로그아웃</div>
  </a>
</div>
<p class="muted" style="margin-top:1rem">사진·DB는 서버 로컬(`public/uploads`, `data/inni.sqlite`)에 저장됩니다.</p>
