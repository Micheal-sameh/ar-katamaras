<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadingController;
use App\Http\Middleware\ReadingsHttpCache;
use Illuminate\Support\Facades\Route;

Route::get('readings/{date}', [ReadingController::class, 'show'])
    ->where('date', '\d{4}-\d{2}-\d{2}')
    ->middleware(['throttle:readings', ReadingsHttpCache::class])
    ->name('readings.show');

// For an uptime monitor (UptimeRobot, healthchecks.io ...): 200 when healthy, 503 when not.
Route::get('health', [HealthController::class, 'show'])
    ->middleware('throttle:readings')
    ->name('health');
