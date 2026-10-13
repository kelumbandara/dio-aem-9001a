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

    'branding' => [
        // Where the uploaded company logo is kept (a private disk; it is served through /branding/logo, never from a public folder).
        'disk' => env('BRANDING_DISK', 'local'),
    ],

    // Shown in the "Powered by" line unless the vendor switches it off or words it differently.
    'vendor_name' => env('VENDOR_NAME', 'DIO ERP'),

    'attachments' => [
        // Private disk (S3-compatible in production); never served from the web root.
        'disk' => env('ATTACHMENTS_DISK', 'attachments'),
        'max_kb' => 10240,
        // Virus scan through a ClamAV daemon (clamd). When enabled and the daemon cannot be reached, uploads are refused
        // (fail closed) rather than stored unscanned.
        'clamav' => [
            'enabled' => (bool) env('CLAMAV_ENABLED', false),
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 20),
        ],
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

    'security' => [
        // Strict-Transport-Security max-age on https answers (0 = off). One year once the site is fully on https.
        'hsts_seconds' => (int) env('HSTS_SECONDS', 31536000),
    ],

    'auth' => [
        'login_attempts_per_minute' => 5,
        // An account is locked for a while after this many wrong passwords or codes, whatever address they come from.
        // Reject passwords found in known breaches (needs outbound access to api.pwnedpasswords.com; fails open if unreachable).
        'check_breached' => (bool) env('PASSWORD_CHECK_BREACHED', env('APP_ENV') === 'production'),
        'lockout_attempts' => 10,
        'lockout_minutes' => 15,
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
