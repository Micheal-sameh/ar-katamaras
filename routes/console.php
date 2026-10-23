<?php

use App\Services\ReadingWarmer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every Tuesday 03:00 (Cairo): store the next 7 days for every language.
Schedule::command('readings:warm --days='.config('readings.warm.days'))
    ->weeklyOn(2, '03:00')
    ->timezone('Africa/Cairo')
    ->onOneServer()
    ->withoutOverlapping();

// If the weekly run could not finish (internet down), try again every day until it has.
// Already stored days are skipped, so a complete window costs nothing.
Schedule::command('readings:warm --days='.config('readings.warm.days'))
    ->dailyAt('04:00')
    ->timezone('Africa/Cairo')
    ->onOneServer()
    ->withoutOverlapping()
    ->when(fn () => ! app(ReadingWarmer::class)->isComplete(
        (int) config('readings.warm.days'),
        config('readings.languages'),
    ));

// On the 25th: store the rest of this month and the whole next month, so no visitor waits for a live
// fetch. If days cannot be stored, one email goes to READINGS_ALERT_EMAIL (see WarmMonth).
Schedule::command('readings:warm-month')
    ->monthlyOn((int) config('readings.warm.monthly.day'), config('readings.warm.monthly.time'))
    ->timezone('Africa/Cairo')
    ->onOneServer()
    ->withoutOverlapping();
