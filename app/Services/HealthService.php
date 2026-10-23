<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Is the whole machine working: database, cache, scheduler, queue worker and enough days stored ahead? */
class HealthService
{
    public function __construct(private readonly ReadingStore $store, private readonly CopticCalendarService $calendar) {}

    /** The scheduler and the queue worker call this every minute (see routes/console.php). */
    public function recordHeartbeat(string $component): void
    {
        $this->cache()->put(ReadingCacheKey::heartbeat($component), now()->getTimestamp(), now()->addDay());
    }

    /**
     * @param  list<string>|null  $only  limit to some of: database, cache, scheduler, queue, data
     * @return array{status: string, checks: array<string, array{ok: bool, detail: string}>}
     */
    public function check(?array $only = null): array
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cacheWorks(),
            'scheduler' => $this->heartbeat('scheduler', (int) config('readings.health.scheduler_max_age')),
            'queue' => $this->heartbeat('queue', (int) config('readings.health.queue_max_age')),
            'data' => $this->daysAhead(),
        ];

        if ($only !== null) {
            $checks = array_intersect_key($checks, array_flip($only));
        }

        $ok = collect($checks)->every(fn (array $c) => $c['ok']);

        return ['status' => $ok ? 'ok' : 'failing', 'checks' => $checks];
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'detail' => 'reachable'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'database unreachable'];
        }
    }

    private function cacheWorks(): array
    {
        try {
            $key = 'readings:health:probe';
            $this->cache()->put($key, '1', 30);

            return $this->cache()->get($key) === '1'
                ? ['ok' => true, 'detail' => 'working']
                : ['ok' => false, 'detail' => 'cache did not return the value written'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'cache unreachable'];
        }
    }

    private function heartbeat(string $component, int $maxAge): array
    {
        try {
            $last = $this->cache()->get(ReadingCacheKey::heartbeat($component));
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'cache unreachable'];
        }

        if ($last === null) {
            return ['ok' => false, 'detail' => "no {$component} heartbeat yet (is it running?)"];
        }

        $age = now()->getTimestamp() - (int) $last;

        return ['ok' => $age <= $maxAge, 'detail' => "last seen {$age}s ago (limit {$maxAge}s)"];
    }

    private function daysAhead(): array
    {
        $minimum = (int) config('readings.warm.alert_below_days');
        $today = $this->calendar->today();
        $parts = [];
        $ok = true;

        try {
            foreach (config('readings.languages') as $language) {
                $days = $this->store->consecutiveDaysFrom($today, $language);
                $parts[] = $language->code().': '.$days;
                $ok = $ok && $days >= $minimum;
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'could not count stored days'];
        }

        return ['ok' => $ok, 'detail' => 'days stored ahead ('.implode(', ', $parts).'), minimum '.$minimum];
    }

    private function cache()
    {
        return Cache::store(config('readings.cache.store'));
    }
}
