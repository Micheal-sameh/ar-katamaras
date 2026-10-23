<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesLanguageOption;
use App\Services\CopticCalendarService;
use App\Services\ReadingWarmer;
use Illuminate\Console\Command;
use Throwable;

class WarmReadings extends Command
{
    use ParsesLanguageOption;

    protected $signature = 'readings:warm
        {--days= : Number of days ahead to store (default from config)}
        {--lang=* : Language codes (ar, en, fr); all configured languages when omitted}';

    protected $description = 'Fetch and store upcoming days of readings so the app survives internet outages; emails on failure';

    public function handle(ReadingWarmer $warmer, CopticCalendarService $calendar): int
    {
        $days = (int) ($this->option('days') ?: config('readings.warm.days'));
        $languages = $this->languages();
        if ($languages === null) {
            return self::INVALID;
        }

        $from = $calendar->today();
        $to = $from->addDays(max(0, $days - 1));

        try {
            $result = $warmer->warmRange($from, $to, $languages);
        } catch (Throwable $e) {
            $warmer->alertCrash($e, $from->toDateString().' to '.$to->toDateString(), 'Weekly pre-cache');
            $this->error('Warm-up crashed: '.$e->getMessage());

            return self::FAILURE;
        }

        $ahead = $warmer->checkAhead($languages);
        $warmer->followUpFailures($result, $from, $to, $languages, 'Weekly pre-cache');

        $this->info(sprintf('Stored %d, skipped %d (already stored), failed %d.', $result['stored'], $result['skipped'], count($result['failed'])));
        foreach ($ahead as $code => $count) {
            $this->line("  {$code}: {$count} day(s) stored ahead");
        }
        foreach ($result['failed'] as $failed) {
            $this->warn("  failed: {$failed} (retry queued)");
        }

        return $warmer->isComplete($days, $languages) ? self::SUCCESS : self::FAILURE;
    }
}
