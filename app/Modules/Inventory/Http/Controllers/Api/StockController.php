<?php

namespace App\Modules\Inventory\Http\Controllers\Api;

use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockQueries;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** Read-only views of stock: levels per item, where an item is, the ledger, lots, and what is below its minimum. */
class StockController extends Controller
{
    public function __construct(private readonly StockQueries $queries, private readonly ItemCatalog $items) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockDocument::class);
        $request->validate(['filter.q' => ['sometimes', 'string', 'max:100'], 'filter.warehouse' => ['sometimes', 'string', 'max:20'], 'filter.below_reorder' => ['sometimes', 'in:true,false,1,0'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return $this->paged($this->queries->summary([
            'q' => $request->input('filter.q'),
            'warehouse_id' => $this->warehouseId($request->input('filter.warehouse')),
            'below_reorder' => filter_var($request->input('filter.below_reorder'), FILTER_VALIDATE_BOOLEAN),
        ], (int) $request->query('per_page', 25)));
    }

    public function item(string $code): JsonResponse
    {
        $this->authorize('viewAny', StockDocument::class);
        $item = $this->items->findByCode(strtoupper($code)) ?? abort(404);

        return response()->json(['data' => ['item_code' => $item->code, 'item_name' => $item->name, 'uom' => $item->baseUomCode, 'balances' => $this->queries->balances($item->id)]]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockDocument::class);
        $request->validate([
            'filter.item' => ['sometimes', 'string', 'max:40'], 'filter.warehouse' => ['sometimes', 'string', 'max:20'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'], 'filter.to' => ['sometimes', 'date_format:Y-m-d'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $item = $request->input('filter.item') ? ($this->items->findByCode(strtoupper((string) $request->input('filter.item'))) ?? abort(404)) : null;

        return $this->paged($this->queries->ledger([
            'item_id' => $item?->id, 'warehouse_id' => $this->warehouseId($request->input('filter.warehouse')),
            'from' => $request->input('filter.from'), 'to' => $request->input('filter.to'),
        ], (int) $request->query('per_page', 50)));
    }

    public function lots(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockDocument::class);
        $request->validate(['filter.item' => ['sometimes', 'string', 'max:40'], 'filter.expiring_within_days' => ['sometimes', 'integer', 'min:0', 'max:3650']]);
        $item = $request->input('filter.item') ? ($this->items->findByCode(strtoupper((string) $request->input('filter.item'))) ?? abort(404)) : null;

        return $this->paged($this->queries->lots([
            'item_id' => $item?->id,
            ...($request->has('filter.expiring_within_days') ? ['expiring_within_days' => (int) $request->input('filter.expiring_within_days')] : []),
        ]));
    }

    public function belowReorder(): JsonResponse
    {
        $this->authorize('viewAny', StockDocument::class);

        return response()->json(['data' => $this->queries->belowReorder()]);
    }

    private function warehouseId(mixed $code): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        return Warehouse::query()->where('code', strtoupper((string) $code))->value('id') ?? abort(404);
    }

    /** @param  LengthAwarePaginator<int|string, mixed>  $page */
    private function paged(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json([
            'data' => $page->getCollection()->values(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
