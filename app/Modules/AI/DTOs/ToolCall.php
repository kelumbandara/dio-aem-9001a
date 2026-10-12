<?php

namespace App\Modules\AI\DTOs;

/** A model's request to run a tool. The model only asks; the platform decides (docs/architecture/ai-agents.md §2). */
final readonly class ToolCall
{
    /** @param  array<string, mixed>  $input */
    public function __construct(public string $id, public string $name, public array $input) {}
}
