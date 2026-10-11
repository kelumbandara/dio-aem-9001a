<?php

namespace App\Modules\Sales\Http\Controllers\Web;

use App\Modules\Sales\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Sales\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Sales\Http\Requests\OrderRequest;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\FormOptions;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\SalesPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    use NeedsPermission, ReportsBusinessErrors;

    public function index(Request $request, SalesPresenter $presenter): View
    {
        $this->need($request, 'sales.view');
        $status = in_array($request->query('status'), Order::STATUSES, true) ? $request->query('status') : null;
        $q = trim((string) $request->query('q', ''));
        $page = $this->searchNo(Order::query()->when($status, fn ($query) => $query->where('status', $status)), $q)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('sales::docs.index', [
            'kind' => 'orders', 'page' => $page, 'rows' => $presenter->headers($page->getCollection()), 'statuses' => Order::STATUSES, 'status' => $status, 'q' => $q,
            'create' => $request->user()->can('sales.order.manage') ? route('sales.orders.create') : null,
        ]);
    }

    public function create(Request $request, FormOptions $options): View
    {
        $this->need($request, 'sales.order.manage');

        return view('sales::docs.form', ['kind' => 'order', 'doc' => null, 'options' => $options->all()]);
    }

    public function store(OrderRequest $request, OrderService $service): RedirectResponse
    {
        return $this->attempt(function () use ($request, $service) {
            $order = $service->save($request->validated(), null, $request->user()->id);

            return redirect()->route('sales.orders.show', $order)->with('status', __('sales::ui.flash.saved', ['document' => $order->document_no]));
        });
    }

    public function show(Request $request, Order $order, SalesPresenter $presenter): View
    {
        $this->need($request, 'sales.view');

        return view('sales::orders.show', ['doc' => $presenter->order($order)]);
    }

    public function edit(Request $request, Order $order, SalesPresenter $presenter, FormOptions $options): View
    {
        $this->need($request, 'sales.order.manage');
        abort_unless($order->status === 'draft', 409);

        return view('sales::docs.form', ['kind' => 'order', 'doc' => $presenter->order($order), 'options' => $options->all()]);
    }

    public function update(OrderRequest $request, Order $order, OrderService $service): RedirectResponse
    {
        return $this->attempt(function () use ($request, $order, $service) {
            $service->save($request->validated(), $order, $request->user()->id);

            return redirect()->route('sales.orders.show', $order)->with('status', __('sales::ui.flash.saved', ['document' => $order->document_no]));
        });
    }

    public function confirm(Request $request, Order $order, OrderService $service): RedirectResponse
    {
        $this->need($request, 'sales.order.confirm');
        $data = $request->validate(['override_reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $service->confirm($order, $request->user()->id, $request->user()->can('sales.credit.override'), $data['override_reason'] ?? null);

            return redirect()->route('sales.orders.show', $order)->with('status', __('sales::ui.flash.confirmed', ['document' => $order->document_no]));
        });
    }

    public function cancel(Request $request, Order $order, OrderService $service): RedirectResponse
    {
        $this->need($request, 'sales.order.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $service->cancel($order, $data['reason'], $request->user()->id);

            return redirect()->route('sales.orders.show', $order)->with('status', __('sales::ui.flash.cancelled', ['document' => $order->document_no]));
        });
    }

    public function deliver(Request $request, Order $order, DeliveryService $service): RedirectResponse
    {
        $this->need($request, 'sales.delivery.post');
        $data = $request->validate([
            'delivery_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array'], 'lines.*.line' => ['required', 'integer'], 'lines.*.qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);
        $data['lines'] = $this->filled($data['lines']);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $delivery = $service->post($order, $data, $request->user()->id);

            return redirect()->route('sales.deliveries.show', $delivery)->with('status', __('sales::ui.flash.delivered', ['document' => $delivery->document_no]));
        });
    }

    public function invoice(Request $request, Order $order, InvoiceService $service): RedirectResponse
    {
        $this->need($request, 'sales.invoice.post');
        $data = $request->validate([
            'invoice_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array'], 'lines.*.line' => ['required', 'integer'], 'lines.*.qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);
        $data['lines'] = $this->filled($data['lines']);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $invoice = $service->create($order, $data, $request->user()->id);

            return redirect()->route('sales.invoices.show', $invoice)->with('status', __('sales::ui.flash.invoiced', ['document' => $invoice->document_no]));
        });
    }

    /**
     * Rows with a quantity; an empty list means "nothing chosen", never "everything".
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function filled(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => trim((string) ($r['qty'] ?? '')) !== '' && (float) $r['qty'] > 0));
        if ($rows === []) {
            abort(back()->withInput()->withErrors(['lines' => __('sales::ui.errors.pick_lines')]));
        }

        return $rows;
    }
}
