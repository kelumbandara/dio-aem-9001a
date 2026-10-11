<?php

return [
    // Business dates, numbering periods and UI display use this zone; the database is UTC.
    'display_timezone' => env('DISPLAY_TIMEZONE', 'Asia/Colombo'),

    'locales' => ['en', 'si', 'ta'],

    // Not config('app.locale'): App::setLocale() overwrites that value for the rest of the process.
    'default_locale' => env('APP_LOCALE', 'en'),

    'audit' => [
        // Attribute names (case-insensitive substring match) whose values are never stored.
        'redact' => ['password', 'remember_token', 'secret', 'recovery_codes', 'token', 'nic', 'account_no', 'iban', 'swift'],
        // Whole months are dropped, so entries live between N and N+1 months (owner decision: 3).
        'retention_months' => (int) env('AUDIT_RETENTION_MONTHS', 3),
    ],

    'outbox' => [
        'batch_size' => 100,
        'max_attempts' => 8,
    ],

    'attachments' => [
        // Private disk (S3-compatible in production); never served from the web root.
        'disk' => env('ATTACHMENTS_DISK', 'attachments'),
        'max_kb' => 10240,
        // detected MIME type => allowed extensions. Anything else is rejected, whatever the client says.
        'allowed' => [
            'application/pdf' => ['pdf'],
            'image/png' => ['png'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/webp' => ['webp'],
            'text/plain' => ['txt', 'log'],
            'text/csv' => ['csv'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        ],
        'download_url_minutes' => 5,
    ],

    'mfa' => [
        'issuer' => env('MFA_ISSUER', 'DIO ERP'),
        // Users holding any of these roles must enrol TOTP before using the system.
        'required_roles' => ['super-admin', 'admin', 'accountant'],
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
