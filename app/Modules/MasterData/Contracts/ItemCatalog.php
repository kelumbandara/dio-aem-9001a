<?php

namespace App\Modules\MasterData\Contracts;

use App\Modules\MasterData\DTOs\ItemInfo;
use App\Support\Exceptions\BusinessException;

/** Read access to the item master for modules above MasterData. */
interface ItemCatalog
{
    public function find(int $itemId): ?ItemInfo;

    public function findByCode(string $code): ?ItemInfo;

    /**
     * For documents: the item must exist and be active.
     *
     * @throws BusinessException masterdata.item_not_found | masterdata.item_inactive
     */
    public function requireActive(int $itemId): ItemInfo;
}
