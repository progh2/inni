<?php

declare(strict_types=1);

namespace Inni\Ai;

use Inni\AiProvider;

final class UnconfiguredProvider implements AiProvider
{
    public function id(): string
    {
        return '';
    }

    public function label(): string
    {
        return '';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function suggest(string $task, string $prompt): array
    {
        unset($task, $prompt);
        return ['ok' => false, 'error' => 'ai_unconfigured', 'suggestion' => null];
    }
}
