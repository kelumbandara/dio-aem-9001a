<?php

namespace App\Modules\Procurement\Http\Controllers\Api;

use App\Modules\Procurement\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Procurement\Http\Requests\PurchaseOrderRequest;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchasePresenter;
use App\Modules\Procurement\Services\PurchaseReturnService;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use NeedsPermission;

    private const QTY = ['regex:/^\d{1,12}(\.\d{1,6})?$/'];

    public function index(Request $request, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.view');
        $request->validate(['filter.status' => ['sometimes', 'in:'.implode(',', PurchaseOrder::STATUSES)], 'filter.q' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $f = (array) $request->input('filter', []);
        $page = $this->searchNo(PurchaseOrder::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v)), (string) ($f['q'] ?? ''))
            ->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->headers($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, PurchaseOrder $order, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.view');

        return response()->json(['data' => $presenter->order($order)]);
    }

    public function store(PurchaseOrderRequest $request, PurchaseOrderService $service, PurchasePresenter $presenter): JsonResponse
    {
        $order = $service->save($request->validated(), null, $request->user()->id);

        return response()->json(['data' => $presenter->order($order)], 201)->header('Location', route('api.v1.purchase-orders.show', $order));
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $order, PurchaseOrderService $service, PurchasePresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->order($service->save($request->validated(), $order, $request->user()->id))]);
    }

    /** Approved at once, or 202-style `pending_approval` when an approval policy applies. */
    public function submit(Request $request, PurchaseOrder $order, PurchaseOrderService $service, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.order.manage');

        return response()->json(['data' => $presenter->order($service->submit($order, $request->user()->id, 'api', $request->header('Idempotency-Key')))]);
    }

    public function withdraw(Request $request, PurchaseOrder $order, PurchaseOrderService $service, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.order.manage');

        return response()->json(['data' => $presenter->order($service->withdraw($order, $request->user()->id))]);
    }

    public function cancel(Request $request, PurchaseOrder $order, PurchaseOrderService $service, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.order.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $presenter->order($service->cancel($order, $data['reason'], $request->user()->id))]);
    }

    public function receive(Request $request, PurchaseOrder $order, GoodsReceiptService $service, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.receipt.post');
        $data = $request->validate([
            'receipt_date' => ['nullable', 'date_format:Y-m-d'], 'supplier_dn_ref' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array', 'max:200'], 'lines.*.line' => ['required', 'integer', 'min:1'], 'lines.*.qty' => ['required', 'string', ...self::QTY],
            'lines.*.lot' => ['nullable', 'string', 'max:60'], 'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $receipt = $service->post($order, $data, $request->user()->id);

        return response()->json(['data' => $presenter->receipt($receipt)], 201)->header('Location', route('api.v1.goods-receipts.show', $receipt));
    }

    public function bill(Request $request, PurchaseOrder $order, SupplierBillService $service, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.bill.post');
        $data = $request->validate([
            'supplier_invoice_no' => ['required', 'string', 'max:60'], 'bill_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'], 'override_reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['nullable', 'array', 'max:200'], 'lines.*.line' => ['required', 'integer', 'min:1'], 'lines.*.qty' => ['required', 'string', ...self::QTY],
            'lines.*.unit_price' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
        ]);
        $bill = $service->create($order, $data, $request->user()->id, $request->user()->can('procurement.bill.override'));

        return response()->json(['data' => $presenter->bill($bill)], 201)->header('Location', route('api.v1.supplier-bills.show', $bill));
    }

    public function sendBack(Request $request, PurchaseOrder $order, PurchaseReturnService $service, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.return.post');
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'], 'return_date' => ['nullable', 'date_format:Y-m-d'], 'bill' => ['nullable', 'string', 'max:60'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.line' => ['required', 'integer', 'min:1'], 'lines.*.qty' => ['required', 'string', ...self::QTY],
        ]);
        $return = $service->create($order, $data, $request->user()->id);

        return response()->json(['data' => $presenter->purchaseReturn($return)], 201)->header('Location', route('api.v1.purchase-returns.show', $return));
    }
}
