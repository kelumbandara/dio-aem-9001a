<?php

return [
    /*
    | How far (in percent of the required quantity) the net quantity issued to a production order may exceed what the
    | order needs. The BOM's own scrap allowance is already part of "required". Beyond this, issuing needs someone with
    | `manufacturing.issue.over` and a reason. 0 = nothing above the requirement without that permission.
    */
    'over_issue_pct' => env('MANUFACTURING_OVER_ISSUE_PCT', '0'),

    // The deepest level an indented BOM is followed (guards against a data fault; real products are far shallower).
    'max_bom_depth' => 12,
];
