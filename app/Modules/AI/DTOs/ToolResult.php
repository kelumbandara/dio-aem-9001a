<?php

namespace App\Modules\AI\DTOs;

/** What a tool found. `refs` are the documents the figures rest on, so an answer can be checked (grounding). */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{label: string, url: string}>  $refs
     */
    public function __construct(public array $data, public array $refs = []) {}
}
