<?php

use Inni\Support;

/** @var string $content */
/** @var string $school */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>라벨 인쇄 · <?= Support::e($school) ?></title>
  <style>
    body { font-family: "IBM Plex Sans KR", sans-serif; margin: 1rem; color: #1a2a28; }
    .toolbar { margin-bottom: 1rem; }
    .sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .label {
      border: 1px solid #1a2a28; border-radius: 8px; padding: 10px;
      display: flex; gap: 10px; align-items: center; break-inside: avoid;
      min-height: 110px;
    }
    .label img { width: 84px; height: 84px; }
    .name { font-weight: 700; font-size: 14px; }
    .code { font-size: 12px; opacity: 0.75; margin-top: 4px; }
    .school { font-size: 11px; margin-top: 6px; opacity: 0.6; }
    @media print { .toolbar { display: none; } body { margin: 0; } }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
</head>
<body>
  <div class="toolbar">
    <button onclick="window.print()">인쇄</button>
  </div>
  <?= $content ?>
</body>
</html>
