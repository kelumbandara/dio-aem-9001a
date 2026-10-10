<?php

return [
    // Business dates, numbering periods and UI display use this zone; the database is UTC.
    'display_timezone' => env('DISPLAY_TIMEZONE', 'Asia/Colombo'),

    'locales' => ['en', 'si', 'ta'],

    // Not config('app.locale'): App::setLocale() overwrites that value for the rest of the process.
    'default_locale' => env('APP_LOCALE', 'en'),

    'audit' => [
        // Attribute names (case-insensitive substring match) whose values are never stored.
        'redact' => ['password', 'remember_token', 'secret', 'recovery_codes', 'token', 'nic'],
    ],

    'mfa' => [
        'issuer' => env('MFA_ISSUER', 'DIO ERP'),
        // Users holding any of these roles must enrol TOTP before using the system.
        'required_roles' => ['super-admin', 'admin'],
        'recovery_code_count' => 8,
    ],

    'auth' => [
        'login_attempts_per_minute' => 5,
        'api_requests_per_minute' => 120,
    ],

    'idempotency' => [
        'ttl_hours' => 24,
    ],

    // Fallbacks for SettingsService::get() when nothing is stored.
    'settings_defaults' => [
        'core.date_format' => 'Y-m-d',
        'core.datetime_format' => 'Y-m-d H:i',
    ],
];
