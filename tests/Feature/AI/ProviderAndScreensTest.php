<?php

use App\Modules\AI\Contracts\AiProvider;
use App\Modules\AI\DTOs\ProviderRequest;
use App\Modules\AI\Exceptions\AiException;
use App\Modules\AI\Models\Conversation;
use App\Modules\AI\Models\Execution;
use App\Modules\AI\Providers\ClaudeProvider;
use App\Modules\AI\Services\ConversationService;
use App\Modules\AI\Services\Tools\ToolCatalog;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\ScriptedProvider;

function aiRequest(array $o = []): ProviderRequest
{
    return new ProviderRequest($o['model'] ?? 'claude-test', $o['system'] ?? 'Be useful.', $o['messages'] ?? [['role' => 'user', 'content' => 'Hi']], $o['tools'] ?? [], 500);
}

describe('the Claude provider', function () {
    beforeEach(fn () => config(['ai.claude.key' => 'sk-test-secret-key', 'ai.claude.base_url' => 'https://api.example.test']));

    it('sends the key in a header only, with the model, system prompt, messages and tools', function () {
        Http::fake(['api.example.test/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hello']], 'usage' => ['input_tokens' => 12, 'output_tokens' => 3], 'stop_reason' => 'end_turn'])]);
        $tools = [['name' => 'sales.summary', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => new stdClass, 'required' => []]]];
        $res = (new ClaudeProvider)->complete(aiRequest(['tools' => $tools]));

        expect($res->text)->toBe('Hello')->and($res->inputTokens)->toBe(12)->and($res->outputTokens)->toBe(3)->and($res->toolCalls)->toBe([]);
        Http::assertSent(function ($r) {
            return $r->url() === 'https://api.example.test/v1/messages' && $r->header('x-api-key') === ['sk-test-secret-key'] && $r->header('anthropic-version') === ['2023-06-01']
                && $r['model'] === 'claude-test' && $r['system'] === 'Be useful.' && $r['max_tokens'] === 500 && $r['messages'][0]['content'] === 'Hi' && $r['tools'][0]['name'] === 'sales__summary'
                && ! str_contains($r->body(), 'sk-test-secret-key');
        });
    });

    it('sends tool names the provider accepts and reads them back as our own names', function () {
        Http::fake(['*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'sales__top_customers', 'input' => ['from' => '2026-11-01']]], 'usage' => ['input_tokens' => 5, 'output_tokens' => 2], 'stop_reason' => 'tool_use'])]);
        $tools = [['name' => 'sales.top_customers', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => new stdClass, 'required' => []]]];
        $earlier = [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_0', 'name' => 'stock.valuation', 'input' => new stdClass]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_0', 'content' => '{}']]]];
        $res = (new ClaudeProvider)->complete(aiRequest(['tools' => $tools, 'messages' => $earlier]));

        expect($res->toolCalls[0]->name)->toBe('sales.top_customers')->and($res->blocks[0]['name'])->toBe('sales.top_customers');
        Http::assertSent(fn ($r) => $r['tools'][0]['name'] === 'sales__top_customers' && $r['messages'][1]['content'][0]['name'] === 'stock__valuation');
    });

    it('has only tool names the provider accepts once translated, each one unique', function () {
        $wire = [];
        foreach (app(ToolCatalog::class)->all() as $tool) {
            expect($tool->name())->not->toContain('__')->and(ClaudeProvider::internalName(ClaudeProvider::wireName($tool->name())))->toBe($tool->name());
            expect(ClaudeProvider::wireName($tool->name()))->toMatch('/^[a-zA-Z0-9_-]{1,128}$/');
            $wire[] = ClaudeProvider::wireName($tool->name());
        }
        expect($wire)->toBe(array_values(array_unique($wire)));
    });

    it('leaves the tools field out when there are none', function () {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'x']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);
        (new ClaudeProvider)->complete(aiRequest());
        Http::assertSent(fn ($r) => ! array_key_exists('tools', $r->data()));
    });

    it('reads a tool request, with and without input, and keeps the blocks to send back', function () {
        Http::fake(['*' => Http::response(['content' => [
            ['type' => 'text', 'text' => 'Let me look.'],
            ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'sales.summary', 'input' => ['from' => '2026-11-01', 'to' => '2026-11-04']],
            ['type' => 'tool_use', 'id' => 'toolu_10', 'name' => 'stock.valuation', 'input' => []],
        ], 'usage' => ['input_tokens' => 50, 'output_tokens' => 9], 'stop_reason' => 'tool_use'])]);
        $res = (new ClaudeProvider)->complete(aiRequest());
        expect($res->toolCalls)->toHaveCount(2)->and($res->toolCalls[0]->name)->toBe('sales.summary')->and($res->toolCalls[0]->input['to'])->toBe('2026-11-04')->and($res->toolCalls[1]->input)->toBe([])
            ->and($res->blocks)->toHaveCount(3)->and($res->stopReason)->toBe('tool_use');
        expect(json_encode($res->blocks[2]['input']))->toBe('{}');                           // an empty input goes back as an object, not []
    });

    it('is not configured without a key, and sends nothing', function () {
        Http::fake();
        foreach ([null, '', '   '] as $key) {
            config(['ai.claude.key' => $key]);
            expect(fn () => (new ClaudeProvider)->complete(aiRequest()))->toThrow(AiException::class, 'not set up');
        }
        Http::assertNothingSent();
    });

    it('turns failures into one clear error that never contains the key', function () {
        $seq = Http::sequence();
        foreach ([429, 500, 529, 401] as $status) {
            $seq->push(['error' => ['message' => 'invalid x-api-key sk-test-secret-key'], 'x' => 1], $status);
        }
        Http::fake(['*' => $seq]);
        foreach ([429, 500, 529, 401] as $status) {
            try {
                (new ClaudeProvider)->complete(aiRequest());
                $this->fail('Expected an error');
            } catch (AiException $e) {
                expect($e->errorCode)->toBe('ai.provider_unavailable')->and($e->getMessage())->toContain("status {$status}: invalid x-api-key [key]")->and($e->getMessage())->not->toContain('sk-test');
            }
        }
    });

    it('turns a lost connection into the same error', function () {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error: could not resolve host while sending sk-test-secret-key')]);
        try {
            (new ClaudeProvider)->complete(aiRequest());
            $this->fail('Expected an error');
        } catch (AiException $e) {
            expect($e->errorCode)->toBe('ai.provider_unavailable')->and($e->getMessage())->not->toContain('sk-test');
        }
    });
});

describe('the chat screens', function () {
    beforeEach(function () {
        $this->seed(FinanceSeeder::class);
        $this->boss = userWithPermissions('ai.use', 'inventory.stock.view', 'sales.view');
        $this->admin = userWithPermissions('ai.use', 'ai.usage.view');
        $this->nobody = userWithPermissions('sales.view');
        $this->script = fn (array $steps) => app()->instance(AiProvider::class, new ScriptedProvider($steps));
    });

    it('sends signed-out people to sign in and shows nothing to people without the permission', function () {
        $this->get('/assistant')->assertRedirect('/login');
        $this->actingAs($this->nobody)->get('/assistant')->assertForbidden();
        $this->actingAs($this->nobody)->post('/assistant', ['message' => 'hi'])->assertForbidden();
        $this->actingAs($this->boss)->get('/assistant/usage')->assertForbidden();
    });

    it('shows the empty chat with examples, the sidebar entry, and a notice when no key is set', function () {
        config(['ai.claude.key' => null]);
        $this->actingAs($this->boss)->get('/assistant')->assertOk()->assertSee('You could ask:')->assertSee('Ask the assistant')->assertSee('not switched on yet');
        config(['ai.claude.key' => 'sk-x']);
        $this->actingAs($this->boss)->get('/assistant')->assertOk()->assertDontSee('not switched on yet');
    });

    it('asks a question, shows the answer with the pages it is based on, and keeps the conversation in the list', function () {
        ($this->script)([ScriptedProvider::useTool('stock.valuation'), ScriptedProvider::say('Your stock is worth LKR 0.')]);
        $res = $this->actingAs($this->boss)->post('/assistant', ['message' => 'What is my stock worth?']);
        $c = Conversation::firstOrFail();
        $res->assertRedirect(route('ai.chat.show', $c->public_id));

        $this->actingAs($this->boss)->get(route('ai.chat.show', $c->public_id))->assertOk()->assertSee('What is my stock worth?')->assertSee('Your stock is worth LKR 0.')
            ->assertSee('Based on:')->assertSee(route('inventory.stock.index'), false);
        $this->actingAs($this->boss)->get('/assistant')->assertSee('What is my stock worth?');

        ($this->script)([ScriptedProvider::say('Second answer')]);
        $this->actingAs($this->boss)->post(route('ai.chat.message', $c->public_id), ['message' => 'And again?'])->assertRedirect(route('ai.chat.show', $c->public_id));
        $this->actingAs($this->boss)->get(route('ai.chat.show', $c->public_id))->assertSee('Second answer')->assertSee('And again?');
    });

    it('shows the problem on the page, and keeps what was typed, when the service is unavailable or the question is bad', function () {
        ($this->script)([fn () => throw AiException::unavailable('status 529')]);
        $this->actingAs($this->boss)->followingRedirects()->post('/assistant', ['message' => 'Hello?'])->assertSee('did not answer')->assertSee('Hello?');
        $this->actingAs($this->boss)->post('/assistant', ['message' => ''])->assertSessionHasErrors('message');
        $this->actingAs($this->boss)->post('/assistant', ['message' => str_repeat('a', 2001)])->assertSessionHasErrors('message');
        expect(Execution::where('status', 'error')->count())->toBe(1);
    });

    it('reports a missing key as not set up, not as a crash', function () {
        config(['ai.claude.key' => null]);
        app()->bind(AiProvider::class, ClaudeProvider::class);
        $this->actingAs($this->boss)->followingRedirects()->post('/assistant', ['message' => 'Hello?'])->assertOk()->assertSee('not set up yet');
    });

    it('keeps one person\'s conversation from another', function () {
        $c = app(ConversationService::class)->start($this->boss->id, 'private question');
        $this->actingAs($this->admin)->get(route('ai.chat.show', $c->public_id))->assertNotFound();
        $this->actingAs($this->admin)->post(route('ai.chat.message', $c->public_id), ['message' => 'sneaky'])->assertNotFound();
    });

    it('shows the usage page to those allowed, with each person\'s cost', function () {
        config(['ai.pricing' => ['default' => ['input' => '3.00', 'output' => '15.00']], 'ai.model' => 'some-model']);   // a fixed price, whatever the configured model costs
        ($this->script)([ScriptedProvider::say('a', 1000, 500)]);
        $this->actingAs($this->boss)->post('/assistant', ['message' => 'q']);
        $this->actingAs($this->admin)->get('/assistant/usage')->assertOk()->assertSee($this->boss->name)->assertSee('USD 0.010500')->assertSee('Monthly limit');
    });

    it('is gone when the module is switched off', function () {
        app(ModuleRegistry::class)->disable('ai');
        $this->actingAs($this->boss)->get('/assistant')->assertForbidden();
    });
});

describe('the API', function () {
    beforeEach(function () {
        $this->seed(FinanceSeeder::class);
        $this->boss = userWithPermissions('ai.use', 'inventory.stock.view');
        $this->script = fn (array $steps) => app()->instance(AiProvider::class, new ScriptedProvider($steps));
    });

    it('starts a conversation, continues it, lists and reads it', function () {
        ($this->script)([ScriptedProvider::say('First'), ScriptedProvider::say('Second')]);
        $id = asApi($this->boss)->postJson('/api/v1/ai/conversations', ['message' => 'One'], idemKey())->assertCreated()->assertJsonPath('data.answer.content', 'First')->json('data.id');
        asApi($this->boss)->postJson("/api/v1/ai/conversations/{$id}/messages", ['message' => 'Two'], idemKey())->assertCreated()->assertJsonPath('data.content', 'Second');
        asApi($this->boss)->getJson('/api/v1/ai/conversations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        asApi($this->boss)->getJson("/api/v1/ai/conversations/{$id}")->assertOk()->assertJsonCount(4, 'data.messages')->assertJsonPath('data.messages.2.content', 'Two');
    });

    it('does not ask the model twice for a repeated request', function () {
        ($this->script)([ScriptedProvider::say('Only once')]);
        $key = idemKey();
        $first = asApi($this->boss)->postJson('/api/v1/ai/conversations', ['message' => 'One'], $key)->assertCreated();
        $again = asApi($this->boss)->postJson('/api/v1/ai/conversations', ['message' => 'One'], $key)->assertCreated();
        expect($again->json('data.answer.content'))->toBe('Only once')->and(Execution::count())->toBe(1);
    });

    it('answers errors in the standard envelope with the right status', function () {
        config(['ai.claude.key' => null]);
        app()->bind(AiProvider::class, ClaudeProvider::class);
        asApi($this->boss)->postJson('/api/v1/ai/conversations', ['message' => 'One'], idemKey())->assertStatus(503)->assertJsonPath('error.code', 'ai.not_configured');
        asApi($this->boss)->postJson('/api/v1/ai/conversations', [], idemKey())->assertStatus(422);

        config(['ai.budget_user_month_usd' => '0']);
        ($this->script)([ScriptedProvider::say('x')]);
        asApi($this->boss)->postJson('/api/v1/ai/conversations', ['message' => 'One'], idemKey())->assertStatus(429)->assertJsonPath('error.code', 'ai.budget_exceeded');
    });

    it('checks permissions and ownership', function () {
        $nobody = userWithPermissions('sales.view');
        asApi($nobody)->getJson('/api/v1/ai/conversations')->assertForbidden();
        asApi($nobody)->postJson('/api/v1/ai/conversations', ['message' => 'x'], idemKey())->assertForbidden();
        asApi($this->boss)->getJson('/api/v1/ai/usage')->assertForbidden();
        $c = app(ConversationService::class)->start($this->boss->id, 'mine');
        asApi(userWithPermissions('ai.use', 'ai.usage.view'))->getJson("/api/v1/ai/conversations/{$c->public_id}")->assertNotFound();
        asApi(userWithPermissions('ai.use', 'ai.usage.view'))->getJson('/api/v1/ai/usage')->assertOk()->assertJsonStructure(['data' => ['month_spent_usd', 'company_budget_usd', 'user_budget_usd', 'by_user']]);
    });

    it('needs a sign-in', function () {
        $this->getJson('/api/v1/ai/conversations')->assertUnauthorized();
        $this->postJson('/api/v1/ai/conversations', ['message' => 'x'], idemKey())->assertUnauthorized();
    });
});

describe('who gets the assistant', function () {
    it('gives it to the business roles and not to machine operators', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (['admin', 'accountant', 'sales', 'purchasing', 'production'] as $role) {
            expect(Role::findByName($role, 'web')->hasPermissionTo('ai.use'))->toBeTrue("{$role} should have ai.use");
        }
        expect(Role::findByName('operator', 'web')->hasPermissionTo('ai.use'))->toBeFalse()->and(Role::findByName('sales', 'web')->hasPermissionTo('ai.usage.view'))->toBeFalse()
            ->and(Role::findByName('admin', 'web')->hasPermissionTo('ai.usage.view'))->toBeTrue();
    });
});
