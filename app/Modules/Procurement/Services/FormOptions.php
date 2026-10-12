<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\Contracts\TaxCatalog;

/** The pick-lists of the purchase order form. */
final class FormOptions
{
    public function __construct(
        private readonly PartyDirectory $parties,
        private readonly ItemCatalog $items,
        private readonly WarehouseDirectory $warehouses,
        private readonly TaxCatalog $taxes,
    ) {}

    /** @return array{suppliers: list<array{code: string, name: string}>, items: list<array{code: string, name: string}>, warehouses: array<int, string>, taxCodes: list<array{id: int, code: string, name: string, kind: string}>} */
    public function all(): array
    {
        return [
            'suppliers' => array_map(fn ($s) => ['code' => $s->code, 'name' => $s->name], array_values(array_filter($this->parties->search('supplier', '', 500), fn ($s) => $s->isActive))),
            'items' => collect($this->items->search('', 500))->filter(fn ($i) => $i->isActive && $i->isPurchasable)->map(fn ($i) => ['code' => $i->code, 'name' => $i->name])->values()->all(),
            'warehouses' => $this->warehouses->active(),
            'taxCodes' => $this->taxes->codes(['vat', 'exempt']),
        ];
    }
}
