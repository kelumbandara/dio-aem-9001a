<?php

namespace App\Modules\AI\DTOs;

/** What a tool found. `refs` are the documents the figures rest on, so an answer can be checked (grounding). */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{label: string, url: string}>  $refs
     * @param  array<string, mixed>  $audit  extra facts for the audit trail (for example the number of a draft that was created)
     */
    public function __construct(public array $data, public array $refs = [], public array $audit = []) {}
}
