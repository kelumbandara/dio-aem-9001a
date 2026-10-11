<?php

namespace App\Modules\Sales\Http\Controllers\Api;

use App\Modules\Sales\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Sales\Models\CreditNote;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Deliveries, invoices and credit notes: read, plus the follow-up actions that belong to each. */
class DocumentController extends Controller
{
    use NeedsPermission;

    public function deliveries(Request $request, SalesPresenter $presenter): JsonResponse
    {
        return $this->list($request, $presenter, Delivery::query());
    }

    public function delivery(Request $request, Delivery $delivery, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.view');

        return response()->json(['data' => $presenter->delivery($delivery)]);
    }

    public function reverseDelivery(Request $request, Delivery $delivery, DeliveryService $service, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.reverse');
        $this->need($request, 'sales.delivery.post');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $presenter->delivery($service->reverse($delivery, $data['note'] ?? null, $request->user()->id))]);
    }

    public function invoices(Request $request, SalesPresenter $presenter): JsonResponse
    {
        return $this->list($request, $presenter, Invoice::query());
    }

    public function invoice(Request $request, Invoice $invoice, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.view');

        return response()->json(['data' => $presenter->invoicePrint($invoice)]);
    }

    public function creditNote(Request $request, Invoice $invoice, SalesCreditNoteService $service, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.credit_note.post');
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'], 'note_date' => ['nullable', 'date_format:Y-m-d'], 'restock' => ['nullable', 'boolean'], 'warehouse' => ['nullable', 'string', 'max:20'],
            'lines' => ['nullable', 'array', 'max:200'], 'lines.*.line' => ['required', 'integer', 'min:1'], 'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);
        $note = $service->create($invoice, $data, $request->user()->id);

        return response()->json(['data' => $presenter->creditNote($note)], 201)->header('Location', route('api.v1.credit-notes.show', $note));
    }

    public function creditNotes(Request $request, SalesPresenter $presenter): JsonResponse
    {
        return $this->list($request, $presenter, CreditNote::query());
    }

    public function showCreditNote(Request $request, CreditNote $creditNote, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.view');

        return response()->json(['data' => $presenter->creditNote($creditNote)]);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<*>  $query */
    private function list(Request $request, SalesPresenter $presenter, $query): JsonResponse
    {
        $this->need($request, 'sales.view');
        $request->validate(['filter.q' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = $this->searchNo($query, (string) $request->input('filter.q', ''))->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->headers($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
