<?php

return [
    /*
    | A supplier's price may differ from the order's by this many percent (either way) without anyone having to
    | accept the difference. More than that, a bill needs someone with `procurement.bill.override` and a reason.
    | The difference itself is always posted to the purchase price variance account.
    */
    'price_tolerance_pct' => env('PROCUREMENT_PRICE_TOLERANCE_PCT', '2'),

    /*
    | Tax code given to an order line whose item has none: input VAT is claimed on every purchase unless the item is EXEMPT.
    */
    'default_vat_code' => env('PROCUREMENT_DEFAULT_VAT_CODE', 'VAT'),
];
