<?php

namespace App\Modules\Sales\Http\Controllers\Web;

use App\Modules\Sales\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Sales\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Sales\Http\Requests\QuotationRequest;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\FormOptions;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuotationController extends Controller
{
    use NeedsPermission, ReportsBusinessErrors;

    public function index(Request $request, SalesPresenter $presenter): View
    {
        $this->need($request, 'sales.view');
        $status = in_array($request->query('status'), Quotation::STATUSES, true) ? $request->query('status') : null;
        $q = trim((string) $request->query('q', ''));
        $page = $this->searchNo(Quotation::query()->when($status, fn ($query) => $query->where('status', $status)), $q)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('sales::docs.index', [
            'kind' => 'quotations', 'page' => $page, 'rows' => $presenter->headers($page->getCollection()), 'statuses' => Quotation::STATUSES, 'status' => $status, 'q' => $q,
            'create' => $request->user()->can('sales.quotation.manage') ? route('sales.quotations.create') : null,
        ]);
    }

    public function create(Request $request, FormOptions $options): View
    {
        $this->need($request, 'sales.quotation.manage');

        return view('sales::docs.form', ['kind' => 'quotation', 'doc' => null, 'options' => $options->all()]);
    }

    public function store(QuotationRequest $request, QuotationService $service): RedirectResponse
    {
        return $this->attempt(function () use ($request, $service) {
            $quotation = $service->save($request->validated(), null, $request->user()->id);

            return redirect()->route('sales.quotations.show', $quotation)->with('status', __('sales::ui.flash.saved', ['document' => $quotation->document_no]));
        });
    }

    public function show(Request $request, Quotation $quotation, SalesPresenter $presenter, FormOptions $options): View
    {
        $this->need($request, 'sales.view');

        return view('sales::quotations.show', ['doc' => $presenter->quotation($quotation), 'warehouses' => $options->all()['warehouses']]);
    }

    public function edit(Request $request, Quotation $quotation, SalesPresenter $presenter, FormOptions $options): View
    {
        $this->need($request, 'sales.quotation.manage');
        abort_unless($quotation->status === 'draft', 409);

        return view('sales::docs.form', ['kind' => 'quotation', 'doc' => $presenter->quotation($quotation), 'options' => $options->all()]);
    }

    public function update(QuotationRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        return $this->attempt(function () use ($request, $quotation, $service) {
            $service->save($request->validated(), $quotation, $request->user()->id);

            return redirect()->route('sales.quotations.show', $quotation)->with('status', __('sales::ui.flash.saved', ['document' => $quotation->document_no]));
        });
    }

    public function transition(Request $request, Quotation $quotation, string $to, QuotationService $service): RedirectResponse
    {
        $this->need($request, 'sales.quotation.manage');
        abort_unless(in_array($to, ['sent', 'accepted', 'rejected', 'cancelled'], true), 404);

        return $this->attempt(function () use ($request, $quotation, $to, $service) {
            $service->transition($quotation, $to, $request->user()->id);

            return redirect()->route('sales.quotations.show', $quotation)->with('status', __('sales::ui.flash.quotation_'.$to, ['document' => $quotation->document_no]));
        });
    }

    public function convert(Request $request, Quotation $quotation, OrderService $orders): RedirectResponse
    {
        $this->need($request, 'sales.order.manage');
        $data = $request->validate(['warehouse' => ['required', 'string', 'max:20']]);

        return $this->attempt(function () use ($request, $quotation, $orders, $data) {
            $order = $orders->fromQuotation($quotation, $data, $request->user()->id);

            return redirect()->route('sales.orders.show', $order)->with('status', __('sales::ui.flash.converted', ['document' => $order->document_no]));
        });
    }
}
