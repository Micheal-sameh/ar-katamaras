<?php

use App\Http\Controllers\ReadingController;
use App\Http\Middleware\ReadingsHttpCache;
use Illuminate\Support\Facades\Route;

Route::get('readings/{date}', [ReadingController::class, 'show'])
    ->where('date', '\d{4}-\d{2}-\d{2}')
    ->middleware(['throttle:120,1', ReadingsHttpCache::class])
    ->name('readings.show');
