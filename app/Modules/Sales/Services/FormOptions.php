<?php

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\Contracts\TaxCatalog;

/** The pick-lists of the quotation and order forms. */
final class FormOptions
{
    public function __construct(
        private readonly PartyDirectory $parties,
        private readonly ItemCatalog $items,
        private readonly WarehouseDirectory $warehouses,
        private readonly TaxCatalog $taxes,
    ) {}

    /** @return array{customers: list<array{code: string, name: string}>, items: list<array{code: string, name: string}>, warehouses: array<int, string>, taxCodes: list<array{id: int, code: string, name: string, kind: string}>} */
    public function all(): array
    {
        return [
            'customers' => array_map(fn ($c) => ['code' => $c->code, 'name' => $c->name], array_values(array_filter($this->parties->search('customer', '', 500), fn ($c) => $c->isActive))),
            'items' => collect($this->items->search('', 500))->filter(fn ($i) => $i->isActive && $i->isSellable)->map(fn ($i) => ['code' => $i->code, 'name' => $i->name])->values()->all(),
            'warehouses' => $this->warehouses->active(),
            'taxCodes' => $this->taxes->codes(['vat', 'exempt']),
        ];
    }
}
