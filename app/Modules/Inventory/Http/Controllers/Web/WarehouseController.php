<?php

namespace App\Modules\Inventory\Http\Controllers\Web;

use App\Modules\Core\Contracts\BranchDirectory;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveWarehouse;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Inventory\Http\Requests\SaveWarehouseRequest;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    use ReportsBusinessErrors;

    public function index(BranchDirectory $branches): View
    {
        $this->authorize('viewAny', Warehouse::class);
        $warehouses = Warehouse::query()->with('locations')->orderBy('code')->get();

        return view('inventory::warehouses.index', ['warehouses' => $warehouses, 'branches' => $branches->labels($warehouses->pluck('branch_id')->filter()->all())]);
    }

    public function create(BranchDirectory $branches): View
    {
        $this->authorize('create', Warehouse::class);

        return view('inventory::warehouses.form', ['warehouse' => null, 'branchChoices' => $branches->active(), 'branchCode' => null]);
    }

    public function store(SaveWarehouseRequest $request, SaveWarehouse $save, BranchDirectory $branches): RedirectResponse
    {
        return $this->attempt(function () use ($request, $save, $branches) {
            $warehouse = $save($this->data($request, $branches));

            return redirect()->route('inventory.warehouses.show', $warehouse)->with('status', __('inventory::ui.flash.saved'));
        });
    }

    public function show(Warehouse $warehouse, BranchDirectory $branches): View
    {
        $this->authorize('view', $warehouse);

        return view('inventory::warehouses.show', ['warehouse' => $warehouse->load('locations'), 'branch' => $branches->labels([(int) $warehouse->branch_id])[$warehouse->branch_id] ?? null]);
    }

    public function edit(Warehouse $warehouse, BranchDirectory $branches): View
    {
        $this->authorize('update', $warehouse);

        return view('inventory::warehouses.form', [
            'warehouse' => $warehouse, 'branchChoices' => $branches->active(),
            'branchCode' => $warehouse->branch_id ? (explode(' — ', $branches->labels([$warehouse->branch_id])[$warehouse->branch_id] ?? '')[0] ?: null) : null,
        ]);
    }

    public function update(SaveWarehouseRequest $request, Warehouse $warehouse, SaveWarehouse $save, BranchDirectory $branches): RedirectResponse
    {
        return $this->attempt(function () use ($request, $warehouse, $save, $branches) {
            $save($this->data($request, $branches), $warehouse, (int) $request->validated('version'));

            return redirect()->route('inventory.warehouses.show', $warehouse)->with('status', __('inventory::ui.flash.saved'));
        });
    }

    public function addLocation(Request $request, Warehouse $warehouse, SaveLocation $save): RedirectResponse
    {
        $this->authorize('update', $warehouse);
        $data = $request->validate(['code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._\\-]+$/'], 'name' => ['required', 'string', 'max:150']]);

        return $this->attempt(function () use ($warehouse, $save, $data) {
            $save($warehouse, $data);

            return redirect()->route('inventory.warehouses.show', $warehouse)->with('status', __('inventory::ui.flash.saved'));
        });
    }

    public function updateLocation(Request $request, Warehouse $warehouse, Location $location, SaveLocation $save): RedirectResponse
    {
        $this->authorize('update', $warehouse);
        abort_unless($location->warehouse_id === $warehouse->id, 404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'is_active' => ['sometimes', 'boolean']]);
        $data['is_active'] = $request->boolean('is_active');

        return $this->attempt(function () use ($warehouse, $location, $save, $data) {
            $save($warehouse, $data, $location);

            return redirect()->route('inventory.warehouses.show', $warehouse)->with('status', __('inventory::ui.flash.saved'));
        });
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
}
