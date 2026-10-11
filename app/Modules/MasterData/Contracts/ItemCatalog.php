<?php

namespace App\Modules\MasterData\Contracts;

use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\MasterData\DTOs\ItemUnit;
use App\Support\Exceptions\BusinessException;

/** Read access to the item master for modules above MasterData. */
interface ItemCatalog
{
    public function find(int $itemId): ?ItemInfo;

    public function findByCode(string $code): ?ItemInfo;

    /**
     * Several items at once (lists and documents); unknown ids are simply absent.
     *
     * @param  list<int>  $itemIds
     * @return array<int, ItemInfo> keyed by item id
     */
    public function findMany(array $itemIds): array;

    /**
     * Items whose code, name or barcode contains the term (case-insensitive), by code; for pickers and lists.
     *
     * @return array<int, ItemInfo> keyed by item id
     */
    public function search(string $term, int $limit = 200): array;

    /**
     * The units an item can be handled in, base unit first.
     *
     * @return list<ItemUnit>
     */
    public function units(int $itemId): array;

    /**
     * For documents: the item must exist and be active.
     *
     * @throws BusinessException masterdata.item_not_found | masterdata.item_inactive
     */
    public function requireActive(int $itemId): ItemInfo;
}
