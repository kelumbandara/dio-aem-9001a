<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Contracts\AiProvider;
use App\Modules\AI\DTOs\ProviderRequest;
use App\Modules\AI\DTOs\ToolContext;
use App\Modules\AI\Exceptions\AiException;
use App\Modules\AI\Models\Conversation;
use App\Modules\AI\Models\Execution;
use App\Modules\AI\Models\Message;
use App\Modules\AI\Services\Tools\SchemaValidator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * The run loop (docs/architecture/ai-agents.md §2): limits → question → provider → for each tool request: known? allowed? valid? →
 * run → result back → … → answer. The model only ever asks; every decision is made here from the person's permissions. Every run,
 * successful or not, leaves one immutable audit record.
 */
final class Orchestrator
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly ToolRegistry $tools,
        private readonly AgentRegistry $agents,
        private readonly SafetyPolicy $safety,
        private readonly UsageMeter $meter,
        private readonly SchemaValidator $validator,
    ) {}

    /**
     * Answers one question in a conversation and returns the saved answer.
     *
     * @param  Authenticatable&Authorizable  $user  the conversation's owner; the assistant acts with exactly their permissions
     *
     * @throws AiException
     */
    public function ask(Authenticatable&Authorizable $user, Conversation $conversation, string $question, string $agent = AgentRegistry::DEFAULT): Message
    {
        $userId = (int) $user->getAuthIdentifier();
        $started = hrtime(true);
        $today = CarbonImmutable::now(config('core.display_timezone'))->startOfDay();
        $def = $this->agents->get($agent, App::getLocale(), $today);

        $text = $this->safety->question($question);
        if ($text === null) {
            throw AiException::make('ai.message_invalid', 'Type a question of up to '.config('ai.max_message_chars').' characters.', 422, 'message');
        }

        try {
            $this->meter->assertAllowed($userId);
        } catch (AiException $e) {
            $this->audit($conversation, $userId, $def, $e->errorCode === 'ai.rate_limited' ? 'rate_limited' : 'budget', hash('sha256', $def['system'].$text), 0, [], 0, 0, $started, $e->errorCode);
            throw $e;
        }

        $history = $this->history($conversation);
        Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $text]);
        $messages = $this->merge([...$history, ['role' => 'user', 'content' => $text]]);

        $context = new ToolContext($user, $userId, $today);
        $available = $this->tools->availableTo($user);
        $schemas = array_map(fn ($t) => ['name' => $t->name(), 'description' => $t->description(), 'input_schema' => $t->schema()], $available);
        $hash = hash('sha256', $def['system'].json_encode($messages));

        $steps = 0;
        $in = $out = 0;
        $audit = [];
        $refs = [];
        $answer = null;
        $status = 'ok';

        try {
            while ($steps < (int) config('ai.max_steps')) {
                $steps++;
                $res = $this->provider->complete(new ProviderRequest($def['model'], $def['system'], $messages, $schemas, (int) config('ai.max_tokens')));
                $in += $res->inputTokens;
                $out += $res->outputTokens;

                if ($res->toolCalls === []) {
                    $answer = trim($res->text);
                    break;
                }

                $messages[] = ['role' => 'assistant', 'content' => $res->blocks];
                $results = [];
                foreach ($res->toolCalls as $call) {
                    [$block, $entry, $got] = $this->runTool($call->id, $call->name, $call->input, $user, $context);
                    $results[] = $block;
                    $audit[] = $entry;
                    array_push($refs, ...$got);
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AiException $e) {
            $this->audit($conversation, $userId, $def, 'error', $hash, $steps, $audit, $in, $out, $started, $e->errorCode);
            throw $e;
        }

        if ($answer === null) {
            $status = 'max_steps';
            $answer = __('ai::ui.max_steps');
        } elseif ($answer === '') {
            $answer = __('ai::ui.empty_answer');
        }

        $execution = $this->audit($conversation, $userId, $def, $status, $hash, $steps, $audit, $in, $out, $started, null);

        $conversation->touch();

        return Message::create([
            'conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $answer,
            'refs' => $this->uniqueRefs($refs), 'execution_id' => $execution->id,
        ]);
    }

    /**
     * Runs one requested tool, or refuses it. The result block goes back to the model; the entry goes to the audit trail.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<array{label: string, url: string}>}
     */
    private function runTool(string $id, string $name, array $input, Authorizable $user, ToolContext $context): array
    {
        $t0 = hrtime(true);
        $entry = ['name' => mb_substr($name, 0, 80), 'input' => $this->safety->cleanResult($input)['data'], 'status' => 'ok', 'flagged' => false, 'ms' => 0];
        $finish = function (string $status, string $message) use ($id, &$entry, $t0): array {
            $entry['status'] = $status;
            $entry['error'] = $message;
            $entry['ms'] = (int) ((hrtime(true) - $t0) / 1_000_000);

            return [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $message, 'is_error' => true], $entry, []];
        };

        $tool = $this->tools->find($name, $user);
        if ($tool === null) {
            return $finish('denied', 'This tool is not available to you.');       // unknown, module off and no permission read the same
        }
        $problems = $this->validator->problems($tool->schema(), $input);
        if ($problems !== []) {
            return $finish('invalid', 'The input was refused: '.implode(' ', $problems));
        }

        try {
            $result = $tool->handle($input, $context);
        } catch (AiException $e) {
            return $finish('invalid', 'The input was refused: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $finish('error', 'The tool failed. Say that you could not get this information.');
        }

        $clean = $this->safety->cleanResult($result->data);
        $entry['flagged'] = $clean['flagged'];
        $entry['ms'] = (int) ((hrtime(true) - $t0) / 1_000_000);

        return [
            ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $this->safety->envelope($tool->name(), $clean['data'], $clean['flagged'])],
            $entry,
            array_map(fn (array $r) => ['label' => $this->safety->cleanResult(['l' => $r['label']])['data']['l'], 'url' => $r['url']], $result->refs),
        ];
    }

    /** @return list<array{role: string, content: string}> */
    private function history(Conversation $conversation): array
    {
        return Message::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->limit((int) config('ai.history_messages'))->get()
            ->reverse()->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
    }

    /**
     * Neighbouring messages by the same speaker are joined (an earlier question may have gone unanswered because of an error).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string|list<array<string, mixed>>}>
     */
    private function merge(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['role'] === $m['role']) {
                $out[$last]['content'] .= "\n\n".$m['content'];
            } else {
                $out[] = $m;
            }
        }
        while ($out !== [] && $out[0]['role'] !== 'user') {
            array_shift($out);
        }

        return $out;
    }

    /**
     * @param  list<array{label: string, url: string}>  $refs
     * @return list<array{label: string, url: string}>
     */
    private function uniqueRefs(array $refs): array
    {
        $seen = [];
        foreach ($refs as $r) {
            $seen[$r['url']] ??= $r;
        }

        return array_slice(array_values($seen), 0, 12);
    }

    /**
     * @param  array{name: string, model: string, system: string}  $def
     * @param  list<array<string, mixed>>  $calls
     */
    private function audit(Conversation $conversation, int $userId, array $def, string $status, string $hash, int $steps, array $calls, int $in, int $out, int|float $started, ?string $errorCode): Execution
    {
        return Execution::create([
            'conversation_id' => $conversation->id, 'user_id' => $userId, 'agent' => $def['name'], 'model' => $def['model'], 'prompt_hash' => $hash,
            'status' => $status, 'steps' => $steps, 'tool_calls' => $calls, 'input_tokens' => $in, 'output_tokens' => $out,
            'cost_usd' => $this->meter->cost($def['model'], $in, $out), 'error_code' => $errorCode,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
