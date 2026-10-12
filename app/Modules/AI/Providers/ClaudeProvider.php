<?php

namespace App\Modules\AI\Providers;

use App\Modules\AI\Contracts\AiProvider;
use App\Modules\AI\DTOs\ProviderRequest;
use App\Modules\AI\DTOs\ProviderResponse;
use App\Modules\AI\DTOs\ToolCall;
use App\Modules\AI\Exceptions\AiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Messages API. The key is read from configuration (environment) at call time and sent only in the request header; it never
 * reaches a log, a prompt or an error message (errors carry the HTTP status only).
 */
final class ClaudeProvider implements AiProvider
{
    public function complete(ProviderRequest $request): ProviderResponse
    {
        $key = config('ai.claude.key');
        if (! is_string($key) || trim($key) === '') {
            throw AiException::notConfigured();
        }

        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => $request->messages,
        ];
        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
        }

        try {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => (string) config('ai.claude.version')])
                ->timeout((int) config('ai.claude.timeout'))
                ->acceptJson()
                ->post(rtrim((string) config('ai.claude.base_url'), '/').'/v1/messages', $body);
        } catch (ConnectionException) {
            throw AiException::unavailable('no connection');
        }

        if (! $res->successful()) {
            throw AiException::unavailable('status '.$res->status());
        }

        $blocks = [];
        $text = '';
        $calls = [];
        foreach ((array) $res->json('content', []) as $b) {
            if (! is_array($b)) {
                continue;
            }
            if (($b['type'] ?? '') === 'text') {
                $text .= (string) ($b['text'] ?? '');
                $blocks[] = ['type' => 'text', 'text' => (string) ($b['text'] ?? '')];
            } elseif (($b['type'] ?? '') === 'tool_use') {
                $input = is_array($b['input'] ?? null) ? $b['input'] : [];
                $calls[] = new ToolCall((string) ($b['id'] ?? ''), (string) ($b['name'] ?? ''), $input);
                $blocks[] = ['type' => 'tool_use', 'id' => (string) ($b['id'] ?? ''), 'name' => (string) ($b['name'] ?? ''), 'input' => $input === [] ? new \stdClass : $input];
            }
        }

        return new ProviderResponse(
            $text, $calls, $blocks,
            (int) $res->json('usage.input_tokens', 0), (int) $res->json('usage.output_tokens', 0),
            (string) $res->json('stop_reason', 'end_turn'),
        );
    }
}
