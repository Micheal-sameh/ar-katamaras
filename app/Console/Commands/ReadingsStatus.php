<?php

namespace App\Console\Commands;

use App\Services\CopticCalendarService;
use App\Services\ReadingStore;
use Illuminate\Console\Command;

class ReadingsStatus extends Command
{
    protected $signature = 'readings:status';

    protected $description = 'Show the stored date range per language';

    public function handle(ReadingStore $store, CopticCalendarService $calendar): int
    {
        $today = $calendar->today();
        $rows = [];

        foreach (config('readings.languages') as $language) {
            $range = $store->range($language);
            $rows[] = [
                $language->code(),
                $range['first'] ?? '-',
                $range['last'] ?? '-',
                $range['count'],
                $store->consecutiveDaysFrom($today, $language),
            ];
        }

        $this->table(['Language', 'First stored', 'Last stored', 'Days', 'Consecutive from today'], $rows);

        return self::SUCCESS;
    }
}
