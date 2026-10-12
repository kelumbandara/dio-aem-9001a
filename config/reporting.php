<?php

return [
    // The financial year starts in this month (Sri Lanka: April), so "this year" means 1 April – 31 March.
    'fiscal_year_start_month' => (int) env('REPORTING_FISCAL_YEAR_START_MONTH', 4),

    // The longest period a dashboard will work out (days).
    'max_range_days' => 732,

    // How many rows the "top" lists show.
    'top_rows' => 10,
];
