<?php

namespace App\Services;

use App\Enums\Language;
use App\Enums\ReadingSource;
use App\Exceptions\ReadingsUnavailableException;
use App\Jobs\RetryWarmDayJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/** Pre-fetches upcoming days so the app keeps working through internet outages. */
class ReadingWarmer
{
    public function __construct(
        private readonly ReadingService $readings,
        private readonly ReadingStore $store,
        private readonly CopticCalendarService $calendar,
    ) {}

    /**
     * Fetches missing days from today for $days days. Stored days are skipped (no API call),
     * a failing day never stops the others, and failures are queued for retry.
     *
     * @param  list<Language>  $languages
     * @return array{stored: int, skipped: int, failed: list<string>}
     */
    public function warm(int $days, array $languages, ?callable $sleep = null): array
    {
        $sleep ??= fn (int $seconds) => $seconds > 0 ? sleep($seconds) : null;
        $result = ['stored' => 0, 'skipped' => 0, 'failed' => []];
        $today = $this->calendar->today();
        $pause = (int) config('readings.warm.sleep_seconds');

        foreach ($languages as $language) {
            for ($i = 0; $i < $days; $i++) {
                $date = $today->addDays($i);

                if ($this->readings->isStored($date, $language)) {
                    $result['skipped']++;

                    continue;
                }

                try {
                    $reading = $this->readings->getForDate($date, $language);
                    $result['stored']++;
                    if ($reading->source === ReadingSource::Api) {
                        $sleep($pause); // be polite to the API
                    }
                } catch (ReadingsUnavailableException $e) {
                    $result['failed'][] = $this->label($date, $language);
                    RetryWarmDayJob::dispatch($date->toDateString(), $language->value)
                        ->delay(now()->addSeconds(config('readings.warm.retry_delays')[0]));
                    $sleep($pause);
                }
            }
        }

        return $result;
    }

    /** Logs how many consecutive days are stored ahead; alerts when it drops below the threshold. */
    public function checkAhead(array $languages): array
    {
        $today = $this->calendar->today();
        $threshold = (int) config('readings.warm.alert_below_days');
        $ahead = [];

        foreach ($languages as $language) {
            $count = $this->store->consecutiveDaysFrom($today, $language);
            $ahead[$language->code()] = $count;

            if ($count < $threshold) {
                Log::critical('Readings: fewer than the minimum number of days stored ahead', [
                    'language' => $language->code(), 'days_ahead' => $count, 'minimum' => $threshold,
                ]);
            } else {
                Log::info('Readings: days stored ahead', ['language' => $language->code(), 'days_ahead' => $count]);
            }
        }

        return $ahead;
    }

    /** True when every language has at least $days consecutive days stored from today. */
    public function isComplete(int $days, array $languages): bool
    {
        $today = $this->calendar->today();
        foreach ($languages as $language) {
            if ($this->store->consecutiveDaysFrom($today, $language) < $days) {
                return false;
            }
        }

        return true;
    }

    private function label(CarbonImmutable $date, Language $language): string
    {
        return $language->code().':'.$date->toDateString();
    }
}
