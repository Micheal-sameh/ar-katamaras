<?php

namespace App\Services;

use App\Jobs\WarmYearJob;
use Illuminate\Support\Facades\Cache;

/**
 * Starts the whole-year warm-up when a visitor asks for a day the server does not have stored yet.
 * A cooldown keeps it to one run per period, so a burst of requests (or someone scripting dates) cannot
 * queue it again and again.
 */
class YearWarmTrigger
{
    public function requestIfNeeded(): bool
    {
        $cooldown = max(1, (int) config('readings.warm.on_demand_cooldown_minutes'));

        $first = Cache::store(config('readings.cache.store'))->add(ReadingCacheKey::yearWarmRequested(), now()->toIso8601String(), now()->addMinutes($cooldown));
        if (! $first) {
            return false;
        }

        WarmYearJob::dispatch();

        return true;
    }
}
