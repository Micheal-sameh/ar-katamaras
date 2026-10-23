<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesLanguageOption;
use App\Services\CopticCalendarService;
use App\Services\ReadingWarmer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RefreshReadings extends Command
{
    use ParsesLanguageOption;

    protected $signature = 'readings:refresh
        {--days= : Days from today to refetch (default from config)}
        {--from= : First date, YYYY-MM-DD (instead of --days)}
        {--to= : Last date, YYYY-MM-DD (default: same as --from)}
        {--lang=* : Language codes (ar, en, fr); all configured languages when omitted}';

    protected $description = 'Fetch days again from the Katameros API and replace the stored copy (picks up upstream corrections)';

    public function handle(ReadingWarmer $warmer, CopticCalendarService $calendar): int
    {
        $languages = $this->languages();
        if ($languages === null) {
            return self::INVALID;
        }

        $timezone = config('readings.timezone');
        if ($this->option('from')) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->option('from'), $timezone);
            $to = $this->option('to') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('to'), $timezone) : $from;
        } else {
            $from = $calendar->today();
            $to = $from->addDays(max(0, (int) ($this->option('days') ?: config('readings.warm.days')) - 1));
        }

        $result = $warmer->refreshRange($from, $to, $languages);

        $this->info(sprintf('%s to %s: refreshed %d, failed %d.', $from->toDateString(), $to->toDateString(), $result['refreshed'], count($result['failed'])));
        foreach ($result['failed'] as $failed) {
            $this->warn("  failed: {$failed} (the stored copy was kept)");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
