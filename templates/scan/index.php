<?php

use Inni\App;
use Inni\Auth;
use Inni\Support;

$scanAction = App::url('scan/resolve');
$scanSubmitLabel = '열기';
?>
<h1>스캔</h1>
<p class="muted">QR/바코드를 찍거나 관리번호를 직접 입력하세요.</p>
<?php if (Auth::canLoan($user ?? null)): ?>
  <p class="muted"><a href="<?= Support::e(App::url('loans/desk')) ?>">대여 데스크에서 빌려주기·받아주기</a></p>
<?php endif; ?>
<?php require App::root() . '/templates/partials/scan_input.php'; ?>
