<?php

use Inni\App;
use Inni\Support;

/** @var string $content */
/** @var array $flash */
/** @var ?array $user */
/** @var string $app_name */
/** @var string $school_name */
/** @var string $current_route */

$tabs = [
    ['home', '홈'],
    ['search', '찾기'],
    ['scan', '스캔'],
    ['rooms', '실'],
    ['more', '더보기'],
];

$tabActive = static function (string $tab, string $current): bool {
    if ($tab === 'home') {
        return $current === 'home' || $current === '';
    }
    if ($tab === 'more') {
        return $current === 'more' || str_starts_with($current, 'more/')
            || $current === 'items' || $current === 'assets' || $current === 'materials'
            || $current === 'loans' || str_starts_with($current, 'loans/')
            || $current === 'reports' || str_starts_with($current, 'reports/')
            || $current === 'inventory' || str_starts_with($current, 'inventory/')
            || $current === 'catalog/csv' || str_starts_with($current, 'catalog/csv')
            || $current === 'settings' || str_starts_with($current, 'settings/');
    }
    return $current === $tab || str_starts_with($current, $tab . '/');
};
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= Support::e($app_name) ?> · <?= Support::e($school_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+KR:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= Support::e(App::baseUrl()) ?>/assets/css/app.css">
</head>
<body>
<div class="cutting-mat">
  <header class="shell-header no-print">
    <div class="shell-header-inner">
      <a class="brand" href="<?= Support::e(App::url('home')) ?>">inni <span><?= Support::e($school_name) ?></span></a>
      <div class="muted"><?= Support::e($user['display_name'] ?? '') ?></div>
    </div>
  </header>

  <div class="layout">
    <nav class="side-nav no-print">
      <?php foreach ($tabs as [$r, $label]): ?>
        <a class="<?= $tabActive($r, $current_route) ? 'active' : '' ?>"
           href="<?= Support::e(App::url($r)) ?>"><?= Support::e($label) ?></a>
      <?php endforeach; ?>
      <a class="ghost" href="<?= Support::e(App::url('items/new')) ?>">+ 빠른 등록</a>
    </nav>

    <main>
      <?php foreach ($flash as $f): ?>
        <div class="flash <?= Support::e($f['type']) ?>"><?= Support::e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>

  <nav class="bottom-nav no-print">
    <?php foreach ($tabs as [$r, $label]): ?>
      <?php $active = $tabActive($r, $current_route); ?>
      <?php if ($r === 'scan'): ?>
        <a class="scan-fab <?= $active ? 'active' : '' ?>" href="<?= Support::e(App::url('scan')) ?>"><strong>스캔</strong></a>
      <?php else: ?>
        <a class="<?= $active ? 'active' : '' ?>" href="<?= Support::e(App::url($r)) ?>"><?= Support::e($label) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
</div>
<script src="<?= Support::e(App::baseUrl()) ?>/assets/js/app.js"></script>
</body>
</html>
