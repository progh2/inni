<?php

use Inni\App;
use Inni\Support;

/** @var string $content */
/** @var array $flash */
/** @var string $app_name */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= Support::e($app_name) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+KR:wght@400;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= Support::e(App::baseUrl()) ?>/assets/css/app.css">
</head>
<body class="cutting-mat">
  <?php foreach ($flash as $f): ?>
    <div class="flash <?= Support::e($f['type']) ?>" style="margin:1rem"><?= Support::e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</body>
</html>
