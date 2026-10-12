<?php

return [
    /*
    | How far (in percent of the required quantity) the net quantity issued to a production order may exceed what the
    | order needs. The BOM's own scrap allowance is already part of "required". Beyond this, issuing needs someone with
    | `manufacturing.issue.over` and a reason. 0 = nothing above the requirement without that permission.
    */
    'over_issue_pct' => env('MANUFACTURING_OVER_ISSUE_PCT', '0'),

    /*
    | Output may exceed the planned quantity of a production order by this many percent (units spoiled and remade still
    | count as made). Beyond it, make another order.
    */
    'over_production_pct' => env('MANUFACTURING_OVER_PRODUCTION_PCT', '10'),

    // How many days back an operator may report output or downtime for a shift (0 = only the current shift's date).
    'report_backdate_days' => (int) env('MANUFACTURING_REPORT_BACKDATE_DAYS', 7),

    // The deepest level an indented BOM is followed (guards against a data fault; real products are far shallower).
    'max_bom_depth' => 12,
];
