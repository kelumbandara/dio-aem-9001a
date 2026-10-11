<?php

namespace App\Modules\MasterData\Contracts;

use App\Modules\MasterData\DTOs\PriceQuote;
use Carbon\CarbonInterface;

interface PriceResolver
{
    /**
     * The unit price for an item and quantity, or null when no price list has one.
     *
     * Price list: the explicit one, else the party's own list, else the default list of that type – a list
     * that is inactive or outside its validity dates is skipped. Row: in the requested unit the row with the
     * highest minimum quantity not above $qty; if the unit has no rows, the base-unit price multiplied by
     * the unit's factor. Never guesses across currencies: the quote states the list's currency.
     *
     * @param  'sales'|'purchase'  $type
     * @param  int|null  $partyId  customer id for sales, supplier id for purchase
     */
    public function quote(
        int $itemId,
        string $qty,
        ?int $uomId = null,
        string $type = 'sales',
        ?int $priceListId = null,
        ?int $partyId = null,
        ?CarbonInterface $on = null,
    ): ?PriceQuote;
}
