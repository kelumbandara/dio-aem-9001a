<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Manufacturing\Models\DowntimeReason;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionLine;
use App\Modules\Manufacturing\Models\Shift;
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

    /**
     * What the operator screen needs: shifts (with the one running now), machines that can work, reasons for stopping.
     *
     * @return array{shifts: list<array{code: string, name: string, hours: string}>, current: array{shift: string, date: string}|null, machines: list<array{code: string, name: string}>, reasons: list<array{code: string, name: string, category: string}>}
     */
    public function operator(ShiftService $shifts): array
    {
        $current = $shifts->current();

        return [
            'shifts' => Shift::query()->where('is_active', true)->orderBy('start_time')->get()->map(fn ($s) => ['code' => $s->code, 'name' => $s->name, 'hours' => substr((string) $s->start_time, 0, 5).' – '.substr((string) $s->end_time, 0, 5)])->all(),
            'current' => $current === null ? null : ['shift' => $current['shift']->code, 'date' => $current['date']->toDateString()],
            'machines' => Machine::query()->where('status', 'available')->orderBy('code')->get()->map(fn ($m) => ['code' => $m->code, 'name' => $m->name])->all(),
            'reasons' => DowntimeReason::query()->where('is_active', true)->orderBy('category')->orderBy('code')->get()->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'category' => $r->category])->all(),
        ];
    }
}
