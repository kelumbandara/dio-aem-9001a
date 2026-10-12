<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionLine;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\MasterData\Contracts\ItemCatalog;

/** The pick-lists of the BOM and production order forms. */
final class FormOptions
{
    public function __construct(private readonly ItemCatalog $items, private readonly WarehouseDirectory $warehouses) {}

    /** @return array{items: list<array{code: string, name: string, uom: string}>, warehouses: array<int, string>, workCenters: list<array{code: string, name: string}>, machines: list<array{code: string, name: string, work_center: string}>, lines: list<array{code: string, name: string}>} */
    public function all(): array
    {
        $centers = WorkCenter::query()->where('is_active', true)->orderBy('code')->get();

        return [
            'items' => collect($this->items->search('', 500))->filter(fn ($i) => $i->isActive && $i->isStocked())->map(fn ($i) => ['code' => $i->code, 'name' => $i->name, 'uom' => $i->baseUomCode])->values()->all(),
            'warehouses' => $this->warehouses->active(),
            'workCenters' => $centers->map(fn ($c) => ['code' => $c->code, 'name' => $c->name])->all(),
            'machines' => Machine::query()->where('status', '!=', 'retired')->orderBy('code')->get()->map(fn ($m) => ['code' => $m->code, 'name' => $m->name, 'work_center' => $centers->firstWhere('id', $m->work_center_id)->code ?? ''])->all(),
            'lines' => ProductionLine::query()->where('is_active', true)->orderBy('code')->get()->map(fn ($l) => ['code' => $l->code, 'name' => $l->name])->all(),
        ];
    }
}
