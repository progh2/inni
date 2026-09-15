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

$more = (string) file_get_contents($root . '/templates/more/index.php');
$search = (string) file_get_contents($root . '/templates/search/index.php');
$layout = (string) file_get_contents($root . '/templates/layouts/app.php');
$router = (string) file_get_contents($root . '/app/Router.php');
$itemCtl = (string) file_get_contents($root . '/app/Controllers/ItemController.php');
$assetCtl = (string) file_get_contents($root . '/app/Controllers/AssetController.php');
$itemsTpl = (string) file_get_contents($root . '/templates/items/index.php');
$assetsTpl = (string) file_get_contents($root . '/templates/assets/index.php');
$searchCtl = (string) file_get_contents($root . '/app/Controllers/SearchController.php');

check(str_contains($more, '재료·품목 목록'), 'More menu must expose 재료·품목 목록');
check(str_contains($more, "App::url('items')"), '재료·품목 목록 must link to items');
check(substr_count($more, "App::url('items')") === 1, 'More must not duplicate the items browse link');
check(str_contains($more, '기자재 현황'), 'More menu must expose 기자재 현황');
check(str_contains($more, "App::url('assets')"), '기자재 현황 must link to assets');
check(substr_count($more, "App::url('assets')") === 1, 'More must not duplicate the assets board link');
check(str_contains($more, '연한·노후 기자재'), 'More menu must expose 연한·노후 기자재');
check(str_contains($more, "App::url('assets/aging')"), '연한·노후 기자재 must link to assets/aging');
check(substr_count($more, "App::url('assets/aging')") === 1, 'More must not duplicate the aging board link');
check(str_contains($more, '실험실습재료 현황'), 'More menu must expose 실험실습재료 현황');
check(str_contains($more, "App::url('materials')"), '실험실습재료 현황 must link to materials');
check(substr_count($more, "App::url('materials')") === 1, 'More must not duplicate the materials board link');
check(!str_contains($more, "App::url('search')"), 'More must not replace the search tab');

check(preg_match("/\\\$tabs = \\[\\s*\\['home', '홈'\\],\\s*\\['search', '찾기'\\],\\s*\\['scan', '스캔'\\],\\s*\\['rooms', '실'\\],\\s*\\['more', '더보기'\\],\\s*\\];/", $layout) === 1, 'Bottom nav stays 홈/찾기/스캔/실/더보기');
check(substr_count($layout, "['home', '홈']") === 1 && substr_count($layout, "['more', '더보기']") === 1, 'Tab labels stay a single 5-tab set');
check(str_contains($layout, "\$current === 'items' || \$current === 'assets' || \$current === 'materials'"), 'List/status screens keep the 더보기 tab active');
check(str_contains($layout, "\$current === 'assets/aging'"), 'Aging board keeps the 더보기 tab active');
check(str_contains($layout, "\$current === 'loans' || str_starts_with(\$current, 'loans/')"), 'Loan inbox/list keep the 더보기 tab active');
check(str_contains($layout, "\$current === 'reports' || str_starts_with(\$current, 'reports/')"), 'Repair queue keeps the 더보기 tab active');
check(str_contains($more, '내 대여함'), 'More menu must expose 내 대여함');
check(str_contains($more, "App::url('loans/mine')"), '내 대여함 must link to loans/mine');
check(str_contains($more, 'Auth::canLoan($user)'), '내 대여함 is gated on canLoan');
check(substr_count($more, "App::url('loans/mine')") === 1, 'More must not duplicate the inbox link');
check(str_contains($more, '대여 데스크'), 'More menu must expose 대여 데스크');
check(str_contains($more, "App::url('loans/desk')"), '대여 데스크 must link to loans/desk');
check(substr_count($more, "App::url('loans/desk')") === 1, 'More must not duplicate the desk link');
check(str_contains($more, '수리 대기'), 'More menu must expose 수리 대기');
check(str_contains($more, "App::url('reports')"), '수리 대기 must link to reports');
check(str_contains($more, 'Auth::canWrite($user)'), '수리 대기 is gated on canWrite');
check(substr_count($more, "App::url('reports')") === 1, 'More must not duplicate the repair queue link');
check(str_contains($more, '사업예산 실사'), 'More menu must expose 사업예산 실사');
check(str_contains($more, "App::url('inventory/report')"), '사업예산 실사 must link to inventory/report');
check(str_contains($more, 'Auth::canInventory($user)'), '사업예산 실사 is gated on canInventory');
check(substr_count($more, "App::url('inventory/report')") === 1, 'More must not duplicate the inventory report link');
check(!str_contains($layout, "str_starts_with(\$current, 'items/')"), 'Item detail/new must not steal the 더보기 tab');
check(!str_contains($layout, "str_starts_with(\$current, 'assets/')"), 'Asset detail must not steal the 더보기 tab');

check(str_contains($search, '<h1>찾기</h1>'), 'Search tab keeps its heading');
check(str_contains($search, 'name="q"'), 'Search tab keeps the query field');
check(str_contains($search, '검색어를 입력하거나 스캔 탭을 사용하세요.'), 'Empty search still asks for a query');
check(str_contains($searchCtl, "if (\$q !== '')"), 'Search results still require a query');

check(str_contains($router, "'items' => [ItemController::class, 'index']"), 'items GET route is registered');
check(str_contains($router, "'assets' => [AssetController::class, 'index']"), 'assets GET route is registered');
check(str_contains($router, "'assets/aging' => [AssetController::class, 'aging']"), 'assets/aging GET route is registered');
check(str_contains($router, "'materials' => [MaterialController::class, 'index']"), 'materials GET route is registered');
check(str_contains($router, "'reports' => [ReportController::class, 'index']"), 'reports GET route is registered');
check(str_contains($router, "'inventory/report' => [InventoryController::class, 'report']"), 'inventory/report GET route is registered');

check(str_contains($itemCtl, 'Catalog::list'), 'ItemController index keeps the #29 catalog list');
check(str_contains($itemsTpl, '<h1>품목 목록</h1>'), 'items/index.php keeps the #29 list title');
check(str_contains($itemsTpl, 'name="type"') && str_contains($itemsTpl, 'name="low_stock"'), 'items/index.php keeps type and low-stock filters');
check(!str_contains($itemsTpl, '타입·재고 필터는 곧 제공됩니다'), 'items/index.php must not be reverted to the #31 stub');

check(str_contains($assetCtl, 'function index'), 'AssetController has a GET index');
check(str_contains($assetCtl, 'AssetBoard::list'), 'Asset index is the #30 board, not the #31 stub');
check(str_contains($assetCtl, "View::render('assets/index'"), 'Board renders assets/index');
check(str_contains($assetsTpl, '<h1>기자재 현황</h1>'), 'assets/index.php keeps the status title');
check(!str_contains($assetsTpl, '상태·실·연체 필터는 곧 제공됩니다'), 'assets/index.php must not be reverted to the #31 stub');
check(str_contains($assetsTpl, 'name="status"') && str_contains($assetsTpl, 'name="room"') && str_contains($assetsTpl, 'name="overdue"'), 'board replaced the stub with status/room/overdue filters');

echo "PASS: {$checks} more-entry checks\n";
