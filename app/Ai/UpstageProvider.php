<?php

declare(strict_types=1);

namespace Inni\Ai;

use Inni\Ai;
use Inni\AiProvider;

/** Upstage seat. Unconfigured (empty api_key) fails closed. No inventory writes. */
final class UpstageProvider implements AiProvider
{
    public function id(): string
    {
        return Ai::PROVIDER_UPSTAGE;
    }

    public function label(): string
    {
        return Ai::providerLabel(Ai::PROVIDER_UPSTAGE);
    }

    public function isConfigured(): bool
    {
        return Ai::providerId() === Ai::PROVIDER_UPSTAGE && Ai::apiKey() !== '';
    }

    public function suggest(string $task, string $prompt): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'ai_unconfigured', 'suggestion' => null];
        }
        return Ai::completeSuggestion($this, $task, $prompt);
    }
}
