<?php

use App\Http\Controllers\AccessCheckController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadingController;
use App\Http\Middleware\ReadingsHttpCache;
use App\Http\Middleware\RequireChurchCode;
use Illuminate\Support\Facades\Route;

// Members only: with READINGS_ACCESS_CODE set, every reading request needs the church code (X-Church-Code header).
// The code is checked before the rate limit counts the request and before anything is fetched or cached.
Route::middleware([RequireChurchCode::class, 'throttle:readings'])->group(function () {
    // The apps call this to check a code the person typed (under /readings so every app reaches it like the readings).
    Route::get('readings/access', AccessCheckController::class)->name('access');

    // Several already-stored days in one request (never fetches from the upstream API), so an app can top up its
    // offline copy with one call every two weeks. Declared first: 'range' is not a date.
    Route::get('readings/range', [ReadingController::class, 'range'])
        ->middleware(ReadingsHttpCache::class)
        ->name('readings.range');

    Route::get('readings/{date}', [ReadingController::class, 'show'])
        ->where('date', '\d{4}-\d{2}-\d{2}')
        ->middleware(ReadingsHttpCache::class)
        ->name('readings.show');
});

// For an uptime monitor (UptimeRobot, healthchecks.io ...): 200 when healthy, 503 when not. Stays open.
Route::get('health', [HealthController::class, 'show'])
    ->middleware('throttle:readings')
    ->name('health');
