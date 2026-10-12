<?php

namespace App\Modules\AI\Http\Controllers\Api;

use App\Modules\AI\Models\Conversation;
use App\Modules\AI\Models\Message;
use App\Modules\AI\Services\ConversationService;
use App\Modules\AI\Services\Orchestrator;
use App\Modules\AI\Services\UsageMeter;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request, ConversationService $conversations): JsonResponse
    {
        $this->need($request, 'ai.use');

        return response()->json(['data' => $conversations->recent($request->user()->id)->map(fn (Conversation $c) => $this->conversation($c))->all()]);
    }

    public function show(Request $request, string $conversation, ConversationService $conversations): JsonResponse
    {
        $this->need($request, 'ai.use');
        $c = $conversations->own($conversation, $request->user()->id);

        return response()->json(['data' => $this->conversation($c) + ['messages' => $c->messages()->get()->map(fn (Message $m) => $this->present($m))->all()]]);
    }

    public function start(Request $request, ConversationService $conversations, Orchestrator $orchestrator): JsonResponse
    {
        $this->need($request, 'ai.use');
        $data = $request->validate(['message' => ['required', 'string']]);
        $c = $conversations->start($request->user()->id, $data['message']);
        $answer = $orchestrator->ask($request->user(), $c, $data['message']);

        return response()->json(['data' => $this->conversation($c->refresh()) + ['answer' => $this->present($answer)]], 201);
    }

    public function message(Request $request, string $conversation, ConversationService $conversations, Orchestrator $orchestrator): JsonResponse
    {
        $this->need($request, 'ai.use');
        $c = $conversations->own($conversation, $request->user()->id);
        $data = $request->validate(['message' => ['required', 'string']]);
        $answer = $orchestrator->ask($request->user(), $c, $data['message']);

        return response()->json(['data' => $this->present($answer)], 201);
    }

    public function usage(Request $request, UsageMeter $meter): JsonResponse
    {
        $this->need($request, 'ai.usage.view');

        return response()->json(['data' => [
            'month_spent_usd' => $meter->spent(null), 'company_budget_usd' => (string) config('ai.budget_company_month_usd'),
            'user_budget_usd' => (string) config('ai.budget_user_month_usd'), 'by_user' => $meter->byUser(),
        ]]);
    }

    private function need(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }

    /** @return array<string, mixed> */
    private function present(Message $m): array
    {
        return ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'refs' => $m->refs ?? [], 'created_at' => $m->created_at->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function conversation(Conversation $c): array
    {
        return ['id' => $c->public_id, 'title' => $c->title, 'updated_at' => $c->updated_at->toIso8601String()];
    }
}
