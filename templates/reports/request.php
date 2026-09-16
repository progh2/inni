<?php

use Inni\App;
use Inni\Support;

/** @var string $q */
/** @var list<array<string, mixed>> $hits */

$scanAction = App::url('reports/request/resolve');
$scanSubmitLabel = '이 장비로 수리 요청';
$scanHintText = 'QR을 찍으면 해당 장비의 수리 요청으로 바로 갑니다.';
$repairHref = static function (string $id): string {
    return App::url('assets/show', ['id' => $id, 'focus' => 'repair']) . '#repair';
};
?>
<p class="muted"><a href="<?= Support::e(App::url('home')) ?>">← 홈</a></p>
<h1>수리 요청</h1>
<p class="muted">고장난 장비를 찾아 증상을 남기세요. 스캔하거나 이름·관리번호로 고르면 됩니다.</p>

<?php require App::root() . '/templates/partials/scan_input.php'; ?>

<form method="get" action="<?= Support::e(App::url('reports/request')) ?>" class="card">
  <input type="hidden" name="r" value="reports/request">
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
      <a class="list-row" href="<?= Support::e($repairHref((string) $hit['id'])) ?>">
        <div>
          <div class="title"><?= Support::e((string) $hit['name']) ?></div>
          <div class="meta"><?= Support::e((string) $hit['management_number']) ?> · <?= Support::e((string) ($hit['location_name'] ?? '')) ?></div>
        </div>
        <span class="badge <?= Support::e((string) $hit['status']) ?>"><?= Support::e(Support::statusLabel((string) $hit['status'])) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$hits): ?><p class="muted">결과 없음. 스캔하거나 다른 이름으로 찾아 보세요.</p><?php endif; ?>
  </div>
<?php endif; ?>
