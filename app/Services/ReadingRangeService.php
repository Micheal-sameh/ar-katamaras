<?php

namespace App\Services;

use App\DTOs\DailyReadingDto;
use App\Enums\Language;
use Carbon\CarbonImmutable;

/** Looks up several days that are already stored. It never fetches, so one call can never load the upstream API. */
class ReadingRangeService
{
    public function __construct(private readonly ReadingService $readings) {}

    /**
     * @return array{from: CarbonImmutable, days: list<DailyReadingDto>, missing: list<string>}
     */
    public function stored(CarbonImmutable $from, int $days, Language $language, int $bibleId = 0): array
    {
        $found = [];
        $missing = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->addDays($i);

            if ($this->readings->isStored($date, $language, $bibleId)) {
                $found[] = $this->readings->getForDate($date, $language, $bibleId);
            } else {
                $missing[] = $date->toDateString();
            }
        }

        return ['from' => $from, 'days' => $found, 'missing' => $missing];
    }
}
