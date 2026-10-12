<?php

namespace App\Modules\AI\Http\Controllers\Web;

use App\Modules\AI\Exceptions\AiException;
use App\Modules\AI\Models\Conversation;
use App\Modules\AI\Services\ConversationService;
use App\Modules\AI\Services\Orchestrator;
use App\Modules\AI\Services\UsageMeter;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(Request $request, ConversationService $conversations): View
    {
        abort_unless($request->user()->can('ai.use'), 403);

        return view('ai::chat.show', ['conversations' => $conversations->recent($request->user()->id), 'conversation' => null, 'messages' => collect(), 'configured' => $this->configured()]);
    }

    public function show(Request $request, string $conversation, ConversationService $conversations): View
    {
        abort_unless($request->user()->can('ai.use'), 403);
        $c = $conversations->own($conversation, $request->user()->id);

        return view('ai::chat.show', ['conversations' => $conversations->recent($request->user()->id), 'conversation' => $c, 'messages' => $c->messages()->get(), 'configured' => $this->configured()]);
    }

    /** Starts a conversation with its first question. */
    public function start(Request $request, ConversationService $conversations, Orchestrator $orchestrator): RedirectResponse
    {
        abort_unless($request->user()->can('ai.use'), 403);
        $data = $request->validate(['message' => ['required', 'string']]);
        $c = $conversations->start($request->user()->id, $data['message']);

        try {
            $orchestrator->ask($request->user(), $c, $data['message']);
        } catch (AiException $e) {
            if (! $c->messages()->exists()) {                    // nothing was said: back to the empty page
                return redirect()->route('ai.chat.index')->withErrors(['message' => $e->getMessage()])->withInput();
            }

            return redirect()->route('ai.chat.show', $c->public_id)->withErrors(['message' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.chat.show', $c->public_id);
    }

    public function message(Request $request, string $conversation, ConversationService $conversations, Orchestrator $orchestrator): RedirectResponse
    {
        abort_unless($request->user()->can('ai.use'), 403);
        $c = $conversations->own($conversation, $request->user()->id);
        $data = $request->validate(['message' => ['required', 'string']]);

        return $this->ask($request, $c, $data['message'], $orchestrator);
    }

    public function usage(Request $request, UsageMeter $meter): View
    {
        abort_unless($request->user()->can('ai.usage.view'), 403);

        return view('ai::chat.usage', [
            'rows' => $meter->byUser(), 'company' => $meter->spent(null),
            'userBudget' => (string) config('ai.budget_user_month_usd'), 'companyBudget' => (string) config('ai.budget_company_month_usd'),
            'model' => (string) config('ai.model'), 'configured' => $this->configured(),
        ]);
    }

    private function ask(Request $request, Conversation $c, string $text, Orchestrator $orchestrator): RedirectResponse
    {
        try {
            $orchestrator->ask($request->user(), $c, $text);
        } catch (AiException $e) {
            return redirect()->route('ai.chat.show', $c->public_id)->withErrors(['message' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ai.chat.show', $c->public_id);
    }

    private function configured(): bool
    {
        return (string) config('ai.claude.key') !== '' || config('ai.provider') !== 'claude';
    }
}
