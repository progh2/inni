<?php

use Inni\App;
use Inni\ReportCost;
use Inni\Support;

/** @var array $user */
/** @var array{year: ?int, month: ?int} $filters */
/** @var int $year */
/** @var ?int $month */
/** @var array{year: ?int, month: ?int, total_amount: float, count: int} $summary */
/** @var list<array{year: int, month: int, label: string, total_amount: float, count: int}> $months */
/** @var list<array{year: int, total_amount: float, count: int}> $years */
/** @var list<array<string, mixed>> $cases */
?>
<p class="muted">
  <a href="<?= Support::e(App::url('reports')) ?>">← 수리 대기</a>
</p>
<h1>수리비 합계</h1>
<p class="muted">기록된 수리비의 월·연 합계입니다. 에듀파인·감가상각은 없습니다.</p>

<div class="stats" style="margin:1rem 0">
  <div class="stat">
    <b><?= Support::e(ReportCost::formatAmount((float) $summary['total_amount'])) ?></b>
    <?= $month !== null ? (int) $month . '월' : (int) $year . '년' ?>
  </div>
  <div class="stat">
    <b><?= (int) $summary['count'] ?></b>건
  </div>
  <div class="stat">
    <b><?= (int) $year ?></b>연도
  </div>
</div>

<form method="get" action="<?= Support::e(App::url('reports/costs')) ?>" class="card">
  <input type="hidden" name="r" value="reports/costs">
  <div class="grid-2">
    <div class="field">
      <label for="cost-year">연도</label>
      <input id="cost-year" name="year" type="number" inputmode="numeric" min="1900" max="2100" step="1" value="<?= Support::e((string) $year) ?>">
    </div>
    <div class="field">
      <label for="cost-month">월</label>
      <select id="cost-month" name="month">
        <option value="">전체</option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= $m ?>월</option>
        <?php endfor; ?>
      </select>
    </div>
  </div>
  <div class="actions">
    <button class="btn btn-primary" type="submit">걸러보기</button>
    <a class="btn btn-ghost" href="<?= Support::e(App::url('reports/costs')) ?>">올해</a>
  </div>
</form>

<?php if ($years): ?>
<div class="actions" style="margin:1rem 0">
  <?php foreach ($years as $row): ?>
    <?php $active = $year === (int) $row['year'] && $month === null; ?>
    <a class="btn <?= $active ? 'btn-ink' : 'btn-ghost' ?>" href="<?= Support::e(App::url('reports/costs', ['year' => (string) $row['year']])) ?>">
      <?= (int) $row['year'] ?>년 <?= Support::e(ReportCost::formatAmount((float) $row['total_amount'])) ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2 class="section-title" style="margin-top:0"><?= (int) $year ?>년 월별</h2>
  <div class="report-table-wrap">
    <table class="report-table">
      <thead>
        <tr>
          <th>월</th>
          <th class="num">건수</th>
          <th class="num">합계</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($months as $row): ?>
          <tr>
            <td>
              <a href="<?= Support::e(App::url('reports/costs', ['year' => (string) $year, 'month' => (string) $row['month']])) ?>">
                <?= Support::e($row['label']) ?>
              </a>
            </td>
            <td class="num"><?= (int) $row['count'] ?></td>
            <td class="num"><?= Support::e(ReportCost::formatAmount((float) $row['total_amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2 class="section-title" style="margin-top:0">
    기록
    (<?= count($cases) ?>)
  </h2>
  <?php foreach ($cases as $r): ?>
    <div class="list-row">
      <div>
        <a class="title" href="<?= Support::e(App::url('reports/show', ['id' => $r['id']])) ?>">
          <?= Support::e((string) $r['title']) ?>
        </a>
        <div class="meta">
          <?= Support::e((string) ($r['asset_name'] ?? '장비')) ?>
          <?= !empty($r['management_number']) ? ' · ' . Support::e((string) $r['management_number']) : '' ?>
          <?= !empty($r['cost_vendor']) ? ' · ' . Support::e((string) $r['cost_vendor']) : '' ?>
          <?= !empty($r['cost_budget_line']) ? ' · ' . Support::e((string) $r['cost_budget_line']) : '' ?>
          <?= !empty($r['cost_at']) ? ' · ' . Support::e((string) $r['cost_at']) : '' ?>
        </div>
      </div>
      <div class="actions">
        <span class="badge"><?= Support::e(ReportCost::formatAmount(isset($r['cost_amount']) ? (float) $r['cost_amount'] : null)) ?></span>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$cases): ?>
    <p class="muted">이 기간에 기록된 수리비가 없습니다.</p>
  <?php endif; ?>
</div>
