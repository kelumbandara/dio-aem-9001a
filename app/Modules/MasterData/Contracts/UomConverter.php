<?php

namespace App\Modules\MasterData\Contracts;

use App\Support\Exceptions\BusinessException;

/** Converts quantities between the units an item is defined in. Strings in, strings out. */
interface UomConverter
{
    /**
     * Convert $qty of item $itemId from one unit to another and round (half away from zero) to the
     * target unit's decimal places.
     *
     * @throws BusinessException masterdata.uom_not_convertible when either unit is neither the item's base unit nor one of its units
     */
    public function convert(string $qty, int $itemId, int $fromUomId, int $toUomId): string;

    /** @throws BusinessException masterdata.uom_not_convertible */
    public function toBase(string $qty, int $itemId, int $uomId): string;

    /** Base units contained in one of $uomId (1 for the base unit). @throws BusinessException masterdata.uom_not_convertible */
    public function factor(int $itemId, int $uomId): string;
}
