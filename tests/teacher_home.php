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

$home = (string) file_get_contents($root . '/templates/home/index.php');
$more = (string) file_get_contents($root . '/templates/more/index.php');
$request = (string) file_get_contents($root . '/templates/reports/request.php');
$desk = (string) file_get_contents($root . '/templates/desk/index.php');
$aging = (string) file_get_contents($root . '/templates/assets/aging.php');
$assetShow = (string) file_get_contents($root . '/templates/assets/show.php');
$itemCreate = (string) file_get_contents($root . '/templates/items/new.php');
$assets = (string) file_get_contents($root . '/templates/assets/index.php');
$layout = (string) file_get_contents($root . '/templates/layouts/app.php');
$router = (string) file_get_contents($root . '/app/Router.php');
$reportCtl = (string) file_get_contents($root . '/app/Controllers/ReportController.php');

check(str_contains($home, "App::url('reports/request')") && str_contains($home, '수리 요청'), 'home exposes 수리 요청');
check(str_contains($home, "App::url('loans/mine')") && str_contains($home, '내 대여함'), 'home exposes 내 대여함');
check(str_contains($home, 'Auth::canLoan($user)'), 'teacher home CTAs stay on canLoan');
check(str_contains($home, '고장난 장비를 찾아 증상을 남기세요'), 'home repair card explains the next step');
check(str_contains($home, '대여 데스크는 장비를 빌려주고 받아주는 창구'), 'home explains 대여 데스크');

check(str_contains($home, '>파기<') || str_contains($home, '파기</div>'), 'home names 파기 for managers');
check(str_contains($home, '장부 보정'), 'home names 장부 보정 for managers');
check(str_contains($home, '사업예산 실사'), 'home names 사업예산 실사 for managers');
check(str_contains($home, '수리비'), 'home names 수리비 for managers');
check(str_contains($home, 'Auth::canWrite($user)'), 'manager home card is write-gated');

check(str_contains($more, "App::url('reports/request')") && str_contains($more, '수리 요청'), 'more exposes 수리 요청');
check(str_contains($more, '파기') && str_contains($more, "App::url('assets/aging')"), 'more exposes 파기');
check(str_contains($more, '장부 보정') && str_contains($more, "App::url('inventory')"), 'more exposes 장부 보정');
check(str_contains($more, '사업예산 실사') && str_contains($more, "App::url('inventory/report')"), 'more exposes 사업예산 실사');
check(str_contains($more, '수리비') && str_contains($more, "App::url('reports/costs')"), 'more exposes 수리비');

check(str_contains($request, '<h1>수리 요청</h1>'), 'request page keeps the Korean title');
check(str_contains($request, "App::url('reports/request/resolve')"), 'request camera/code posts to request resolve');
check(str_contains($request, "App::url('assets/show'") && str_contains($request, '#repair'), 'request search lands on asset repair');
check(str_contains($request, 'name="q"'), 'request page has a search field');
check(!str_contains($request, 'new Vue') && !str_contains($request, 'createApp'), 'request page stays SSR');

check(str_contains($router, "'reports/request' => [ReportController::class, 'requestForm']"), 'request route is registered');
check(str_contains($router, "'reports/request/resolve' => [ReportController::class, 'requestResolve']"), 'request resolve route is registered');
check(str_contains($reportCtl, 'function requestForm') && str_contains($reportCtl, 'function requestResolve'), 'request actions exist');
check(str_contains($reportCtl, 'requireTeacherRequest') && str_contains($reportCtl, 'Auth::canLoan($user)'), 'request is gated on canLoan');
check(str_contains($reportCtl, 'Csrf::requirePost()'), 'request resolve is POST+CSRF');
check(str_contains($reportCtl, "focus' => 'repair'") || str_contains($reportCtl, "'focus' => 'repair'"), 'scan resolve focuses the repair form');

check(str_contains($assetShow, 'id="repair"'), 'asset detail has a repair anchor');
check(str_contains($assetShow, 'focus') && str_contains($assetShow, '수리 요청으로 이동'), 'asset detail jumps to repair when focused');
check(str_contains($assetShow, '이 장비를 쓸 수 있는 햇수'), 'asset detail explains 내용연한');

check(str_contains($desk, '<h1>대여 데스크</h1>'), 'desk keeps the Korean title');
check(str_contains($desk, '장비를 빌려주고 받아주는 창구'), 'desk explains the title');
check(str_contains($desk, '기본 빌리는 사람'), 'desk avoids 차용자');
check(!str_contains($desk, '차용자'), 'desk UI does not say 차용자');

check(str_contains($aging, '이 장비를 쓸 수 있는 햇수'), 'aging explains 내용연한');
check(str_contains($aging, '임박은 만료가 1년 안, 초과는 이미 지난'), 'aging explains 임박·초과');
check(str_contains($aging, '조달청 제안'), 'aging still mentions PPS suggest');
check(!preg_match('/#[0-9]{2,}/', $aging), 'aging UI has no raw GitHub issue numbers');
check(!preg_match('/#[0-9]{2,}/', $home), 'home UI has no raw GitHub issue numbers');
check(!preg_match('/#[0-9]{2,}/', $more), 'more UI has no raw GitHub issue numbers');
check(!preg_match('/#[0-9]{2,}/', $request), 'request UI has no raw GitHub issue numbers');
check(!preg_match('/#[0-9]{2,}/', $assets), 'assets board UI has no raw GitHub issue numbers');
check(!preg_match('/#[0-9]{2,}/', $assetShow), 'asset detail UI has no raw GitHub issue numbers');
check(!preg_match('/#[0-9]{2,}/', $itemCreate), 'create form UI has no raw GitHub issue numbers');

check(str_contains($itemCreate, '이 장비를 쓸 수 있는 햇수'), 'create form explains 내용연한');
check(str_contains($assets, '임박(1년 안)') && str_contains($assets, '초과(이미 지남)'), 'assets board explains 임박·초과');

check(str_contains($home, 'Auth::canWrite($user)') && str_contains($home, "App::url('items/new')"), 'home hides 빠른 등록 behind canWrite');
check(str_contains($more, 'Auth::canWrite($user)') && str_contains($more, "App::url('items/new')"), 'more hides 빠른 등록 behind canWrite');
check(str_contains($layout, 'Auth::canWrite($user ?? null)'), 'side nav hides 빠른 등록 behind canWrite');

echo "PASS: {$checks} teacher-home checks\n";
