<?php

use Inni\App;

$scanAction = App::url('scan/resolve');
$scanSubmitLabel = '열기';
?>
<h1>스캔</h1>
<p class="muted">QR/바코드를 찍거나 관리번호를 직접 입력하세요.</p>
<?php require App::root() . '/templates/partials/scan_input.php'; ?>
