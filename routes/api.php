<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadingController;
use App\Http\Middleware\ReadingsHttpCache;
use Illuminate\Support\Facades\Route;

// Several already-stored days in one request (never fetches from the upstream API), so an app can top up its
// offline copy with one call every two weeks. Declared first: 'range' is not a date.
Route::get('readings/range', [ReadingController::class, 'range'])
    ->middleware(['throttle:readings', ReadingsHttpCache::class])
    ->name('readings.range');

Route::get('readings/{date}', [ReadingController::class, 'show'])
    ->where('date', '\d{4}-\d{2}-\d{2}')
    ->middleware(['throttle:readings', ReadingsHttpCache::class])
    ->name('readings.show');

// For an uptime monitor (UptimeRobot, healthchecks.io ...): 200 when healthy, 503 when not.
Route::get('health', [HealthController::class, 'show'])
    ->middleware('throttle:readings')
    ->name('health');
