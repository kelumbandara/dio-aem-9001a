<?php

namespace App\Modules\AI\Services\Tools;

use App\Modules\AI\Contracts\BusinessTool;
use App\Modules\AI\DTOs\ToolContext;
use App\Modules\AI\DTOs\ToolResult;
use App\Modules\AI\Exceptions\AiException;
use Carbon\CarbonImmutable;
use Closure;

/** A tool (read by default; drafts say R1) defined by its name, schema and a function over one module's metrics contract. */
final class ClosureTool implements BusinessTool
{
    public const PERIOD = [
        'from' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'First day, YYYY-MM-DD (Colombo date), included.'],
        'to' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'Last day, YYYY-MM-DD, included.'],
    ];

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @param  Closure(array<string, mixed>, ToolContext): ToolResult  $run
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $module,
        private readonly string $permission,
        private readonly array $properties,
        private readonly array $required,
        private readonly Closure $run,
        private readonly string $risk = 'R0',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => $this->properties === [] ? new \stdClass : $this->properties, 'required' => $this->required];
    }

    public function risk(): string
    {
        return $this->risk;
    }

    public function permission(): string
    {
        return $this->permission;
    }

    public function module(): string
    {
        return $this->module;
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        if (isset($input['from'], $input['to'])) {
            self::assertPeriod((string) $input['from'], (string) $input['to']);
        }

        return ($this->run)($input, $context);
    }

    /** A real calendar range of at most two years that does not end before it starts. */
    public static function assertPeriod(string $from, string $to): void
    {
        foreach (['from' => $from, 'to' => $to] as $field => $v) {
            if (! CarbonImmutable::canBeCreatedFromFormat($v, '!Y-m-d') || CarbonImmutable::createFromFormat('!Y-m-d', $v)->toDateString() !== $v) {
                throw AiException::make('ai.tool_input', "'{$field}' is not a real date.", 422, $field);
            }
        }
        if ($from > $to) {
            throw AiException::make('ai.tool_input', "'to' is before 'from'.", 422, 'to');
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 731) {
            throw AiException::make('ai.tool_input', 'The period may be at most two years.', 422, 'to');
        }
    }
}
