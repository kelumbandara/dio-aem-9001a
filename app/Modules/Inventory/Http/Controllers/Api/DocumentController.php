<?php

namespace App\Modules\Inventory\Http\Controllers\Api;

use App\Modules\Inventory\Actions\PostStockMovement;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\Http\Requests\PostStockDocumentRequest;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Services\DocumentPresenter;
use App\Modules\Inventory\Services\MovementInput;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index(Request $request, DocumentPresenter $presenter): JsonResponse
    {
        $this->authorize('viewAny', StockDocument::class);
        $request->validate([
            'filter.type' => ['sometimes', 'in:'.implode(',', StockDocument::TYPES)], 'filter.status' => ['sometimes', 'in:posted,reversed'],
            'filter.q' => ['sometimes', 'string', 'max:100'], 'filter.from' => ['sometimes', 'date_format:Y-m-d'], 'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $f = (array) $request->input('filter', []);

        $page = StockDocument::query()
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('doc_type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('posting_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('posting_date', '<=', $v))
            ->when($f['q'] ?? null, function ($q, $v) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(document_no) like ?', [$term])->orWhereRaw("lower(coalesce(reference, '')) like ?", [$term]));
            })
            ->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->headers($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(StockDocument $document, DocumentPresenter $presenter): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json(['data' => $presenter->full($document)]);
    }

    /** Posts at once (receipt, issue, transfer) or, for an adjustment under an approval policy, answers 202 with the request. */
    public function store(PostStockDocumentRequest $request, MovementInput $input, PostStockMovement $post, DocumentPresenter $presenter): JsonResponse
    {
        $outcome = $post($input->movement($request->validated(), $request->user()->id), $request->user()->id, 'api', $request->header('Idempotency-Key'));

        if (! $outcome->isPosted()) {
            return response()->json(['data' => ['approval_request_id' => $outcome->approvalRequestId, 'document_no' => $outcome->approvalDocumentNo, 'status' => 'pending']], 202);
        }

        $document = StockDocument::query()->findOrFail($outcome->posted->documentId);

        return response()->json(['data' => $presenter->full($document)], 201)->header('Location', route('api.v1.stock-documents.show', $document));
    }

    public function reverse(Request $request, StockDocument $document, StockPoster $poster, DocumentPresenter $presenter): JsonResponse
    {
        $this->authorize('reverse', $document);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $reversal = $poster->reverse($document->id, $data['note'] ?? null, $request->user()->id);

        return response()->json(['data' => $presenter->full(StockDocument::query()->findOrFail($reversal->documentId))], 201);
    }
}
