<?php

/*
|--------------------------------------------------------------------------
| Mobile app support (docs/architecture/mobile.md)
|--------------------------------------------------------------------------
|
| Push notifications go through the Expo push service. The optional access token (EXPO_ACCESS_TOKEN) comes from the environment only.
*/

return [
    // The oldest app version the server still accepts; the app shows "please update" below it.
    'min_app_version' => env('MOBILE_MIN_APP_VERSION', '1.0.0'),

    'push' => [
        'enabled' => (bool) env('MOBILE_PUSH_ENABLED', true),
        'url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        'access_token' => env('EXPO_ACCESS_TOKEN'),
        'timeout' => (int) env('MOBILE_PUSH_TIMEOUT', 10),
        'max_devices_per_user' => (int) env('MOBILE_MAX_DEVICES', 5),
    ],
];
