<?php

return [
    // Dates are Colombo calendar days; timestamps are stored in UTC.
    'timezone' => 'Asia/Colombo',

    // The vendor's licence SIGNING key (secret). Environment only: never stored in the database, a file in the repository or a log.
    'licence_private_key' => env('LICENCE_PRIVATE_KEY'),

    // The phases a customer's product goes through after a licence ends (must match the product's LICENCE_* settings).
    'grace_days' => (int) env('LICENCE_GRACE_DAYS', 7),
    'readonly_days' => (int) env('LICENCE_READONLY_DAYS', 14),

    'payhere' => [
        'merchant_id' => env('PAYHERE_MERCHANT_ID'),
        // Used to sign and check PayHere messages only; never stored or shown.
        'merchant_secret' => env('PAYHERE_MERCHANT_SECRET'),
        'sandbox' => (bool) env('PAYHERE_SANDBOX', true),
    ],

    // Days before/after the due date on which a reminder is e-mailed (once each).
    'reminder_days' => [-3, 0, 3, 7, 21],

    // Starting values for the Settings screen; whatever is saved there wins.
    'defaults' => [
        'plan_name' => 'Standard',
        'monthly_price' => '',
        'vat_rate' => '18.00',
        'trial_days' => '30',
        'invoice_lead_days' => '7',
        'max_users' => '',
        'issuer_name' => '',
        'issuer_address' => '',
        'issuer_vat_number' => '',
        'issuer_email' => '',
        'issuer_phone' => '',
        'bank_details' => '',
        'invoice_footer' => 'Thank you for your business.',
    ],
];
