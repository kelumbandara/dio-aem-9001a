<?php

namespace App\Modules\Finance\Http\Controllers\Api;

use App\Modules\Finance\Actions\PostManualJournal;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Modules\Finance\Http\Requests\PostJournalRequest;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\JournalInput;
use App\Modules\Finance\Services\JournalPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function index(Request $request, JournalPresenter $presenter): JsonResponse
    {
        $this->authorize('finance.view');
        $request->validate([
            'filter.kind' => ['sometimes', 'in:manual,system,reversal,opening'], 'filter.status' => ['sometimes', 'in:posted,reversed'], 'filter.q' => ['sometimes', 'string', 'max:100'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'], 'filter.to' => ['sometimes', 'date_format:Y-m-d'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $f = (array) $request->input('filter', []);

        $page = JournalEntry::query()
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('entry_date', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->where('entry_date', '<=', $v))
            ->when($f['q'] ?? null, function ($q, $v) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(journal_no) like ?', [$term])->orWhereRaw('lower(description) like ?', [$term])->orWhereRaw("lower(coalesce(reference, '')) like ?", [$term]));
            })
            ->orderByDesc('entry_date')->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->headers($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(JournalEntry $journal, JournalPresenter $presenter): JsonResponse
    {
        $this->authorize('finance.view');

        return response()->json(['data' => $presenter->full($journal)]);
    }

    /** Posts at once, or answers 202 with the approval request when a policy asks for approval. */
    public function store(PostJournalRequest $request, JournalInput $input, PostManualJournal $post, JournalPresenter $presenter): JsonResponse
    {
        $result = $post($input->request($request->validated(), $request->user()->id), $request->user()->id, 'api', $request->header('Idempotency-Key'));

        if (! $result instanceof PostedJournal) {
            return response()->json(['data' => $result + ['status' => 'pending']], 202);
        }

        return response()->json(['data' => $presenter->full(JournalEntry::query()->findOrFail($result->id))], 201)->header('Location', route('api.v1.journals.show', $result->publicId));
    }

    public function reverse(Request $request, JournalEntry $journal, JournalPoster $poster, JournalPresenter $presenter): JsonResponse
    {
        $this->authorize('finance.reverse');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $reversal = $poster->reverse($journal->id, $data['note'] ?? null, $request->user()->id);

        return response()->json(['data' => $presenter->full(JournalEntry::query()->findOrFail($reversal->id))], 201);
    }
}
