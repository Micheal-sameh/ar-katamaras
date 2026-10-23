<?php

use App\Enums\Language;

return [

    /*
    |--------------------------------------------------------------------------
    | Katameros API
    |--------------------------------------------------------------------------
    */
    'api' => [
        'base_url' => env('KATAMEROS_API_URL', 'https://api.katameros.app'),
        'timeout' => (int) env('KATAMEROS_API_TIMEOUT', 15),
        'retries' => (int) env('KATAMEROS_API_RETRIES', 1),
        'bible_id' => env('KATAMEROS_BIBLE_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | store: null uses the default cache store (use "redis" when available,
    | otherwise "database" or "file"). The store must support atomic locks.
    */
    'cache' => [
        'store' => env('READINGS_CACHE_STORE'),
        'ttl_days' => (int) env('READINGS_CACHE_TTL_DAYS', 30),
        'lock_seconds' => 30,
        'lock_wait_seconds' => 10,
    ],

    'timezone' => env('READINGS_TIMEZONE', 'Africa/Cairo'),

    /*
    |--------------------------------------------------------------------------
    | Languages served by the app (the Katameros languageId lives on the enum)
    |--------------------------------------------------------------------------
    */
    'languages' => [Language::Arabic, Language::English],
    'default_language' => Language::Arabic,

    /*
    |--------------------------------------------------------------------------
    | Weekly pre-cache (readings:warm)
    |--------------------------------------------------------------------------
    */
    'warm' => [
        'days' => (int) env('READINGS_WARM_DAYS', 7),
        'sleep_seconds' => (int) env('READINGS_WARM_SLEEP', 1),
        'alert_below_days' => (int) env('READINGS_ALERT_BELOW_DAYS', 3),
        'retry_delays' => [60, 300, 900], // seconds: 1, 5, 15 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP caching for GET /api/readings/{date}
    |--------------------------------------------------------------------------
    */
    'http' => [
        'max_age' => (int) env('READINGS_HTTP_MAX_AGE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | PPT import
    |--------------------------------------------------------------------------
    */
    'ppt' => [
        'directory' => env('READINGS_PPT_DIR', storage_path('app/ppt')),
    ],
];
