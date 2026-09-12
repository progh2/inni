<?php

declare(strict_types=1);

namespace Inni;

/** Read-only draft. Callers must show this to a human; it is not a write command. */
final class AiSuggestion
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $task,
        public readonly string $title,
        public readonly string $summary,
        public readonly array $fields = [],
    ) {
    }

    /** @return array{provider: string, task: string, title: string, summary: string, fields: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'task' => $this->task,
            'title' => $this->title,
            'summary' => $this->summary,
            'fields' => $this->fields,
        ];
    }
}
