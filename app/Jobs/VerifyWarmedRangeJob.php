<?php

namespace App\Jobs;

use App\Enums\Language;
use App\Mail\ReadingsWarmFailed;
use App\Services\ReadingCacheKey;
use App\Services\ReadingStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Runs after the retries of a monthly warm-up are used up. Checks that every day in the range is
 * stored for every language and sends ONE email if not (not one per failed day).
 */
class VerifyWarmedRangeJob implements ShouldQueue
{
    use Queueable;

    /** @param list<int> $languageIds */
    public function __construct(public string $from, public string $to, public array $languageIds, public string $jobLabel = 'Monthly pre-cache') {}

    public function handle(ReadingStore $store): void
    {
        $timezone = config('readings.timezone');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->from, $timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->to, $timezone);

        $missing = [];
        foreach ($this->languageIds as $id) {
            $language = Language::from($id);
            for ($date = $from; $date <= $to; $date = $date->addDay()) {
                if (! $store->has($date, $language)) {
                    $missing[] = $language->code().':'.$date->toDateString();
                }
            }
        }

        if ($missing === []) {
            Log::info('Readings: pre-cache verified', ['job' => $this->jobLabel, 'from' => $this->from, 'to' => $this->to]);

            return;
        }

        $errors = Cache::store(config('readings.cache.store'))->get(ReadingCacheKey::warmErrors(), []);
        Log::critical('Readings: pre-cache incomplete', ['job' => $this->jobLabel, 'missing' => count($missing), 'from' => $this->from, 'to' => $this->to]);

        Mail::to(config('readings.alerts.email'))->send(new ReadingsWarmFailed(
            'some days could not be stored',
            "{$this->from} to {$this->to}",
            $missing,
            $errors,
            $this->jobLabel,
        ));
    }
}
