<?php

/*
| The readings API is public and read-only, so it answers cross-origin GET requests.
| Restrict it to your own sites with CORS_ALLOWED_ORIGINS (comma separated), e.g.
| "https://readings.example.org,http://tauri.localhost,tauri://localhost".
| The default "*" allows any origin (no cookies or credentials are ever involved).
*/
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*')))));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'],

    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'If-None-Match'],

    'exposed_headers' => ['ETag', 'X-Readings-Source'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
