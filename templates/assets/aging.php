<?php

use Inni\App;
use Inni\AssetAging;
use Inni\AssetLife;
use Inni\Budget;
use Inni\Support;

/** @var list<array<string, mixed>> $assets */
/** @var array{band: ?string} $filters */
/** @var array{due: int, over: int, watch: int} $summary */

$filterQuery = static function (array $extra = []) use ($filters): array {
    $merged = $filters;
    foreach ($extra as $key => $value) {
        $merged[$key] = $value;
    }
    return AssetAging::query($merged);
};
?>
<p class="muted"><a href="<?= Support::e(App::url('assets')) ?>">← 기자재 현황</a></p>
<h1>연한·노후 기자재</h1>
<p class="muted">도입일+내용연한으로 만료 예정일을 계산합니다. 임박은 <?= (int) AssetLife::IMMINENT_DAYS ?>일 이내, 초과는 만료일을 지난 장비입니다. 파기·보정은 없습니다.</p>

<div class="stats" style="margin:1rem 0">
  <a class="stat" href="<?= Support::e(App::url('assets/aging', $filterQuery(['band' => null]))) ?>">
    <b><?= (int) $summary['watch'] ?></b>임박+초과
  </a>
  <a class="stat" href="<?= Support::e(App::url('assets/aging', $filterQuery(['band' => AssetLife::BAND_DUE]))) ?>">
    <b><?= (int) $summary['due'] ?></b>임박
  </a>
  <a class="stat" href="<?= Support::e(App::url('assets/aging', $filterQuery(['band' => AssetLife::BAND_OVER]))) ?>">
    <b><?= (int) $summary['over'] ?></b>초과
  </a>
</div>

<form method="get" action="<?= Support::e(App::url('assets/aging')) ?>" class="card">
  <input type="hidden" name="r" value="assets/aging">
  <div class="field">
    <label for="aging-band">연한</label>
    <select id="aging-band" name="band">
      <option value="">임박+초과</option>
      <option value="<?= Support::e(AssetLife::BAND_DUE) ?>" <?= $filters['band'] === AssetLife::BAND_DUE ? 'selected' : '' ?>>임박만</option>
      <option value="<?= Support::e(AssetLife::BAND_OVER) ?>" <?= $filters['band'] === AssetLife::BAND_OVER ? 'selected' : '' ?>>초과만</option>
    </select>
  </div>
  <button class="btn btn-primary" type="submit">걸러보기</button>
</form>

<div class="card">
  <h2 class="section-title" style="margin-top:0">장비 (<?= count($assets) ?>)</h2>
  <?php foreach ($assets as $asset): ?>
    <?php
      $band = (string) ($asset['life_band'] ?? '');
      $rowLife = AssetLife::format(
          isset($asset['purchase_date']) ? (string) $asset['purchase_date'] : null,
          $asset['useful_life_years'] ?? null,
      );
      $remaining = AssetLife::formatRemaining(
          isset($asset['days_until']) ? (int) $asset['days_until'] : null
      );
      $rowBudget = Budget::format(
          isset($asset['budget_program']) ? (string) $asset['budget_program'] : null,
          $asset['budget_year'] ?? null,
      );
    ?>
    <a class="list-row<?= $band === AssetLife::BAND_OVER ? ' is-over' : '' ?>" href="<?= Support::e(App::url('assets/show', ['id' => $asset['id']])) ?>">
      <div>
        <div class="title"><?= Support::e((string) $asset['name']) ?></div>
        <div class="meta">
          <?= Support::e((string) $asset['management_number']) ?>
          · <?= Support::e((string) ($asset['location_name'] ?? '')) ?>
          <?php if ($rowLife !== ''): ?> · <?= Support::e($rowLife) ?><?php endif; ?>
          <?php if ($remaining !== ''): ?> · <?= Support::e($remaining) ?><?php endif; ?>
          <?php if ($rowBudget !== ''): ?> · <?= Support::e($rowBudget) ?><?php endif; ?>
        </div>
      </div>
      <span class="badge <?= Support::e($band) ?>"><?= Support::e(AssetLife::bandLabel($band)) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$assets): ?><p class="muted">조건에 맞는 장비가 없습니다. 도입일과 내용연한이 있는 장비만 표시됩니다.</p><?php endif; ?>
</div>
