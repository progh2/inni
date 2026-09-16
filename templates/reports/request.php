<?php

use Inni\App;
use Inni\Support;

/** @var string $q */
/** @var list<array<string, mixed>> $hits */
/** @var list<array<string, mixed>> $browse */

$scanAction = App::url('reports/request/resolve');
$scanSubmitLabel = '이 장비로 수리 요청';
$scanHintText = 'QR·관리번호만 여기 넣습니다. 이름은 위에서 찾으세요.';
$repairHref = static function (string $id): string {
    return App::url('assets/show', ['id' => $id, 'focus' => 'repair']) . '#repair';
};
$rows = $q !== '' ? $hits : $browse;
$listTitle = $q !== '' ? '검색 결과 (' . count($hits) . ')' : '장비 고르기';
?>
<p class="muted"><a href="<?= Support::e(App::url('home')) ?>">← 홈</a></p>
<h1>수리 요청</h1>
<p class="muted">고장난 장비를 찾아 증상을 남기세요. 이름·관리번호로 고르거나, 아래에서 QR을 찍으면 됩니다.</p>

<form method="get" action="<?= Support::e(App::url('reports/request')) ?>" class="card">
  <input type="hidden" name="r" value="reports/request">
  <div class="field" style="margin:0">
    <label>이름 · 관리번호로 찾기</label>
    <input type="search" name="q" value="<?= Support::e($q) ?>" placeholder="예: 오실로, 전장-2024" autocomplete="off">
  </div>
  <button class="btn btn-primary btn-block" style="margin-top:0.75rem" type="submit">검색</button>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0"><?= Support::e($listTitle) ?></h2>
  <?php foreach ($rows as $hit): ?>
    <a class="list-row" href="<?= Support::e($repairHref((string) $hit['id'])) ?>">
      <div>
        <div class="title"><?= Support::e((string) $hit['name']) ?></div>
        <div class="meta"><?= Support::e((string) $hit['management_number']) ?> · <?= Support::e((string) ($hit['location_name'] ?? '')) ?></div>
      </div>
      <span class="badge <?= Support::e((string) $hit['status']) ?>"><?= Support::e(Support::statusLabel((string) $hit['status'])) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <p class="muted"><?= $q !== '' ? '결과 없음. 다른 이름으로 찾거나 QR을 찍어 보세요.' : '고를 수 있는 장비가 없습니다.' ?></p>
  <?php endif; ?>
</div>

<?php require App::root() . '/templates/partials/scan_input.php'; ?>
