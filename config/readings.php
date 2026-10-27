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
    | Bible versions a visitor may choose (Katameros bibleId), per language
    |--------------------------------------------------------------------------
    | Only ids listed here are ever sent to the API. Today only Arabic has two versions.
    */
    'bibles' => [
        Language::Arabic->value => [
            'default' => 11,
            'available' => [11 => 'الكتاب المقدس، فان دايك', 3 => 'Arabic'],
        ],
        Language::English->value => ['default' => 2, 'available' => [2 => 'NKJV']],
        Language::French->value => ['default' => 1, 'available' => [1 => 'Louis Segond 1910']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Weekly pre-cache (readings:warm)
    |--------------------------------------------------------------------------
    */
    'warm' => [
        'days' => (int) env('READINGS_WARM_DAYS', 7),

        // readings:warm-year: keeps this many days ahead stored on the server (the whole year), topped up weekly.
        'year_days' => (int) env('READINGS_YEAR_DAYS', 365),

        // When a visitor asks for a day that is not stored, the same warm-up starts by itself (queued, in chunks of
        // this many days), at most once per cooldown.
        'year_chunk_days' => (int) env('READINGS_YEAR_CHUNK_DAYS', 10),
        'on_demand_cooldown_minutes' => (int) env('READINGS_ONDEMAND_WARM_COOLDOWN', 360),
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
    | Health check (GET /api/health, readings:health): point an uptime monitor at it
    |--------------------------------------------------------------------------
    | The scheduler and the queue worker each leave a heartbeat every minute. If one goes quiet for
    | longer than these limits, nothing else can alert you (nothing is running), so the check fails.
    */
    'health' => [
        'scheduler_max_age' => (int) env('READINGS_HEALTH_SCHEDULER_MAX_AGE', 300),  // seconds
        'queue_max_age' => (int) env('READINGS_HEALTH_QUEUE_MAX_AGE', 600),          // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Database backups (readings:backup, SQLite only)
    |--------------------------------------------------------------------------
    */
    'backup' => [
        'directory' => env('READINGS_BACKUP_DIR', storage_path('app/backups')),
        'keep' => (int) env('READINGS_BACKUP_KEEP', 14), // newest copies to keep
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
    // Members only. The readings API answers only requests that carry a valid church code (header X-Church-Code);
    // the apps ask each person for it once. With no codes and no pattern, the API is open to everyone.
    //   READINGS_ACCESS_CODES   the codes handed out, comma separated (READINGS_ACCESS_CODE, one code, still works)
    //   READINGS_ACCESS_PATTERN optional: a format every valid code matches, e.g. e1c1f[0-9]*nr[0-9]+
    'access' => [
        'codes' => array_values(array_filter(array_map('trim', explode(',', (string) env('READINGS_ACCESS_CODE').','.(string) env('READINGS_ACCESS_CODES'))))),
        'pattern' => env('READINGS_ACCESS_PATTERN'),
        // The Android / Windows apps send this (X-App-Key) instead of asking for a code, so only the website is gated.
        // It ships inside the apps: it keeps casual visitors out, not someone who goes looking for it.
        'app_key' => env('READINGS_APP_KEY'),
        'max_failures_per_minute' => (int) env('READINGS_ACCESS_MAX_FAILURES', 5), // wrong codes per IP
    ],

    // GET /api/readings/range: how many stored days one request may return (the apps ask for 14).
    'range' => [
        'max_days' => (int) env('READINGS_RANGE_MAX_DAYS', 31),
    ],

    'http' => [
        'max_age' => (int) env('READINGS_HTTP_MAX_AGE', 300),

        // Public exposure: only days inside this window can be requested (and so fetched from the
        // upstream API). Caps how many upstream calls anyone can trigger by scripting dates.
        'past_days' => (int) env('READINGS_PAST_DAYS', 366),
        'future_days' => (int) env('READINGS_FUTURE_DAYS', 366),

        // Requests per minute per client IP.
        'throttle_per_minute' => (int) env('READINGS_THROTTLE_PER_MINUTE', 30),

        // Reverse proxy / CDN in front of the app (needed for correct client IPs and https):
        // "*" to trust any (only safe if the app is reachable solely through the proxy),
        // or a comma separated list of proxy IPs/CIDRs. Empty = trust none.
        'trusted_proxies' => env('TRUSTED_PROXIES'),
    ],

];
