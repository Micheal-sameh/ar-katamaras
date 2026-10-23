<?php

namespace App\Jobs;

use App\Enums\Language;
use App\Services\ReadingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retries one failed day. Dispatched with a 1 minute delay; the next attempts follow after
 * 5 and 15 minutes (config readings.warm.retry_delays).
 */
class RetryWarmDayJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $date, public int $languageId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return array_slice(config('readings.warm.retry_delays'), 1);
    }

    public function handle(ReadingService $readings): void
    {
        $language = Language::from($this->languageId);

        if ($readings->isStored($this->date, $language)) {
            return;
        }

        // Throws ReadingsUnavailableException while the API is still down; the queue retries after the backoff.
        $readings->getForDate($this->date, $language);
    }

    public function failed(Throwable $e): void
    {
        Log::critical('Readings: warm retry gave up', ['date' => $this->date, 'language' => $this->languageId, 'error' => $e->getMessage()]);
    }
}
