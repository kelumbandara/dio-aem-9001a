<?php

namespace App\Modules\Communications\Http\Controllers\Api;

use App\Modules\Communications\Http\Controllers\Concerns\PresentsMessages;
use App\Modules\Communications\Models\Message;
use App\Modules\Communications\Services\MessageService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    use PresentsMessages;

    public function index(Request $request): JsonResponse
    {
        $this->needs($request, 'comm.message.view');
        $request->validate(['filter.status' => ['sometimes', Rule::in(Message::STATUSES)], 'filter.channel' => ['sometimes', Rule::in(['email', 'whatsapp'])], 'filter.direction' => ['sometimes', Rule::in(['out', 'in'])], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $f = (array) $request->input('filter', []);
        $page = Message::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($f['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Message $m) => $this->present($m))->values(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Message $message): JsonResponse
    {
        $this->needs($request, 'comm.message.view');

        return response()->json(['data' => $this->present($message, true)]);
    }

    /** Prepares a message from a template (a draft: nothing is sent). */
    public function store(Request $request, MessageService $messages): JsonResponse
    {
        $this->needs($request, 'comm.message.draft');
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:10'], 'template' => ['required', 'string', 'max:60'], 'party_type' => ['nullable', 'string', 'max:20'], 'party' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:190'], 'locale' => ['nullable', 'string', 'max:5'], 'params' => ['nullable', 'array'], 'params.*' => ['nullable', 'string', 'max:300'],
            'related_type' => ['nullable', 'string', 'max:60'], 'related_id' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json(['data' => $this->present($messages->draft($data, $request->user()->id), true)], 201);
    }

    public function send(Request $request, Message $message, MessageService $messages): JsonResponse
    {
        $this->needs($request, 'comm.message.send');

        return response()->json(['data' => $this->present($messages->send($message, $request->user()->id, 'api'), true)]);
    }

    public function cancel(Request $request, Message $message, MessageService $messages): JsonResponse
    {
        $this->needs($request, 'comm.message.draft');

        return response()->json(['data' => $this->present($messages->cancel($message, $request->user()->id), true)]);
    }
}
