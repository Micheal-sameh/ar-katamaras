<?php

namespace App\Console\Commands;

use App\Enums\Language;
use App\Services\ReadingWarmer;
use Illuminate\Console\Command;

class WarmReadings extends Command
{
    protected $signature = 'readings:warm
        {--days= : Number of days ahead to store (default from config)}
        {--lang=* : Language codes (ar, en, fr); all configured languages when omitted}';

    protected $description = 'Fetch and store upcoming days of readings so the app survives internet outages';

    public function handle(ReadingWarmer $warmer): int
    {
        $days = (int) ($this->option('days') ?: config('readings.warm.days'));
        $languages = $this->languages();
        if ($languages === null) {
            return self::INVALID;
        }

        $result = $warmer->warm($days, $languages);
        $ahead = $warmer->checkAhead($languages);

        $this->info(sprintf('Stored %d, skipped %d (already stored), failed %d.', $result['stored'], $result['skipped'], count($result['failed'])));
        foreach ($ahead as $code => $count) {
            $this->line("  {$code}: {$count} day(s) stored ahead");
        }
        foreach ($result['failed'] as $failed) {
            $this->warn("  failed: {$failed} (retry queued)");
        }

        return $warmer->isComplete($days, $languages) ? self::SUCCESS : self::FAILURE;
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
