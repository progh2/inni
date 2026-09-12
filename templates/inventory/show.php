<?php

use Inni\App;
use Inni\Csrf;
use Inni\Support;

$scanAction = App::url('inventory/confirm');
$scanSubmitLabel = '확인';
$scanHintText = '이 실의 장비·품목 QR을 찍거나 관리번호를 입력하세요.';
$total = count($lines);
?>
<p class="muted"><a href="<?= Support::e(App::url('rooms/show', ['id' => $check['location_id']])) ?>">← <?= Support::e($check['location_name']) ?></a></p>
<h1>실사 · <?= Support::e($check['location_name']) ?></h1>
<p class="muted">확인 <?= (int) $confirmed ?> / <?= (int) $total ?></p>

<?php require App::root() . '/templates/partials/scan_input.php'; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0">예상 목록</h2>
  <?php foreach ($lines as $line): ?>
    <?php $ok = ($line['confirmed_at'] ?? null) !== null; ?>
    <div class="list-row">
      <div>
        <div class="title"><?= Support::e($line['name']) ?></div>
        <div class="meta">
          <?= $line['kind'] === 'asset' ? '장비' : '품목' ?>
          <?= $line['code'] ? ' · ' . Support::e($line['code']) : '' ?>
          · <?= Support::e($line['location_name']) ?>
          <?php if ($line['kind'] === 'item'): ?>
            · <?= Support::e((string) $line['expected_qty']) ?> <?= Support::e((string) ($line['unit'] ?? '')) ?>
          <?php endif; ?>
        </div>
      </div>
      <span class="badge <?= $ok ? 'confirmed' : 'unchecked' ?>"><?= $ok ? '확인' : '미확인' ?></span>
    </div>
  <?php endforeach; ?>
  <?php if (!$lines): ?>
    <p class="muted">이 실에 등록된 장비·재고가 없습니다. 종료하면 미확인 0건입니다.</p>
  <?php endif; ?>
</div>

<form class="actions" method="post" action="<?= Support::e(App::url('inventory/finish')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="check_id" value="<?= Support::e($check['id']) ?>">
  <button class="btn btn-ink btn-block" type="submit">실사 종료 · 미확인 보기</button>
</form>
