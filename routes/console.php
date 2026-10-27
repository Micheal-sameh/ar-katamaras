<?php

use App\Jobs\HeartbeatJob;
use App\Services\CopticCalendarService;
use App\Services\HealthService;
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

// Every Sunday 02:00 (Cairo): top the server's store up to a whole year ahead (stored days are skipped, so after
// the first big run it only adds the new week). The apps then pull two weeks at a time from /api/readings/range.
Schedule::command('readings:warm --days='.config('readings.warm.year_days'))
    ->weeklyOn(0, '02:00')
    ->timezone('Africa/Cairo')
    ->onOneServer()
    ->withoutOverlapping(1440);

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

// Saturday 03:00: fetch the coming week again and replace the stored copies, so corrections made
// upstream reach visitors. A day that cannot be fetched simply keeps its stored copy.
Schedule::command('readings:refresh --days='.config('readings.warm.days'))
    ->weeklyOn(6, '03:00')
    ->timezone('Africa/Cairo')
    ->onOneServer()
    ->withoutOverlapping();

// Catch-up for the monthly job: Laravel never runs a schedule it missed (server or scheduler down on the
// 25th). From the 26th, every morning, run it again until next month is completely stored.
Schedule::command('readings:warm-month')
    ->dailyAt('05:00')
    ->timezone('Africa/Cairo')
    ->onOneServer()
    ->withoutOverlapping()
    ->when(function () {
        $today = app(CopticCalendarService::class)->today();
        if ($today->day <= (int) config('readings.warm.monthly.day')) {
            return false;
        }
        $warmer = app(ReadingWarmer::class);
        $window = $warmer->monthWindow((int) config('readings.warm.monthly.months_ahead'));

        return ! $warmer->isRangeStored($window['from'], $window['to'], config('readings.languages'));
    });

// Every minute the scheduler and the queue worker leave a heartbeat; GET /api/health (and the Docker
// health checks) fail when either goes quiet.
Schedule::call(fn () => app(HealthService::class)->recordHeartbeat('scheduler'))
    ->everyMinute()
    ->name('readings-scheduler-heartbeat');
Schedule::job(new HeartbeatJob)
    ->everyMinute()
    ->name('readings-queue-heartbeat');

// 02:30 Cairo: a consistent copy of the SQLite database (the newest 14 are kept).
Schedule::command('readings:backup')
    ->dailyAt('02:30')
    ->timezone('Africa/Cairo')
    ->onOneServer()
    ->withoutOverlapping();
