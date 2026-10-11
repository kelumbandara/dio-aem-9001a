<?php

namespace App\Modules\Inventory\Http\Controllers\Web;

use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockQueries;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Stock on hand, an item's stock card, the ledger, lots with expiry. */
class StockController extends Controller
{
    public function __construct(private readonly StockQueries $queries, private readonly ItemCatalog $items) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', StockDocument::class);
        $warehouse = $request->query('warehouse') ? Warehouse::query()->where('code', strtoupper((string) $request->query('warehouse')))->first() : null;

        return view('inventory::stock.index', [
            'rows' => $this->queries->summary(['q' => $request->query('q'), 'warehouse_id' => $warehouse?->id, 'below_reorder' => $request->boolean('below')]),
            'warehouses' => Warehouse::query()->orderBy('code')->get(['code', 'name']),
            'q' => (string) $request->query('q', ''), 'warehouse' => $warehouse?->code, 'below' => $request->boolean('below'),
        ]);
    }

    public function item(Request $request, string $code): View
    {
        $this->authorize('viewAny', StockDocument::class);
        $item = $this->items->findByCode(strtoupper($code)) ?? abort(404);
        $level = app(StockAvailability::class);

        return view('inventory::stock.item', [
            'item' => $item, 'balances' => $this->queries->balances($item->id),
            'level' => $level->level($item->id), 'avg' => $level->averageCost($item->id),
            'ledger' => $this->queries->ledger(['item_id' => $item->id], 25),
        ]);
    }

    public function ledger(Request $request): View
    {
        $this->authorize('viewAny', StockDocument::class);
        $request->validate(['item' => ['sometimes', 'nullable', 'string', 'max:40'], 'warehouse' => ['sometimes', 'nullable', 'string', 'max:20'], 'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $item = $request->query('item') ? $this->items->findByCode(strtoupper((string) $request->query('item'))) : null;
        $warehouse = $request->query('warehouse') ? Warehouse::query()->where('code', strtoupper((string) $request->query('warehouse')))->first() : null;

        return view('inventory::stock.ledger', [
            'rows' => $this->queries->ledger([
                'item_id' => $item->id ?? ($request->query('item') ? -1 : null), 'warehouse_id' => $warehouse->id ?? ($request->query('warehouse') ? -1 : null),
                'from' => $request->query('from'), 'to' => $request->query('to'),
            ]),
            'warehouses' => Warehouse::query()->orderBy('code')->get(['code', 'name']),
            'f' => $request->only(['item', 'warehouse', 'from', 'to']),
        ]);
    }

    public function lots(Request $request): View
    {
        $this->authorize('viewAny', StockDocument::class);
        $days = $request->query('days');

        return view('inventory::stock.lots', [
            'rows' => $this->queries->lots(is_numeric($days) ? ['expiring_within_days' => (int) $days] : []),
            'days' => is_numeric($days) ? (int) $days : null,
        ]);
    }
}
