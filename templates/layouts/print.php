<?php

use Inni\App;
use Inni\Support;

/** @var string $content */
/** @var string $school */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>라벨 인쇄 · <?= Support::e($school) ?></title>
  <style>
    @page { size: A4; margin: 8mm; }
    * { box-sizing: border-box; }
    body {
      font-family: "IBM Plex Sans KR", "Apple SD Gothic Neo", sans-serif;
      margin: 1rem;
      color: #1a2a28;
    }
    .toolbar { margin-bottom: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .toolbar button, .toolbar a {
      font: inherit; cursor: pointer;
      border: 1px solid #1a2a28; background: #fff; color: #1a2a28;
      border-radius: 0.5rem; padding: 0.45rem 0.8rem; text-decoration: none;
    }
    .sheet {
      display: flex;
      flex-wrap: wrap;
      gap: 4mm;
      align-content: flex-start;
    }
    .label {
      width: 70mm;
      height: 32mm;
      border: 1px dashed #1a2a28;
      border-radius: 0;
      padding: 2.4mm 2.6mm;
      display: flex;
      gap: 2.6mm;
      align-items: center;
      overflow: hidden;
      break-inside: avoid;
      page-break-inside: avoid;
      -webkit-column-break-inside: avoid;
    }
    .label canvas,
    .label img {
      width: 24mm;
      height: 24mm;
      flex: 0 0 24mm;
      display: block;
    }
    .label .text { min-width: 0; flex: 1; }
    .name {
      font-weight: 700;
      font-size: 11pt;
      line-height: 1.2;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .code {
      font-size: 9pt;
      margin-top: 1mm;
      word-break: break-all;
      font-variant-numeric: tabular-nums;
    }
    .school { font-size: 8pt; margin-top: 1mm; opacity: 0.65; }
    .empty { color: #5a6a68; }
    @media print {
      .toolbar, .no-print { display: none !important; }
      body { margin: 0; }
      .sheet { gap: 3mm; }
      .label {
        break-inside: avoid;
        page-break-inside: avoid;
        -webkit-column-break-inside: avoid;
      }
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
</head>
<body>
  <div class="toolbar no-print">
    <button type="button" onclick="window.print()">인쇄</button>
    <a href="<?= Support::e(App::url('labels')) ?>">선택으로 돌아가기</a>
  </div>
  <?= $content ?>
</body>
</html>
