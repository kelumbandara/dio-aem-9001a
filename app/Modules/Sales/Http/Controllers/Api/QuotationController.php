<?php

namespace App\Modules\Sales\Http\Controllers\Api;

use App\Modules\Sales\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Sales\Http\Requests\QuotationRequest;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesPresenter;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotationController extends Controller
{
    use NeedsPermission;

    public function index(Request $request, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.view');
        $request->validate(['filter.status' => ['sometimes', 'in:'.implode(',', Quotation::STATUSES)], 'filter.q' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $f = (array) $request->input('filter', []);
        $page = $this->searchNo(Quotation::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v)), (string) ($f['q'] ?? ''))
            ->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->headers($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Quotation $quotation, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.view');

        return response()->json(['data' => $presenter->quotation($quotation)]);
    }

    public function store(QuotationRequest $request, QuotationService $service, SalesPresenter $presenter): JsonResponse
    {
        $quotation = $service->save($request->validated(), null, $request->user()->id);

        return response()->json(['data' => $presenter->quotation($quotation)], 201)->header('Location', route('api.v1.quotations.show', $quotation));
    }

    public function update(QuotationRequest $request, Quotation $quotation, QuotationService $service, SalesPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->quotation($service->save($request->validated(), $quotation, $request->user()->id))]);
    }

    public function transition(Request $request, Quotation $quotation, string $to, QuotationService $service, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.quotation.manage');
        abort_unless(in_array($to, ['sent', 'accepted', 'rejected', 'cancelled'], true), 404);

        return response()->json(['data' => $presenter->quotation($service->transition($quotation, $to, $request->user()->id))]);
    }

    /** Turns an accepted quotation into a draft order with the quoted prices. */
    public function convert(Request $request, Quotation $quotation, OrderService $orders, SalesPresenter $presenter): JsonResponse
    {
        $this->need($request, 'sales.order.manage');
        $data = $request->validate([
            'warehouse' => ['required', 'string', 'max:20'], 'order_date' => ['nullable', 'date_format:Y-m-d'], 'required_date' => ['nullable', 'date_format:Y-m-d'],
            'customer_ref' => ['nullable', 'string', 'max:100'], 'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = $orders->fromQuotation($quotation, $data, $request->user()->id);

        return response()->json(['data' => $presenter->order($order)], 201)->header('Location', route('api.v1.orders.show', $order));
    }
}
