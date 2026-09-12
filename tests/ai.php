<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Inni\Ai;
use Inni\Ai\OllamaProvider;
use Inni\Ai\OpenAiProvider;
use Inni\Ai\UnconfiguredProvider;
use Inni\Ai\UpstageProvider;
use Inni\AiProvider;
use Inni\AiSuggestion;
use Inni\App;
use Inni\Catalog;
use Inni\Inventory;
use Inni\Loan;
use Inni\Stock;

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

function memoryDb(): PDO
{
    global $root;
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($root . '/sql/schema.sql'));
    return $pdo;
}

function appAiConfig(array $ai): void
{
    $ref = new ReflectionClass(App::class);
    $prop = $ref->getProperty('config');
    $prop->setAccessible(true);
    $prop->setValue(null, [
        'app_name' => 'inni',
        'school_name' => 'ai-test',
        'timezone' => 'UTC',
        'demo_login' => false,
        'ai' => $ai,
    ]);
}

function stockQty(PDO $pdo, string $lotId = 'lot-1'): float
{
    $stmt = $pdo->prepare('SELECT quantity FROM stock_lots WHERE id = ?');
    $stmt->execute([$lotId]);
    return (float) $stmt->fetchColumn();
}

function seedStock(PDO $pdo): void
{
    $pdo->exec("INSERT INTO locations(id,name,kind,qr_code,created_at,updated_at) VALUES('room','실습실','room','LOC:room','t','t')");
    $pdo->exec("INSERT INTO catalog_items(id,name,type,unit,min_stock,qr_code,created_at,updated_at) VALUES('solder','납땜 실납','consumable','m',5,'CAT:solder','t','t')");
    $pdo->exec("INSERT INTO stock_lots(id,catalog_item_id,location_id,quantity,updated_at) VALUES('lot-1','solder','room',6,'t')");
    $pdo->exec("INSERT INTO users(id,email,display_name,role,status,created_at,updated_at) VALUES('owner','o@t','owner','owner','active','t','t')");
}

function snapshotWrites(PDO $pdo): array
{
    return [
        'stock' => $pdo->query('SELECT id, quantity FROM stock_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'loans' => $pdo->query('SELECT id, status FROM loans ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'catalog' => $pdo->query('SELECT id, name, min_stock FROM catalog_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'logs' => (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
        'settings' => $pdo->query("SELECT key, value FROM settings WHERE key LIKE 'ai%' OR key LIKE '%api_key%' ORDER BY key")->fetchAll(PDO::FETCH_ASSOC),
    ];
}

appAiConfig([]);
check(Ai::configStatus() === Ai::STATUS_EMPTY, 'missing ai block is empty');
check(Ai::isReady() === false, 'missing ai block is not ready');
$empty = Ai::suggest('draft', '드릴');
check($empty['ok'] === false && $empty['error'] === 'ai_unconfigured', 'unconfigured suggest fails closed');
check($empty['suggestion'] === null, 'unconfigured suggest has no draft');
check(Ai::provider() instanceof UnconfiguredProvider, 'empty provider uses unconfigured adapter');

appAiConfig(['provider' => '', 'api_key' => '', 'base_url' => '', 'model' => '']);
check(Ai::configStatus() === Ai::STATUS_EMPTY && !Ai::isReady(), 'blank ai keys stay empty');
check(Ai::suggest('draft', '오실로스코프')['error'] === 'ai_unconfigured', 'blank keys fail closed');

appAiConfig(['provider' => 'openai', 'api_key' => '', 'base_url' => '', 'model' => '']);
check(Ai::configStatus() === Ai::STATUS_PARTIAL, 'openai without key is partial');
check(!Ai::isReady(), 'openai without key is not ready');
check(Ai::suggest('draft', '드릴')['error'] === 'ai_config_partial', 'openai without key fails closed');
check(!(new OpenAiProvider())->isConfigured(), 'openai adapter is unconfigured without key');

appAiConfig(['provider' => 'upstage', 'api_key' => '', 'base_url' => '', 'model' => '']);
check(Ai::configStatus() === Ai::STATUS_PARTIAL, 'upstage without key is partial');
check(Ai::suggest('draft', '드릴')['error'] === 'ai_config_partial', 'upstage without key fails closed');
check(!(new UpstageProvider())->isConfigured(), 'upstage adapter is unconfigured without key');

appAiConfig(['provider' => 'ollama', 'api_key' => '', 'base_url' => '', 'model' => '']);
check(Ai::configStatus() === Ai::STATUS_PARTIAL, 'ollama without base_url is partial');
check(Ai::suggest('draft', '드릴')['error'] === 'ai_config_partial', 'ollama without base_url fails closed');
check(!(new OllamaProvider())->isConfigured(), 'ollama adapter is unconfigured without base_url');

appAiConfig(['provider' => 'claude', 'api_key' => 'sk-test', 'base_url' => '', 'model' => '']);
check(Ai::configStatus() === Ai::STATUS_UNKNOWN, 'unknown provider is unknown');
check(Ai::suggest('draft', '드릴')['error'] === 'ai_provider_unknown', 'unknown provider fails closed');

appAiConfig(['provider' => '', 'api_key' => 'sk-only', 'base_url' => '', 'model' => '']);
check(Ai::configStatus() === Ai::STATUS_PARTIAL, 'key without provider is partial');
check(Ai::suggest('draft', '드릴')['ok'] === false, 'key without provider fails closed');

$pdo = memoryDb();
seedStock($pdo);
$before = snapshotWrites($pdo);
check(stockQty($pdo) === 6.0, 'seed stock is 6');

appAiConfig(['provider' => 'openai', 'api_key' => 'sk-test-openai', 'base_url' => '', 'model' => 'gpt-test']);
check(Ai::isReady(), 'openai with key is ready');
check(Ai::provider() instanceof OpenAiProvider, 'openai adapter selected');
check(Ai::provider() instanceof AiProvider, 'openai implements AiProvider');
$openai = Ai::suggest('draft_item', '디지털 멀티미터');
check($openai['ok'] === true && $openai['suggestion'] instanceof AiSuggestion, 'configured openai returns a suggestion');
check($openai['suggestion']->provider === 'openai', 'openai suggestion names provider');
check($openai['suggestion']->task === 'draft_item', 'suggestion keeps the task');
check(!str_contains($openai['suggestion']->summary, '출고') || str_contains($openai['suggestion']->summary, '바꾸지'), 'suggestion text stays advisory');

appAiConfig(['provider' => 'upstage', 'api_key' => 'upstage-test', 'base_url' => '', 'model' => '']);
$upstage = Ai::suggest('category', '용접 공구');
check($upstage['ok'] === true && $upstage['suggestion'] instanceof AiSuggestion, 'configured upstage returns a suggestion');
check($upstage['suggestion']->provider === 'upstage', 'upstage suggestion names provider');

appAiConfig(['provider' => 'ollama', 'api_key' => '', 'base_url' => 'http://127.0.0.1:11434', 'model' => 'llama']);
check(Ai::isReady(), 'ollama with base_url is ready without api key');
$ollama = Ai::suggest('search', '용접실 드릴');
check($ollama['ok'] === true && $ollama['suggestion']->provider === 'ollama', 'configured ollama returns a suggestion');

$handlerCalls = 0;
Ai::setSuggestHandler(static function (AiProvider $provider, string $task, string $prompt) use (&$handlerCalls): array {
    $handlerCalls++;
    return [
        'ok' => true,
        'error' => null,
        'suggestion' => new AiSuggestion($provider->id(), $task, '스텁 초안', '테스트 제안', ['prompt' => $prompt]),
    ];
});
$stubbed = Ai::suggest('draft', '토크렌치');
check($handlerCalls === 1 && $stubbed['ok'] === true, 'test suggest handler is used when configured');
check($stubbed['suggestion'] instanceof AiSuggestion && $stubbed['suggestion']->title === '스텁 초안', 'handler suggestion is returned');
Ai::setSuggestHandler(null);

check(snapshotWrites($pdo) === $before, 'suggest does not write stock/loan/catalog/settings');
check(stockQty($pdo) === 6.0, 'stock quantity unchanged after suggestions');
check((int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn() === 0, 'no activity log from AI suggest');

$threw = false;
try {
    Ai::rejectInventoryWrite($openai['suggestion']);
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'ai_write_forbidden';
}
check($threw, 'rejectInventoryWrite always fails closed');
check(Ai::canMutateInventory() === false, 'canMutateInventory is always false');
check(stockQty($pdo) === 6.0 && snapshotWrites($pdo) === $before, 'forbidden write hook does not mutate stock');

$aiFiles = [
    $root . '/app/Ai.php',
    $root . '/app/AiProvider.php',
    $root . '/app/AiSuggestion.php',
    $root . '/app/Ai/OpenAiProvider.php',
    $root . '/app/Ai/UpstageProvider.php',
    $root . '/app/Ai/OllamaProvider.php',
    $root . '/app/Ai/UnconfiguredProvider.php',
];
$writeNeedles = [
    'Stock::',
    'Loan::',
    'Catalog::',
    'Inventory::',
    'CatalogCsv::',
    'INSERT INTO stock',
    'UPDATE stock',
    'INSERT INTO loans',
    'UPDATE loans',
    'INSERT INTO catalog',
    'UPDATE catalog',
    'Database::pdo',
];
foreach ($aiFiles as $file) {
    $src = (string) file_get_contents($file);
    check($src !== '', $file . ' exists');
    foreach ($writeNeedles as $needle) {
        check(!str_contains($src, $needle), basename($file) . ' has no ' . $needle . ' write hook');
    }
}

$aiSrc = (string) file_get_contents($root . '/app/Ai.php');
check(str_contains($aiSrc, 'rejectInventoryWrite'), 'Ai documents the forbidden write path');
check(!preg_match('/function apply/', $aiSrc), 'Ai has no apply/write helper');
check(!method_exists(Ai::class, 'apply') && !method_exists(Ai::class, 'applyToInventory'), 'no apply methods on Ai');
check(!method_exists(Stock::class, 'applyAi') && !method_exists(Loan::class, 'applyAi') && !method_exists(Catalog::class, 'applyAi') && !method_exists(Inventory::class, 'applyAi'), 'inventory helpers have no AI apply hooks');

$router = (string) file_get_contents($root . '/app/Router.php');
check(!str_contains($router, 'settings/ai') && !preg_match('/\'ai/', $router), 'router has no AI mutation route');

$settingsCtl = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
check(str_contains($settingsCtl, 'Ai::isReady()'), 'settings shows AI connection status');
check(!str_contains($settingsCtl, 'function saveAi'), 'settings has no AI write action');
check(!str_contains($settingsCtl, 'Ai::apiKey()'), 'settings controller does not pass the API key to the view');

$settingsTpl = (string) file_get_contents($root . '/templates/settings/index.php');
check(str_contains($settingsTpl, '연결 필요'), 'settings shows 연결 필요');
check(str_contains($settingsTpl, 'id="ai"'), 'settings has an AI card');
check(str_contains($settingsTpl, 'empty($aiReady)'), 'settings hides connected details when not connected');
check(!preg_match('/name=["\']api_key/', $settingsTpl), 'settings has no api_key input');
check(!preg_match('/name=["\']ai_/', $settingsTpl), 'settings has no AI form fields');
check(!str_contains($settingsTpl, 'settings/ai'), 'settings does not post AI secrets');

$moreTpl = (string) file_get_contents($root . '/templates/more/index.php');
check(str_contains($moreTpl, '!empty($aiReady)'), 'more hides AI menu when not connected');
check(str_contains($moreTpl, '제안 전용'), 'more labels AI as suggestion-only when shown');

$moreCtl = (string) file_get_contents($root . '/app/Controllers/MoreController.php');
check(str_contains($moreCtl, 'Ai::isReady()'), 'more uses Ai::isReady to hide the menu');

$example = (string) file_get_contents($root . '/config.example.php');
check(str_contains($example, "'provider' => ''") && str_contains($example, "'api_key' => ''"), 'local example has empty AI secrets');
check(!preg_match("/api_key'\\s*=>\\s*'(?!')[^']+'/", $example), 'local example ships no real AI key');

$production = (string) file_get_contents($root . '/config.production.example.php');
check(str_contains($production, "'provider' => ''") && str_contains($production, "'api_key' => ''"), 'production example has empty AI secrets');
check(!preg_match("/api_key'\\s*=>\\s*'(?!')[^']+'/", $production), 'production example ships no real AI key');

$gitignore = (string) file_get_contents($root . '/.gitignore');
check(str_contains($gitignore, 'config.php'), 'config.php stays untracked');

$composer = glob($root . '/composer.json');
check($composer === [] || $composer === false, 'still Composer-free');

Ai::setSuggestHandler(null);
echo "PASS: {$checks} ai-provider checks\n";
