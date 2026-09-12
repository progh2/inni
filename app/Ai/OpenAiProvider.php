<?php

declare(strict_types=1);

namespace Inni\Ai;

use Inni\Ai;
use Inni\AiProvider;

/** Cloud OpenAI seat. Unconfigured (empty api_key) fails closed. No inventory writes. */
final class OpenAiProvider implements AiProvider
{
    public function id(): string
    {
        return Ai::PROVIDER_OPENAI;
    }

    public function label(): string
    {
        return Ai::providerLabel(Ai::PROVIDER_OPENAI);
    }

    public function isConfigured(): bool
    {
        return Ai::providerId() === Ai::PROVIDER_OPENAI && Ai::apiKey() !== '';
    }

    public function suggest(string $task, string $prompt): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'ai_unconfigured', 'suggestion' => null];
        }
        return Ai::completeSuggestion($this, $task, $prompt);
    }
}
