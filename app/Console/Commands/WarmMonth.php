<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesLanguageOption;
use App\Services\ReadingWarmer;
use Illuminate\Console\Command;
use Throwable;

class WarmMonth extends Command
{
    use ParsesLanguageOption;

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
            $warmer->alertCrash($e, $range, 'Monthly pre-cache');
            $this->error('Monthly warm-up crashed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s: stored %d, skipped %d (already stored), failed %d.', $range, $result['stored'], $result['skipped'], count($result['failed'])));

        if ($result['failed'] !== []) {
            // Failed days are already queued for retry (1, 5, 15 minutes). Once those are used up,
            // one verification job checks the whole range and sends a single email if days are missing.
            $warmer->followUpFailures($result, $window['from'], $window['to'], $languages, 'Monthly pre-cache');
            $this->warn(count($result['failed']).' day(s) failed; retrying, and you will be emailed if any are still missing.');
        }

        return self::SUCCESS;
    }
}
