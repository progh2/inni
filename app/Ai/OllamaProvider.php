<?php

declare(strict_types=1);

namespace Inni\Ai;

use Inni\Ai;
use Inni\AiProvider;

/** Local Ollama seat. Requires provider + base_url. Empty base_url fails closed. No inventory writes. */
final class OllamaProvider implements AiProvider
{
    public function id(): string
    {
        return Ai::PROVIDER_OLLAMA;
    }

    public function label(): string
    {
        return Ai::providerLabel(Ai::PROVIDER_OLLAMA);
    }

    public function isConfigured(): bool
    {
        return Ai::providerId() === Ai::PROVIDER_OLLAMA && Ai::baseUrl() !== '';
    }

    public function suggest(string $task, string $prompt): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'ai_unconfigured', 'suggestion' => null];
        }
        return Ai::completeSuggestion($this, $task, $prompt);
    }
}
