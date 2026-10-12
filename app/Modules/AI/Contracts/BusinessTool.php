<?php

namespace App\Modules\AI\Contracts;

use App\Modules\AI\DTOs\ToolContext;
use App\Modules\AI\DTOs\ToolResult;
use App\Modules\AI\Exceptions\AiException;

/** One thing the assistant may ask the platform to do. Sprint 11 has read tools only (risk R0). */
interface BusinessTool
{
    /** Name the model calls it by, e.g. `sales.summary`. */
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON Schema of the input (an object) */
    public function schema(): array;

    /** R0 read, R1 draft, R2 change, R3 commit (docs/architecture/ai-agents.md §3). */
    public function risk(): string;

    /** The permission the acting person must hold. */
    public function permission(): string;

    /** The module that must be switched on. */
    public function module(): string;

    /** @param  array<string, mixed>  $input  already validated against the schema
     * @throws AiException ai.tool_input when the values are well-formed but not acceptable (for example a period that ends before it starts)
     */
    public function handle(array $input, ToolContext $context): ToolResult;
}
