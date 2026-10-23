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
        'timeout' => (int) env('KATAMEROS_API_TIMEOUT', 10),
        'connect_timeout' => (int) env('KATAMEROS_API_CONNECT_TIMEOUT', 4),
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

        // readings:warm-month: on this day of each month, store the rest of this month plus the next
        // `months_ahead` month(s), so visitors never wait for a live fetch.
        'monthly' => [
            'day' => (int) env('READINGS_MONTHLY_DAY', 25),
            'time' => env('READINGS_MONTHLY_TIME', '03:30'),
            'months_ahead' => (int) env('READINGS_MONTHLY_MONTHS_AHEAD', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure alerts (needs working MAIL_* settings, see docs/readings-setup.md)
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'email' => env('READINGS_ALERT_EMAIL', 'micheal.sameh@avarewase.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP caching for GET /api/readings/{date}
    |--------------------------------------------------------------------------
    */
    'http' => [
        'max_age' => (int) env('READINGS_HTTP_MAX_AGE', 300),

        // Public exposure: only days inside this window can be requested (and so fetched from the
        // upstream API). Caps how many upstream calls anyone can trigger by scripting dates.
        'past_days' => (int) env('READINGS_PAST_DAYS', 366),
        'future_days' => (int) env('READINGS_FUTURE_DAYS', 366),

        // Requests per minute per client IP.
        'throttle_per_minute' => (int) env('READINGS_THROTTLE_PER_MINUTE', 60),

        // Reverse proxy / CDN in front of the app (needed for correct client IPs and https):
        // "*" to trust any (only safe if the app is reachable solely through the proxy),
        // or a comma separated list of proxy IPs/CIDRs. Empty = trust none.
        'trusted_proxies' => env('TRUSTED_PROXIES'),
    ],

];
