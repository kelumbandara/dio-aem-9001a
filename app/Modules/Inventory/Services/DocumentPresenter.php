<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Contracts\UserDirectory;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Lot;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;

/** Builds the readable form of a stock document (shared by the API and the web screens). */
final class DocumentPresenter
{
    public function __construct(private readonly ItemCatalog $items, private readonly UserDirectory $users) {}

    /**
     * @param  iterable<StockDocument>  $documents
     * @return list<array<string, mixed>> headers only (no lines), for lists
     */
    public function headers(iterable $documents): array
    {
        $docs = collect($documents);
        $names = $this->users->names($docs->pluck('created_by')->filter()->all());
        $related = StockDocument::query()->whereIn('id', $docs->pluck('reverses_id')->merge($docs->pluck('reversed_by_id'))->filter()->unique()->all())->pluck('document_no', 'id');

        return $docs->map(fn (StockDocument $d) => $this->header($d, $names, $related->all()))->values()->all();
    }

    /** @return array<string, mixed> */
    public function full(StockDocument $document): array
    {
        $lines = $document->lines()->get();
        $items = $this->items->findMany($lines->pluck('item_id')->all());
        $locationIds = $lines->pluck('from_location_id')->merge($lines->pluck('to_location_id'))->filter()->unique()->all();
        $locations = Location::query()->with('warehouse')->whereIn('id', $locationIds)->get()->keyBy('id');
        $lots = Lot::query()->whereIn('id', $lines->pluck('lot_id')->filter()->all())->get()->keyBy('id');
        $names = $this->users->names($document->created_by ? [$document->created_by] : []);
        $related = StockDocument::query()->whereIn('id', array_filter([$document->reverses_id, $document->reversed_by_id]))->pluck('document_no', 'id')->all();

        $place = fn (?int $id) => $id === null ? null : [
            'warehouse' => $locations[$id]->warehouse->code ?? null, 'location' => ($locations[$id]->is_default ?? true) ? null : $locations[$id]->code,
        ];

        return $this->header($document, $names, $related) + [
            'lines' => $lines->map(fn ($l) => [
                'line_no' => $l->line_no, 'item_code' => $items[$l->item_id]->code ?? null, 'item_name' => $items[$l->item_id]->name ?? null,
                'qty' => $l->qty, 'uom' => $l->uom_code, 'base_qty' => $l->base_qty, 'base_uom' => $items[$l->item_id]->baseUomCode ?? null,
                'from' => $place($l->from_location_id), 'to' => $place($l->to_location_id),
                'lot' => $l->lot_id ? ($lots[$l->lot_id]->lot_no ?? null) : null, 'expiry_date' => $l->lot_id ? $lots[$l->lot_id]->expiry_date?->toDateString() : null,
                'unit_cost' => $l->unit_cost !== null ? Decimal::round($l->unit_cost, 6) : null, 'value' => $l->value, 'note' => $l->note,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, string>  $userNames
     * @param  array<int, string>  $documentNos  id => number of related documents
     * @return array<string, mixed>
     */
    private function header(StockDocument $d, array $userNames, array $documentNos): array
    {
        return [
            'id' => $d->public_id, 'document_no' => $d->document_no, 'type' => $d->doc_type, 'status' => $d->status,
            'posting_date' => $d->posting_date->toDateString(), 'reason' => $d->reason, 'reference' => $d->reference, 'notes' => $d->notes,
            'total_value' => $d->total_value, 'source_type' => $d->source_type, 'source_id' => $d->source_id,
            'reverses' => $d->reverses_id ? ($documentNos[$d->reverses_id] ?? null) : null,
            'reversed_by' => $d->reversed_by_id ? ($documentNos[$d->reversed_by_id] ?? null) : null,
            'created_by' => $d->created_by ? ($userNames[$d->created_by] ?? null) : null,
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}
