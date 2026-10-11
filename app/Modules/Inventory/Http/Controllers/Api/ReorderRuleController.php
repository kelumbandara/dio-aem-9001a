<?php

namespace App\Modules\Inventory\Http\Controllers\Api;

use App\Modules\Inventory\Actions\SaveReorderRule;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReorderRuleController extends Controller
{
    public function __construct(private readonly ItemCatalog $items) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', StockDocument::class);
        $rules = ReorderRule::query()->with('warehouse')->orderBy('item_id')->get();
        $info = $this->items->findMany($rules->pluck('item_id')->all());

        return response()->json(['data' => $rules->map(fn (ReorderRule $r) => $this->present($r, $info[$r->item_id]->code ?? null, $info[$r->item_id]->name ?? null))->values()]);
    }

    public function store(Request $request, SaveReorderRule $save): JsonResponse
    {
        abort_unless($request->user()->can('inventory.reorder.manage'), 403);
        $data = $request->validate([
            'item' => ['required', 'string', 'max:40'], 'warehouse' => ['nullable', 'string', 'max:20'],
            'min_qty' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'], 'reorder_qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);

        $item = $this->items->findByCode(strtoupper($data['item'])) ?? throw InventoryException::make('inventory.unknown_code', 'Unknown item code.', 422, 'item');
        $warehouseId = null;
        if (! empty($data['warehouse'])) {
            $warehouseId = Warehouse::query()->where('code', strtoupper($data['warehouse']))->value('id') ?? throw InventoryException::make('inventory.unknown_code', 'Unknown warehouse code.', 422, 'warehouse');
        }
        $rule = $save(['item_id' => $item->id, 'warehouse_id' => $warehouseId, 'min_qty' => $data['min_qty'], 'reorder_qty' => $data['reorder_qty'] ?? null]);

        return response()->json(['data' => $this->present($rule->load('warehouse'), $item->code, $item->name)], 201);
    }

    public function update(Request $request, ReorderRule $rule, SaveReorderRule $save): JsonResponse
    {
        abort_unless($request->user()->can('inventory.reorder.manage'), 403);
        $data = $request->validate([
            'min_qty' => ['sometimes', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'], 'reorder_qty' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $rule = $save($data, $rule);
        $item = $this->items->find($rule->item_id);

        return response()->json(['data' => $this->present($rule->load('warehouse'), $item?->code, $item?->name)]);
    }

    /** @return array<string, mixed> */
    private function present(ReorderRule $r, ?string $code, ?string $name): array
    {
        return [
            'id' => $r->id, 'item_code' => $code, 'item_name' => $name, 'warehouse' => $r->warehouse?->code, 'min_qty' => $r->min_qty,
            'reorder_qty' => $r->reorder_qty, 'is_active' => $r->is_active,
        ];
    }
}
