<?php

namespace App\Modules\AI\DTOs;

final readonly class ProviderResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<array<string, mixed>>  $blocks  the assistant's content blocks exactly as returned (sent back with the tool results)
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public array $blocks,
        public int $inputTokens,
        public int $outputTokens,
        public string $stopReason = 'end_turn',
    ) {}
}
