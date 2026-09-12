<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;

function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$scan = (string) file_get_contents($root . '/templates/scan/index.php');
$labels = (string) file_get_contents($root . '/templates/labels/index.php');
$print = (string) file_get_contents($root . '/templates/labels/print.php');
$printLayout = (string) file_get_contents($root . '/templates/layouts/print.php');
$appCss = (string) file_get_contents($root . '/public/assets/css/app.css');
$appLayout = (string) file_get_contents($root . '/templates/layouts/app.php');

check(str_contains($scan, 'Csrf::field()'), 'Scan manual form must send CSRF');
check(str_contains($scan, 'csrf_token') || str_contains($scan, 'Csrf::FIELD_NAME'), 'Camera POST must include the CSRF field name');
check(str_contains($scan, 'isSecureContext'), 'Scan must refuse the camera off HTTPS/localhost');
check(str_contains($scan, '코드를 직접 입력하세요'), 'Camera failures must point at manual code entry');
check(str_contains($scan, "facingMode: 'environment'") || str_contains($scan, 'environment'), 'Rear camera should be preferred');
check(str_contains($scan, 'html5-qrcode'), 'Existing html5-qrcode CDN must stay');

check(str_contains($labels, 'Csrf::field()'), 'Label selection form must send CSRF');
check(str_contains($print, 'QRCode.toDataURL'), 'Label preview must draw QR in the browser');
check(str_contains($print, 'label[\'name\']') || str_contains($print, '$label[\'name\']'), 'Label must show the name');
check(str_contains($print, 'label[\'code\']') || str_contains($print, '$label[\'code\']'), 'Label must show the management number');

check(str_contains($printLayout, 'window.print()'), 'Print toolbar must call window.print');
check(str_contains($printLayout, '@media print') && str_contains($printLayout, 'display: none'), 'Print CSS must hide the toolbar');
check(str_contains($printLayout, 'page-break-inside: avoid'), 'Stickers must not split across pages');
check(str_contains($printLayout, 'dashed'), 'Cut guides should be dashed');
check(str_contains($printLayout, '70mm') && str_contains($printLayout, '32mm'), 'Stickers need a fixed label size');
check(str_contains($printLayout, 'qrcode@1.5.1'), 'Label print must use a CDN build that actually ships qrcode.min.js');

check(str_contains($appCss, '#qr-reader'), 'Camera viewport styles must live in app.css');
check(str_contains($appCss, 'max-height: min(52dvh, 22rem)'), 'Camera preview must stay inside the mobile viewport');
check(str_contains($appCss, '.scan-fab') && str_contains($appCss, 'flex: 0 0 3.4rem'), 'Scan FAB must not stretch on the tab bar');
check(str_contains($appCss, '@media print') && str_contains($appCss, '.bottom-nav'), 'Printing the app shell must hide the tab bar');

check(str_contains($appLayout, 'scan-fab'), 'Bottom nav keeps the scan FAB');
check(str_contains($appLayout, '$tabActive'), 'Search/scan/room tabs share one active-state helper');

echo "PASS: {$checks} scan/label checks\n";
