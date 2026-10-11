<?php

namespace App\Modules\Sales\Http\Controllers\Api;

use App\Modules\Sales\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Sales\Http\Requests\OrderRequest;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\SalesPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use NeedsPermission;

    private const LINES = ['lines' => ['nullable', 'array', 'max:200'], 'lines.*.line' => ['required', 'integer', 'min:1'], 'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/']];

    public function index(Request $request, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.view');
        $request->validate(['filter.status' => ['sometimes', 'in:'.implode(',', Order::STATUSES)], 'filter.q' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $f = (array) $request->input('filter', []);
        $page = $this->searchNo(Order::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v)), (string) ($f['q'] ?? ''))
            ->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->headers($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Order $order, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.view');

        return response()->json(['data' => $presenter->order($order)]);
    }

    public function store(OrderRequest $request, OrderService $service, SalesPresenter $presenter): JsonResponse
    {
        $order = $service->save($request->validated(), null, $request->user()->id);

        return response()->json(['data' => $presenter->order($order)], 201)->header('Location', route('api.v1.orders.show', $order));
    }

    public function update(OrderRequest $request, Order $order, OrderService $service, SalesPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->order($service->save($request->validated(), $order, $request->user()->id))]);
    }

    /** Credit check + stock reservation. Over the customer's limit it answers 422 sales.credit_limit_exceeded unless the caller may override and gives a reason. */
    public function confirm(Request $request, Order $order, OrderService $service, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.order.confirm');
        $data = $request->validate(['override_reason' => ['nullable', 'string', 'max:255']]);
        $order = $service->confirm($order, $request->user()->id, $request->user()->can('sales.credit.override'), $data['override_reason'] ?? null);

        return response()->json(['data' => $presenter->order($order)]);
    }

    public function cancel(Request $request, Order $order, OrderService $service, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.order.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $presenter->order($service->cancel($order, $data['reason'], $request->user()->id))]);
    }

    public function deliver(Request $request, Order $order, DeliveryService $service, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.delivery.post');
        $data = $request->validate(['delivery_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000']] + self::LINES);
        $delivery = $service->post($order, $data, $request->user()->id);

        return response()->json(['data' => $presenter->delivery($delivery)], 201)->header('Location', route('api.v1.deliveries.show', $delivery));
    }

    public function invoice(Request $request, Order $order, InvoiceService $service, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.invoice.post');
        $data = $request->validate(['invoice_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000']] + self::LINES);
        $invoice = $service->create($order, $data, $request->user()->id);

        return response()->json(['data' => $presenter->invoice($invoice)], 201)->header('Location', route('api.v1.invoices.show', $invoice));
    }
}
