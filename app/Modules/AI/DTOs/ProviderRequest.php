<?php

namespace App\Modules\AI\DTOs;

/**
 * What is sent to a model. Messages and tools use the common block format: a message is {role, content} where content is text or a
 * list of blocks (`text`, `tool_use` {id, name, input}, `tool_result` {tool_use_id, content, is_error}).
 */
final readonly class ProviderRequest
{
    /**
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public array $tools,
        public int $maxTokens,
    ) {}
}
