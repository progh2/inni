<?php

use Inni\App;
use Inni\AssetLife;
use Inni\AssetLifeBoard;
use Inni\Support;

/** @var list<array<string, mixed>> $assets */
/** @var array{life: ?string} $filters */
/** @var array{total: int, imminent: int, exceeded: int} $summary */

$filterQuery = static function (array $extra = []) use ($filters): array {
    $life = array_key_exists('life', $extra) ? $extra['life'] : $filters['life'];
    $query = [];
    if (is_string($life) && $life !== '') {
        $query['life'] = $life;
    }
    return $query;
};
?>
<p class="muted"><a href="<?= Support::e(App::url('assets')) ?>">← 기자재 현황</a></p>
<h1>연한·노후 기자재</h1>
<p class="muted">도입일·내용연한(#39)으로 만료일을 계산합니다. 내용연한은 장비 상세에서 직접 입력하거나 조달청 제안(#40)을 수락해 채웁니다. 보정·파기는 이 화면에서 하지 않습니다.</p>

<div class="stats" style="margin:1rem 0">
  <a class="stat" href="<?= Support::e(App::url('assets/aging')) ?>">
    <b><?= (int) $summary['total'] ?></b>노후
  </a>
  <a class="stat" href="<?= Support::e(App::url('assets/aging', $filterQuery(['life' => AssetLife::BUCKET_IMMINENT]))) ?>">
    <b><?= (int) $summary['imminent'] ?></b>임박
  </a>
  <a class="stat" href="<?= Support::e(App::url('assets/aging', $filterQuery(['life' => AssetLife::BUCKET_EXCEEDED]))) ?>">
    <b><?= (int) $summary['exceeded'] ?></b>초과
  </a>
</div>

<form method="get" action="<?= Support::e(App::url('assets/aging')) ?>" class="card">
  <input type="hidden" name="r" value="assets/aging">
  <div class="field">
    <label for="aging-life">연한</label>
    <select id="aging-life" name="life">
      <option value="">임박·초과</option>
      <?php foreach (AssetLifeBoard::LIFE_FILTERS as $life): ?>
        <option value="<?= Support::e($life) ?>" <?= $filters['life'] === $life ? 'selected' : '' ?>>
          <?= Support::e(AssetLife::bucketLabel($life)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary" type="submit">걸러보기</button>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0">장비 (<?= count($assets) ?>)</h2>
  <?php foreach ($assets as $asset): ?>
    <?php
      $bucket = (string) ($asset['life_bucket'] ?? '');
      $rowClass = $bucket === AssetLife::BUCKET_EXCEEDED ? ' is-exceeded' : ($bucket === AssetLife::BUCKET_IMMINENT ? ' is-imminent' : '');
    ?>
    <a class="list-row<?= $rowClass ?>" href="<?= Support::e(App::url('assets/show', ['id' => $asset['id']])) ?>">
      <div>
        <div class="title"><?= Support::e((string) $asset['name']) ?></div>
        <div class="meta">
          <?= Support::e((string) $asset['management_number']) ?>
          · <?= Support::e((string) ($asset['location_name'] ?? '')) ?>
          <?php if (($asset['life_label'] ?? '') !== ''): ?>
            · <?= Support::e((string) $asset['life_label']) ?>
          <?php endif; ?>
          <?php if (($asset['remaining_label'] ?? '') !== ''): ?>
            · <?= Support::e((string) $asset['remaining_label']) ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($bucket !== ''): ?>
        <span class="badge <?= Support::e($bucket) ?>"><?= Support::e(AssetLife::bucketLabel($bucket)) ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if (!$assets): ?>
    <p class="muted">조건에 맞는 연한 임박·초과 장비가 없습니다. 도입일과 내용연한은 장비 상세에서 입력하세요.</p>
  <?php endif; ?>
</div>
