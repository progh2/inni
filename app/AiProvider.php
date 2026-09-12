<?php

declare(strict_types=1);

namespace Inni;

/**
 * Suggestion-only provider seat. Implementations must never write stock, loans, or catalog.
 */
interface AiProvider
{
    public function id(): string;

    public function label(): string;

    public function isConfigured(): bool;

    /**
     * Fail closed when the provider is unconfigured. Never mutates inventory.
     *
     * @return array{ok: bool, error: ?string, suggestion: ?AiSuggestion}
     */
    public function suggest(string $task, string $prompt): array;
}
