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
check(!str_contains($more, "App::url('search')"), 'More must not replace the search tab');

check(preg_match("/\\\$tabs = \\[\\s*\\['home', '홈'\\],\\s*\\['search', '찾기'\\],\\s*\\['scan', '스캔'\\],\\s*\\['rooms', '실'\\],\\s*\\['more', '더보기'\\],\\s*\\];/", $layout) === 1, 'Bottom nav stays 홈/찾기/스캔/실/더보기');
check(substr_count($layout, "['home', '홈']") === 1 && substr_count($layout, "['more', '더보기']") === 1, 'Tab labels stay a single 5-tab set');
check(str_contains($layout, "\$current === 'items' || \$current === 'assets'"), 'List/status screens keep the 더보기 tab active');
check(!str_contains($layout, "str_starts_with(\$current, 'items/')"), 'Item detail/new must not steal the 더보기 tab');
check(!str_contains($layout, "str_starts_with(\$current, 'assets/')"), 'Asset detail must not steal the 더보기 tab');

check(str_contains($search, '<h1>찾기</h1>'), 'Search tab keeps its heading');
check(str_contains($search, 'name="q"'), 'Search tab keeps the query field');
check(str_contains($search, '검색어를 입력하거나 스캔 탭을 사용하세요.'), 'Empty search still asks for a query');
check(str_contains($searchCtl, "if (\$q !== '')"), 'Search results still require a query');

check(str_contains($router, "'items' => [ItemController::class, 'index']"), 'items GET route is registered');
check(str_contains($router, "'assets' => [AssetController::class, 'index']"), 'assets GET route is registered');

check(str_contains($itemCtl, 'Catalog::list'), 'ItemController index keeps the #29 catalog list');
check(str_contains($itemsTpl, '<h1>품목 목록</h1>'), 'items/index.php keeps the #29 list title');
check(str_contains($itemsTpl, 'name="type"') && str_contains($itemsTpl, 'name="low_stock"'), 'items/index.php keeps type and low-stock filters');
check(!str_contains($itemsTpl, '타입·재고 필터는 곧 제공됩니다'), 'items/index.php must not be reverted to the #31 stub');

check(str_contains($assetCtl, 'function index'), 'AssetController has a GET index stub');
check(str_contains($assetCtl, "View::render('assets/index')"), 'Asset stub renders assets/index');
check(str_contains($assetsTpl, '<h1>기자재 현황</h1>'), 'Asset stub has the status title');
check(str_contains($assetsTpl, 'class="muted"'), 'Asset stub has a one-line notice');
check(!preg_match('/<(form|table|select)\\b/', $assetsTpl), 'Asset stub must not ship board/filter UI');

echo "PASS: {$checks} more-entry checks\n";
