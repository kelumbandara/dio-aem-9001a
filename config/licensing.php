<?php

return [
    /*
    | Whether an expired licence restricts the system. On by default in production only, so development and tests are
    | never locked out; set LICENSING_ENFORCE=true/false to force either way.
    */
    'enforce' => env('LICENSING_ENFORCE'),

    /*
    | The vendor's PUBLIC signing key (base64). Licences are verified offline with it. The private key never belongs in
    | this product's files: it lives only on the vendor's machine (see `php artisan licence:keys`).
    */
    'public_key' => env('LICENCE_PUBLIC_KEY'),

    // A fresh installation works for this many days before it needs a licence.
    'trial_days' => (int) env('LICENCE_TRIAL_DAYS', 30),

    // After the licence ends: everything works with a warning for `grace_days`, then people can only look and print for
    // `readonly_days`, then the system is locked (only the licence screen and sign-out remain).
    'grace_days' => (int) env('LICENCE_GRACE_DAYS', 7),
    'readonly_days' => (int) env('LICENCE_READONLY_DAYS', 14),

    // Warn on every screen when this many days (or fewer) are left.
    'warn_days' => (int) env('LICENCE_WARN_DAYS', 7),

    // Shown on the licence screen: how to renew (the vendor's contact details).
    'renew_note' => env('LICENCE_RENEW_NOTE'),
];
