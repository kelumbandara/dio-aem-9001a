<?php

namespace Tests\Support;

use App\Modules\AI\Contracts\AiProvider;
use App\Modules\AI\DTOs\ProviderRequest;
use App\Modules\AI\DTOs\ProviderResponse;
use App\Modules\AI\DTOs\ToolCall;
use Closure;
use LogicException;

/**
 * A model that says exactly what the test scripted, so runs are repeatable (docs/architecture/ai-agents.md §6). Each step is a
 * ProviderResponse or a function of the request that returns one (or throws). Every request it received is kept for inspection.
 */
final class ScriptedProvider implements AiProvider
{
    /** @var list<ProviderRequest> */
    public array $requests = [];

    /** @param  list<ProviderResponse|Closure(ProviderRequest): ProviderResponse>  $steps */
    public function __construct(private array $steps) {}

    public function complete(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;
        $step = array_shift($this->steps) ?? throw new LogicException('The scripted model was asked more often than scripted.');

        return $step instanceof Closure ? $step($request) : $step;
    }

    public static function say(string $text, int $in = 100, int $out = 20): ProviderResponse
    {
        return new ProviderResponse($text, [], [['type' => 'text', 'text' => $text]], $in, $out);
    }

    /** @param  array<string, mixed>  $input */
    public static function useTool(string $name, array $input = [], string $id = 'toolu_1', int $in = 100, int $out = 20): ProviderResponse
    {
        return new ProviderResponse('', [new ToolCall($id, $name, $input)], [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input === [] ? new \stdClass : $input]], $in, $out, 'tool_use');
    }
}
