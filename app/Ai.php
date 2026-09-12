<?php

declare(strict_types=1);

namespace Inni;

use Inni\Ai\OllamaProvider;
use Inni\Ai\OpenAiProvider;
use Inni\Ai\UnconfiguredProvider;
use Inni\Ai\UpstageProvider;

/**
 * AI provider seat (OpenAI / Upstage / Ollama). Suggestions only.
 * Keys stay in server config.php. This class has no inventory write hooks.
 */
final class Ai
{
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_UPSTAGE = 'upstage';
    public const PROVIDER_OLLAMA = 'ollama';

    public const STATUS_EMPTY = 'empty';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_READY = 'ready';

    /** @var list<string> */
    public const PROVIDERS = [self::PROVIDER_OPENAI, self::PROVIDER_UPSTAGE, self::PROVIDER_OLLAMA];

    /** @var null|callable(AiProvider, string, string): array{ok: bool, error: ?string, suggestion: ?AiSuggestion} */
    private static $suggestHandler = null;

    public static function providerId(): string
    {
        return strtolower(trim((string) App::config('ai.provider', '')));
    }

    public static function apiKey(): string
    {
        return trim((string) App::config('ai.api_key', ''));
    }

    public static function baseUrl(): string
    {
        return trim((string) App::config('ai.base_url', ''));
    }

    public static function model(): string
    {
        return trim((string) App::config('ai.model', ''));
    }

    public static function providerLabel(?string $id = null): string
    {
        return match ($id ?? self::providerId()) {
            self::PROVIDER_OPENAI => 'OpenAI',
            self::PROVIDER_UPSTAGE => 'Upstage',
            self::PROVIDER_OLLAMA => 'Ollama',
            default => '',
        };
    }

    /** @return self::STATUS_* */
    public static function configStatus(): string
    {
        $id = self::providerId();
        if ($id === '') {
            return self::apiKey() === '' && self::baseUrl() === ''
                ? self::STATUS_EMPTY
                : self::STATUS_PARTIAL;
        }
        if (!in_array($id, self::PROVIDERS, true)) {
            return self::STATUS_UNKNOWN;
        }
        return self::provider()->isConfigured() ? self::STATUS_READY : self::STATUS_PARTIAL;
    }

    public static function isReady(): bool
    {
        return self::configStatus() === self::STATUS_READY;
    }

    public static function provider(): AiProvider
    {
        return match (self::providerId()) {
            self::PROVIDER_OPENAI => new OpenAiProvider(),
            self::PROVIDER_UPSTAGE => new UpstageProvider(),
            self::PROVIDER_OLLAMA => new OllamaProvider(),
            default => new UnconfiguredProvider(),
        };
    }

    /**
     * Suggestion only. Fail closed when unset / unknown / incomplete.
     * Never writes stock, loans, catalog, or inventory-check rows.
     *
     * @return array{ok: bool, error: ?string, suggestion: ?AiSuggestion}
     */
    public static function suggest(string $task, string $prompt): array
    {
        $task = trim($task);
        $prompt = trim($prompt);
        if ($task === '' || $prompt === '') {
            return ['ok' => false, 'error' => 'ai_prompt_empty', 'suggestion' => null];
        }
        if (!self::isReady()) {
            return ['ok' => false, 'error' => self::unconfiguredError(), 'suggestion' => null];
        }
        return self::provider()->suggest($task, $prompt);
    }

    /** @internal CLI tests stub — never used in production. */
    public static function setSuggestHandler(?callable $handler): void
    {
        self::$suggestHandler = $handler;
    }

    /**
     * Adapters call this after their own isConfigured() check.
     * No PDO. No Stock/Loan/Catalog. Live HTTP is out of this seat.
     *
     * @return array{ok: bool, error: ?string, suggestion: ?AiSuggestion}
     */
    public static function completeSuggestion(AiProvider $provider, string $task, string $prompt): array
    {
        if (!$provider->isConfigured() || !self::isReady()) {
            return ['ok' => false, 'error' => self::unconfiguredError(), 'suggestion' => null];
        }
        if (self::$suggestHandler !== null) {
            $data = (self::$suggestHandler)($provider, $task, $prompt);
            if (!is_array($data)) {
                return ['ok' => false, 'error' => 'ai_handler_invalid', 'suggestion' => null];
            }
            $suggestion = $data['suggestion'] ?? null;
            if ($suggestion instanceof AiSuggestion) {
                return ['ok' => true, 'error' => null, 'suggestion' => $suggestion];
            }
            return [
                'ok' => false,
                'error' => isset($data['error']) && is_string($data['error']) ? $data['error'] : 'ai_handler_rejected',
                'suggestion' => null,
            ];
        }

        // Seat only: a local draft so callers can render a proposal. No network, no writes.
        $label = $provider->label();
        return [
            'ok' => true,
            'error' => null,
            'suggestion' => new AiSuggestion(
                $provider->id(),
                $task,
                $label . ' 초안',
                '제안만 합니다. 재고·대여·대장은 바꾸지 마세요. 사람이 확인한 뒤에만 저장하세요.',
                ['prompt' => $prompt, 'model' => self::model()]
            ),
        ];
    }

    /** There is no path from AI output to stock/loan/catalog writes. */
    public static function canMutateInventory(): bool
    {
        return false;
    }

    /**
     * Explicit reject. AI output must never be applied to inventory automatically.
     *
     * @return never
     */
    public static function rejectInventoryWrite(mixed $suggestion = null): never
    {
        unset($suggestion);
        throw new \RuntimeException('ai_write_forbidden');
    }

    private static function unconfiguredError(): string
    {
        return match (self::configStatus()) {
            self::STATUS_UNKNOWN => 'ai_provider_unknown',
            self::STATUS_PARTIAL => 'ai_config_partial',
            default => 'ai_unconfigured',
        };
    }
}
