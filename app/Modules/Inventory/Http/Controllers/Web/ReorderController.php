<?php

namespace App\Modules\Inventory\Http\Controllers\Web;

use App\Modules\Inventory\Actions\SaveReorderRule;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockQueries;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReorderController extends Controller
{
    use ReportsBusinessErrors;

    public function __construct(private readonly ItemCatalog $items) {}

    public function index(Request $request, StockQueries $queries): View
    {
        $this->authorize('viewAny', StockDocument::class);
        $rules = ReorderRule::query()->with('warehouse')->orderBy('item_id')->get();
        $info = $this->items->findMany($rules->pluck('item_id')->all());

        return view('inventory::reorder.index', [
            'below' => $queries->belowReorder(),
            'rules' => $rules->map(fn (ReorderRule $r) => ['model' => $r, 'code' => $info[$r->item_id]->code ?? '?', 'name' => $info[$r->item_id]->name ?? '', 'uom' => $info[$r->item_id]->baseUomCode ?? ''])->all(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'canManage' => $request->user()->can('inventory.reorder.manage'),
        ]);
    }

    public function store(Request $request, SaveReorderRule $save): RedirectResponse
    {
        abort_unless($request->user()->can('inventory.reorder.manage'), 403);
        $data = $request->validate([
            'item' => ['required', 'string', 'max:40'], 'warehouse' => ['nullable', 'string', 'max:20'],
            'min_qty' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'], 'reorder_qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);

        return $this->attempt(function () use ($data, $save) {
            $item = $this->items->findByCode(strtoupper($data['item'])) ?? throw InventoryException::make('inventory.unknown_code', 'Unknown item code.', 422, 'item');
            $warehouseId = null;
            if (! empty($data['warehouse'])) {
                $warehouseId = Warehouse::query()->where('code', strtoupper($data['warehouse']))->value('id') ?? throw InventoryException::make('inventory.unknown_code', 'Unknown warehouse code.', 422, 'warehouse');
            }
            $save(['item_id' => $item->id, 'warehouse_id' => $warehouseId, 'min_qty' => $data['min_qty'], 'reorder_qty' => $data['reorder_qty'] ?? null]);

            return redirect()->route('inventory.reorder.index')->with('status', __('inventory::ui.flash.saved'));
        });
    }

    public function update(Request $request, ReorderRule $rule, SaveReorderRule $save): RedirectResponse
    {
        abort_unless($request->user()->can('inventory.reorder.manage'), 403);
        $data = $request->validate([
            'min_qty' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'], 'reorder_qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $this->attempt(function () use ($rule, $save, $data) {
            $save($data, $rule);

            return redirect()->route('inventory.reorder.index')->with('status', __('inventory::ui.flash.saved'));
        });
    }
}
