<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionLine;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/** Turns BOMs and production orders into plain arrays for the screens and the API, with items shown by code and name. */
final class ManufacturingPresenter
{
    public function __construct(
        private readonly ItemCatalog $items,
        private readonly WarehouseDirectory $warehouses,
        private readonly BomQueries $boms,
    ) {}

    /**
     * @param  Collection<int, BomVersion>  $versions
     * @return list<array<string, mixed>>
     */
    public function bomHeaders(Collection $versions): array
    {
        $items = $this->items->findMany($versions->pluck('item_id')->unique()->all());

        return $versions->map(fn (BomVersion $v) => [
            'id' => $v->public_id, 'item' => $items[$v->item_id]->code ?? '', 'item_name' => $items[$v->item_id]->name ?? '', 'version_no' => $v->version_no,
            'status' => $v->status, 'base_qty' => Decimal::trim((string) $v->base_qty), 'uom' => $items[$v->item_id]->baseUomCode ?? '',
            'effective_from' => $v->effective_from?->toDateString(), 'effective_to' => $v->effective_to?->toDateString(), 'description' => $v->description,
            'components' => $v->components()->count(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function bom(BomVersion $v, bool $withTree = true): array
    {
        $product = $this->items->find($v->item_id);
        $ids = $v->components->pluck('component_item_id')->all();
        $infos = $this->items->findMany($ids);
        $units = [];
        foreach ($v->components as $c) {
            $units[$c->component_item_id] = collect($this->items->units($c->component_item_id))->firstWhere('uomId', $c->uom_id)->code ?? ($infos[$c->component_item_id]->baseUomCode ?? '');
        }
        $centers = WorkCenter::query()->whereIn('id', $v->operations->pluck('work_center_id'))->get()->keyBy('id');
        $machines = Machine::query()->whereIn('id', $v->operations->pluck('machine_id')->filter())->get()->keyBy('id');

        $tree = [];
        if ($withTree && $v->status !== BomVersion::DRAFT) {
            $rows = $this->boms->explode($v->item_id, Decimal::trim((string) $v->base_qty), $v);
            $all = $this->items->findMany(array_values(array_unique(array_column($rows, 'item_id'))));
            $tree = array_map(fn (array $r) => $r + ['code' => $all[$r['item_id']]->code ?? '', 'name' => $all[$r['item_id']]->name ?? '', 'uom' => $all[$r['item_id']]->baseUomCode ?? ''], $rows);
        }

        return [
            'id' => $v->public_id, 'item' => $product->code ?? '', 'item_name' => $product->name ?? '', 'uom' => $product->baseUomCode ?? '',
            'version_no' => $v->version_no, 'status' => $v->status, 'base_qty' => Decimal::trim((string) $v->base_qty), 'description' => $v->description,
            'effective_from' => $v->effective_from?->toDateString(), 'effective_to' => $v->effective_to?->toDateString(), 'version' => $v->version,
            'components' => $v->components->map(fn ($c) => [
                'line_no' => $c->line_no, 'item' => $infos[$c->component_item_id]->code ?? '', 'item_name' => $infos[$c->component_item_id]->name ?? '',
                'qty' => Decimal::trim((string) $c->qty), 'uom' => $units[$c->component_item_id] ?? '', 'scrap_pct' => Decimal::trim((string) $c->scrap_pct), 'notes' => $c->notes,
            ])->values()->all(),
            'operations' => $v->operations->map(fn ($o) => [
                'op_no' => $o->op_no, 'name' => $o->name, 'work_center' => $centers[$o->work_center_id]->code ?? '', 'machine' => $o->machine_id ? ($machines[$o->machine_id]->code ?? '') : null,
                'setup_minutes' => Decimal::trim((string) $o->setup_minutes), 'run_minutes' => Decimal::trim((string) $o->run_minutes), 'notes' => $o->notes,
            ])->values()->all(),
            'tree' => $tree,
        ];
    }

    /**
     * @param  Collection<int, ProductionOrder>  $orders
     * @return list<array<string, mixed>>
     */
    public function orderHeaders(Collection $orders): array
    {
        $items = $this->items->findMany($orders->pluck('item_id')->unique()->all());

        return $orders->map(fn (ProductionOrder $o) => [
            'id' => $o->public_id, 'order_no' => $o->order_no, 'item' => $items[$o->item_id]->code ?? '', 'item_name' => $items[$o->item_id]->name ?? '', 'uom' => $items[$o->item_id]->baseUomCode ?? '',
            'status' => $o->status, 'planned_qty' => Decimal::trim((string) $o->planned_qty), 'planned_start' => $o->planned_start->toDateString(), 'planned_end' => $o->planned_end->toDateString(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function order(ProductionOrder $o): array
    {
        $o->load('materials', 'operations', 'movements', 'bomVersion');
        $product = $this->items->find($o->item_id);
        $infos = $this->items->findMany($o->materials->pluck('item_id')->all());
        $labels = $this->warehouses->labels([$o->source_warehouse_id, $o->output_warehouse_id]);
        $centers = WorkCenter::query()->whereIn('id', $o->operations->pluck('work_center_id'))->get()->keyBy('id');
        $machines = Machine::query()->whereIn('id', $o->operations->pluck('machine_id')->filter())->get()->keyBy('id');
        $line = $o->production_line_id ? ProductionLine::query()->find($o->production_line_id) : null;
        $byMaterial = $o->movements->groupBy('material_id');

        $wip = '0';
        foreach ($o->materials as $m) {
            $wip = Decimal::add($wip, Decimal::sub((string) $m->issued_value, (string) $m->returned_value));
        }

        return [
            'id' => $o->public_id, 'order_no' => $o->order_no, 'status' => $o->status, 'version' => $o->version,
            'item' => $product->code ?? '', 'item_name' => $product->name ?? '', 'uom' => $product->baseUomCode ?? '',
            'bom' => 'v'.$o->bomVersion->version_no, 'bom_id' => $o->bomVersion->public_id, 'bom_status' => $o->bomVersion->status,
            'planned_qty' => Decimal::trim((string) $o->planned_qty), 'reported_qty' => Decimal::trim((string) $o->reported_qty), 'accepted_qty' => Decimal::trim((string) $o->accepted_qty),
            'rejected_qty' => Decimal::trim((string) $o->rejected_qty), 'rework_qty' => Decimal::trim((string) $o->rework_qty), 'received_qty' => Decimal::trim((string) $o->received_qty),
            'planned_start' => $o->planned_start->toDateString(), 'planned_end' => $o->planned_end->toDateString(), 'line' => $line?->code,
            'source_warehouse' => $labels[$o->source_warehouse_id] ?? '', 'output_warehouse' => $labels[$o->output_warehouse_id] ?? '',
            'source_warehouse_code' => explode(' ', $labels[$o->source_warehouse_id] ?? '')[0], 'output_warehouse_code' => explode(' ', $labels[$o->output_warehouse_id] ?? '')[0],
            'notes' => $o->notes, 'cancel_reason' => $o->cancel_reason, 'released_at' => $o->released_at?->toIso8601String(),
            'wip_value' => Decimal::round($wip, 2),
            'materials' => $o->materials->map(function ($m) use ($infos, $byMaterial) {
                $net = $m->netQty();

                return [
                    'line_no' => $m->line_no, 'item' => $infos[$m->item_id]->code ?? '', 'item_name' => $infos[$m->item_id]->name ?? '', 'uom' => $infos[$m->item_id]->baseUomCode ?? '',
                    'tracks_lots' => (bool) ($infos[$m->item_id]->tracksLots ?? false),
                    'required_qty' => Decimal::trim((string) $m->required_qty), 'issued_qty' => Decimal::trim((string) $m->issued_qty), 'returned_qty' => Decimal::trim((string) $m->returned_qty),
                    'net_qty' => Decimal::trim($net), 'remaining_qty' => Decimal::compare((string) $m->required_qty, $net) > 0 ? Decimal::trim(Decimal::sub((string) $m->required_qty, $net)) : '0',
                    'issued_value' => Decimal::round((string) $m->issued_value, 2), 'returned_value' => Decimal::round((string) $m->returned_value, 2),
                    'movements' => ($byMaterial[$m->id] ?? collect())->map(fn ($mv) => [
                        'kind' => $mv->kind, 'qty' => Decimal::trim((string) $mv->qty), 'value' => Decimal::round((string) $mv->value, 2), 'document' => $mv->stock_document_no,
                        'date' => $mv->posted_on->toDateString(), 'reason' => $mv->reason, 'over_issue' => $mv->over_issue,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'operations' => $o->operations->map(fn ($op) => [
                'op_no' => $op->op_no, 'name' => $op->name, 'work_center' => $centers[$op->work_center_id]->code ?? '', 'machine' => $op->machine_id ? ($machines[$op->machine_id]->code ?? '') : null,
                'setup_minutes' => Decimal::trim((string) $op->planned_setup_minutes), 'run_minutes' => Decimal::trim((string) $op->planned_run_minutes), 'status' => $op->status,
            ])->values()->all(),
        ];
    }
}
