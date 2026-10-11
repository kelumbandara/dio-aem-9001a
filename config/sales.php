<?php

return [
    /*
    | Prices typed on new quotations and orders include VAT (the owner's rule: all sales prices are VAT inclusive).
    | The setting is copied onto each document when it is created, so changing it never reinterprets old documents.
    */
    'prices_include_vat' => (bool) env('SALES_PRICES_INCLUDE_VAT', true),

    /*
    | Tax code given to a line whose item has none. Every sale is subject to VAT unless an item is set to EXEMPT.
    */
    'default_vat_code' => env('SALES_DEFAULT_VAT_CODE', 'VAT'),
];
