<?php

/*
|--------------------------------------------------------------------------
| Communications (module Communications, docs/architecture/communications.md)
|--------------------------------------------------------------------------
|
| Every secret comes from the environment only (the server's .env), never from the database or the repository. Without the WhatsApp
| settings the WhatsApp channel is simply "not set up": messages for it fail with a clear reason and nothing is sent anywhere.
| E-mail uses the application's normal mail settings (MAIL_*).
*/

return [
    'whatsapp' => [
        // Meta's WhatsApp Business Cloud API. The version is a setting because Meta retires old ones; check it against Meta's documentation.
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        // proves a webhook call came from Meta (HMAC-SHA256 of the body, header X-Hub-Signature-256)
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        // the word Meta sends back when we register the webhook address
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),
    ],

    // The calling code for numbers typed without one ("077 123 4567" is +94 77 123 4567).
    'default_country_code' => env('COMM_DEFAULT_COUNTRY_CODE', '94'),

    // To stop a mistake or an attack from flooding one person: messages to one address per day, and how long a sending may take.
    'max_per_address_per_day' => (int) env('COMM_MAX_PER_ADDRESS_PER_DAY', 5),
    'send_attempts' => 3,

    // Linking a phone number or e-mail address to a user: the code, its life, the tries allowed, and codes that may be asked for per hour.
    'otp' => [
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'max_per_hour' => 3,
    ],

    // Words that mean "stop writing to me", in any language the customers use (compared after trimming and lower-casing).
    'stop_words' => ['stop', 'unsubscribe', 'quit', 'optout', 'opt out', 'නවත්වන්න', 'நிறுத்து'],
];
