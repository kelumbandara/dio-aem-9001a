<?php

namespace App\Modules\Sales\Http\Controllers\Web;

use App\Modules\Sales\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Sales\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Sales\Models\CreditNote;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\FormOptions;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Deliveries, invoices and credit notes. */
class DocumentController extends Controller
{
    use NeedsPermission, ReportsBusinessErrors;

    public function deliveries(Request $request, SalesPresenter $presenter): View
    {
        return $this->list($request, $presenter, 'deliveries', Delivery::query());
    }

    public function invoices(Request $request, SalesPresenter $presenter): View
    {
        return $this->list($request, $presenter, 'invoices', Invoice::query());
    }

    public function creditNotes(Request $request, SalesPresenter $presenter): View
    {
        return $this->list($request, $presenter, 'credit-notes', CreditNote::query());
    }

    public function delivery(Request $request, Delivery $delivery, SalesPresenter $presenter): View
    {
        $this->need($request, 'sales.view');

        return view('sales::deliveries.show', ['doc' => $presenter->delivery($delivery)]);
    }

    public function reverseDelivery(Request $request, Delivery $delivery, DeliveryService $service): RedirectResponse
    {
        $this->need($request, 'sales.reverse');
        $this->need($request, 'sales.delivery.post');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $delivery, $service, $data) {
            $service->reverse($delivery, $data['note'] ?? null, $request->user()->id);

            return redirect()->route('sales.deliveries.show', $delivery)->with('status', __('sales::ui.flash.delivery_reversed', ['document' => $delivery->document_no]));
        });
    }

    public function invoice(Request $request, Invoice $invoice, SalesPresenter $presenter, FormOptions $options): View
    {
        $this->need($request, 'sales.view');

        return view('sales::invoices.show', ['doc' => $presenter->invoice($invoice), 'warehouses' => $options->all()['warehouses']]);
    }

    public function print(Request $request, Invoice $invoice, SalesPresenter $presenter): View
    {
        $this->need($request, 'sales.view');

        return view('sales::invoices.print', ['doc' => $presenter->invoicePrint($invoice)]);
    }

    public function creditNote(Request $request, Invoice $invoice, SalesCreditNoteService $service): RedirectResponse
    {
        $this->need($request, 'sales.credit_note.post');
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'], 'note_date' => ['nullable', 'date_format:Y-m-d'], 'restock' => ['nullable', 'boolean'], 'warehouse' => ['nullable', 'string', 'max:20'],
            'lines' => ['required', 'array'], 'lines.*.line' => ['required', 'integer'], 'lines.*.qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);
        $data['lines'] = array_values(array_filter($data['lines'], fn ($r) => trim((string) ($r['qty'] ?? '')) !== '' && (float) $r['qty'] > 0));
        if ($data['lines'] === []) {
            return back()->withInput()->withErrors(['lines' => __('sales::ui.errors.pick_lines')]);
        }

        return $this->attempt(function () use ($request, $invoice, $service, $data) {
            $note = $service->create($invoice, $data, $request->user()->id);

            return redirect()->route('sales.credit-notes.show', $note)->with('status', __('sales::ui.flash.credited', ['document' => $note->document_no]));
        });
    }

    public function showCreditNote(Request $request, CreditNote $creditNote, SalesPresenter $presenter): View
    {
        $this->need($request, 'sales.view');

        return view('sales::credit-notes.show', ['doc' => $presenter->creditNote($creditNote)]);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<*>  $query */
    private function list(Request $request, SalesPresenter $presenter, string $kind, $query): View
    {
        $this->need($request, 'sales.view');
        $q = trim((string) $request->query('q', ''));
        $page = $this->searchNo($query, $q)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('sales::docs.index', ['kind' => $kind, 'page' => $page, 'rows' => $presenter->headers($page->getCollection()), 'statuses' => [], 'status' => null, 'q' => $q, 'create' => null]);
    }
}
