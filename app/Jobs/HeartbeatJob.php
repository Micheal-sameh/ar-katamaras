<?php

namespace App\Jobs;

use App\Services\HealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Dispatched every minute. When a worker runs it, the queue is provably alive. */
class HeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(HealthService $health): void
    {
        $health->recordHeartbeat('queue');
    }
}
