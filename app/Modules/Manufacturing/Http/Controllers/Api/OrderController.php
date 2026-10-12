<?php

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Services\ManufacturingPresenter;
use App\Modules\Manufacturing\Services\MaterialService;
use App\Modules\Manufacturing\Services\ProductionOrderService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use NeedsPermission;

    private const QTY = ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'];

    public function index(Request $request, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.view');
        $request->validate(['filter.status' => ['sometimes', 'in:'.implode(',', ProductionOrder::STATUSES)], 'filter.q' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $f = (array) $request->input('filter', []);
        $page = $this->searchNo(ProductionOrder::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v)), (string) ($f['q'] ?? ''), 'order_no')
            ->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->orderHeaders($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, ProductionOrder $order, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.view');

        return response()->json(['data' => $presenter->order($order)]);
    }

    public function store(Request $request, ProductionOrderService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.order.manage');
        $order = $service->save($this->header($request, true), null, $request->user()->id);

        return response()->json(['data' => $presenter->order($order)], 201)->header('Location', route('api.v1.production-orders.show', $order));
    }

    public function update(Request $request, ProductionOrder $order, ProductionOrderService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.order.manage');

        return response()->json(['data' => $presenter->order($service->save($this->header($request, false), $order, $request->user()->id))]);
    }

    /** Releases the order; `shortages` lists materials the source warehouse cannot cover yet (not a refusal). */
    public function release(Request $request, ProductionOrder $order, ProductionOrderService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.order.release');
        $result = $service->release($order, $request->user()->id);

        return response()->json(['data' => $presenter->order($result['order']), 'meta' => ['shortages' => $result['shortages']]]);
    }

    public function cancel(Request $request, ProductionOrder $order, ProductionOrderService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.order.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $presenter->order($service->cancel($order, $data['reason'], $request->user()->id))]);
    }

    public function issue(Request $request, ProductionOrder $order, MaterialService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.issue.post');
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.material' => ['required_without:lines.*.item', 'nullable', 'integer', 'min:1'], 'lines.*.item' => ['required_without:lines.*.material', 'nullable', 'string', 'max:60'],
            'lines.*.qty' => self::QTY, 'lines.*.lot_no' => ['nullable', 'string', 'max:60'],
        ]);
        $result = $service->issue($order, $data['lines'], $request->user()->id, $request->user()->can('manufacturing.issue.over'), $data['reason'] ?? null, $data['date'] ?? null);

        return response()->json(['data' => $presenter->order($order->refresh()), 'meta' => ['stock_document' => $result['document_no']]], 201);
    }

    public function return(Request $request, ProductionOrder $order, MaterialService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.issue.post');
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.material' => ['required_without:lines.*.item', 'nullable', 'integer', 'min:1'], 'lines.*.item' => ['required_without:lines.*.material', 'nullable', 'string', 'max:60'],
            'lines.*.qty' => self::QTY, 'lines.*.lot_no' => ['nullable', 'string', 'max:60'],
        ]);
        $result = $service->return($order, $data['lines'], $request->user()->id, $data['reason'], $data['date'] ?? null);

        return response()->json(['data' => $presenter->order($order->refresh()), 'meta' => ['stock_document' => $result['document_no']]], 201);
    }

    /** @return array<string, mixed> */
    private function header(Request $request, bool $creating): array
    {
        return $request->validate([
            'item' => [$creating ? 'required' : 'prohibited', 'string', 'max:60'], 'qty' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'planned_start' => ['nullable', 'date_format:Y-m-d'], 'planned_end' => ['nullable', 'date_format:Y-m-d'],
            'source_warehouse' => ['required', 'string', 'max:30'], 'output_warehouse' => ['required', 'string', 'max:30'], 'line' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'], 'version' => ['nullable', 'integer'],
        ]);
    }
}
