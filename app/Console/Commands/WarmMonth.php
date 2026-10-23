<?php

namespace App\Console\Commands;

use App\Enums\Language;
use App\Jobs\VerifyWarmedRangeJob;
use App\Mail\ReadingsWarmFailed;
use App\Services\ReadingCacheKey;
use App\Services\ReadingWarmer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class WarmMonth extends Command
{
    protected $signature = 'readings:warm-month
        {--months= : Months ahead to cover after the current one (default from config; 1 = the whole next month)}
        {--lang=* : Language codes (ar, en, fr); all configured languages when omitted}';

    protected $description = 'Store the rest of this month and the whole next month (scheduled for the 25th); emails on failure';

    public function handle(ReadingWarmer $warmer): int
    {
        $languages = $this->languages();
        if ($languages === null) {
            return self::INVALID;
        }

        $months = (int) ($this->option('months') ?? config('readings.warm.monthly.months_ahead'));
        $window = $warmer->monthWindow($months);
        $range = $window['from']->toDateString().' to '.$window['to']->toDateString();

        try {
            $result = $warmer->warmRange($window['from'], $window['to'], $languages);
        } catch (Throwable $e) {
            // Something unexpected (not just an unreachable API): tell the owner right away.
            report($e);
            $this->sendAlert(new ReadingsWarmFailed('the job crashed', $range, [], [get_class($e).': '.$e->getMessage()]));
            $this->error('Monthly warm-up crashed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s: stored %d, skipped %d (already stored), failed %d.', $range, $result['stored'], $result['skipped'], count($result['failed'])));

        if ($result['failed'] !== []) {
            // Failed days are already queued for retry (1, 5, 15 minutes). Once those are used up,
            // one verification job checks the whole range and sends a single email if days are missing.
            Cache::store(config('readings.cache.store'))->put(ReadingCacheKey::warmErrors(), array_slice($result['errors'], 0, 10), now()->addDays(2));
            VerifyWarmedRangeJob::dispatch(
                $window['from']->toDateString(),
                $window['to']->toDateString(),
                array_map(fn (Language $l) => $l->value, $languages),
            )->delay(now()->addSeconds(array_sum(config('readings.warm.retry_delays')) + 120));

            $this->warn(count($result['failed']).' day(s) failed; retrying, and you will be emailed if any are still missing.');
        }

        return self::SUCCESS;
    }

    private function sendAlert(ReadingsWarmFailed $mail): void
    {
        try {
            Mail::to(config('readings.alerts.email'))->send($mail);
        } catch (Throwable $e) {
            Log::critical('Readings: could not send the failure email', ['error' => $e->getMessage()]);
        }
    }

    /** @return list<Language>|null */
    private function languages(): ?array
    {
        $codes = array_filter((array) $this->option('lang'), fn ($c) => $c !== '*' && $c !== '');
        if ($codes === []) {
            return config('readings.languages');
        }

        $languages = [];
        foreach ($codes as $code) {
            $language = Language::fromCode($code);
            if ($language === null) {
                $this->error("Unknown language '{$code}'. Use: ".implode(', ', Language::codes()));

                return null;
            }
            $languages[] = $language;
        }

        return $languages;
    }
}
