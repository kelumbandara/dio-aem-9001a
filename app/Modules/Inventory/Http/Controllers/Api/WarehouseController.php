<?php

namespace App\Modules\Inventory\Http\Controllers\Api;

use App\Modules\Core\Contracts\BranchDirectory;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveWarehouse;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Http\Requests\SaveWarehouseRequest;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request, BranchDirectory $branches): JsonResponse
    {
        $this->authorize('viewAny', Warehouse::class);
        $request->validate(['filter.is_active' => ['sometimes', 'in:true,false,1,0']]);

        $page = Warehouse::query()->with('locations')
            ->when($request->has('filter.is_active'), fn ($q) => $q->where('is_active', filter_var($request->input('filter.is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('code')->paginate(100)->withQueryString();
        $labels = $branches->labels($page->getCollection()->pluck('branch_id')->filter()->all());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Warehouse $w) => $this->present($w, $labels))->values(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Warehouse $warehouse, BranchDirectory $branches): JsonResponse
    {
        $this->authorize('view', $warehouse);

        return response()->json(['data' => $this->present($warehouse->load('locations'), $branches->labels([(int) $warehouse->branch_id]))]);
    }

    public function store(SaveWarehouseRequest $request, SaveWarehouse $save, BranchDirectory $branches): JsonResponse
    {
        $warehouse = $save($this->data($request, $branches));

        return response()->json(['data' => $this->present($warehouse->load('locations'), $branches->labels([(int) $warehouse->branch_id]))], 201)
            ->header('Location', route('api.v1.warehouses.show', $warehouse));
    }

    public function update(SaveWarehouseRequest $request, Warehouse $warehouse, SaveWarehouse $save, BranchDirectory $branches): JsonResponse
    {
        $updated = $save($this->data($request, $branches), $warehouse, (int) $request->validated('version'));

        return response()->json(['data' => $this->present($updated->load('locations'), $branches->labels([(int) $updated->branch_id]))]);
    }

    public function addLocation(Request $request, Warehouse $warehouse, SaveLocation $save): JsonResponse
    {
        $this->authorize('update', $warehouse);
        $data = $request->validate(['code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._\\-]+$/'], 'name' => ['required', 'string', 'max:150']]);
        $save($warehouse, $data);

        return response()->json(['data' => $this->present($warehouse->refresh()->load('locations'), [])], 201);
    }

    public function updateLocation(Request $request, Warehouse $warehouse, Location $location, SaveLocation $save): JsonResponse
    {
        $this->authorize('update', $warehouse);
        abort_unless($location->warehouse_id === $warehouse->id, 404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'is_active' => ['sometimes', 'boolean']]);
        $save($warehouse, $data, $location);

        return response()->json(['data' => $this->present($warehouse->refresh()->load('locations'), [])]);
    }

    /** @return array<string, mixed> */
    private function data(SaveWarehouseRequest $request, BranchDirectory $branches): array
    {
        $data = $request->validated();
        if (array_key_exists('branch', $data)) {
            $data['branch_id'] = $data['branch'] === null ? null
                : ($branches->idByCode($data['branch']) ?? throw InventoryException::make('inventory.unknown_code', 'Unknown or inactive branch code.', 422, 'branch'));
            unset($data['branch']);
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $branchLabels
     * @return array<string, mixed>
     */
    private function present(Warehouse $w, array $branchLabels): array
    {
        return [
            'id' => $w->public_id, 'code' => $w->code, 'name' => $w->name, 'branch' => $w->branch_id !== null ? ($branchLabels[$w->branch_id] ?? null) : null,
            'uses_locations' => $w->uses_locations, 'notes' => $w->notes, 'is_active' => $w->is_active, 'version' => $w->version,
            'locations' => $w->locations->map(fn (Location $l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'is_default' => $l->is_default, 'is_active' => $l->is_active])->values(),
        ];
    }
}
