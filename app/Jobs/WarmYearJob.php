<?php

namespace App\Jobs;

use App\Services\CopticCalendarService;
use App\Services\ReadingWarmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The same work as `php artisan readings:warm --days=365`, done in small chunks. Each chunk finishes well inside
 * the queue worker's 60 second limit and then queues the next one, so a whole year never runs as one long job.
 * Days that are already stored are skipped, so a chunk over stored days costs nothing.
 */
class WarmYearJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public int $offset = 0) {}

    public function handle(ReadingWarmer $warmer, CopticCalendarService $calendar): void
    {
        $total = (int) config('readings.warm.year_days');
        $chunk = max(1, (int) config('readings.warm.year_chunk_days'));

        $from = $calendar->today()->addDays($this->offset);
        $to = $calendar->today()->addDays(min($this->offset + $chunk, $total) - 1);

        $warmer->warmRange($from, $to, config('readings.languages')); // a failing day is retried by its own job

        if ($this->offset + $chunk < $total) {
            self::dispatch($this->offset + $chunk);
        }
    }
}
